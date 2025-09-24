<?php
declare(strict_types=1);

namespace App\Service;

use App\Service\Ports\MailSender;
use MonkeysLegion\Template\Renderer;
use Psr\Log\LoggerInterface;

/**
 * Internal/system emails (security & product): password reset, verification, welcome,
 * admin alerts, generic notifications. No quotas, no tracking, no queue.
 *
 * Uses MonkeysLegion views:
 *   HTML: resources/views/emails/{key}.ml.php            -> "emails.{key}"
 *   TEXT: resources/views/emails_text/{key}.ml.php       -> "emails_text.{key}" (optional)
 */
final class InternalMailService
{
    public function __construct(
        private MailSender $sender,
        private Renderer $renderer,
        private ?LoggerInterface $logger = null,
    ) {}

    /* -------------------- High-level convenience APIs -------------------- */

    /** Generic notification: pass subject + template key or inline HTML + variables */
    public function notify(string $to, string $subject, string $templateKeyOrHtml, array $vars = [], ?string $textOverride = null): void
    {
        [$html, $text] = $this->renderUsingViews($templateKeyOrHtml, $vars, $textOverride);
        $this->sendSystem($to, $subject, $html, $text, headers: ['X-Category' => 'system-notification']);
    }

    public function welcome(string $to, string $name = '', ?string $verifyUrl = null): void
    {
        $brand   = $_ENV['BRAND_NAME'] ?? 'MonkeysMail';
        $subject = "Welcome to {$brand}";
        $vars    = ['name' => $name ?: 'there', 'brand' => $brand, 'verifyUrl' => $verifyUrl];

        $this->notify($to, $subject, 'welcome', $vars);
    }

    public function emailVerification(string $to, string $name, string $verifyUrl, int $ttlSeconds = 3600): void
    {
        $brand   = $_ENV['BRAND_NAME'] ?? 'MonkeysMail';
        $subject = "{$brand} — Verify your email";
        $vars    = [
            'name'      => $name ?: 'there',
            'verifyUrl' => $verifyUrl,
            'ttlMin'    => (int)ceil($ttlSeconds / 60),
            'brand'     => $brand,
        ];

        [$html, $text] = $this->renderUsingViews('email_verification', $vars, null);
        $this->sendSystem($to, $subject, $html, $text, headers: ['X-Category' => 'system-verification']);
    }

    public function passwordReset(string $to, string $name, string $resetUrl, int $ttlSeconds = 3600): void
    {
        $brand   = $_ENV['BRAND_NAME'] ?? 'MonkeysMail';
        $subject = "{$brand} — Reset your password";
        $vars    = [
            'name'     => $name ?: 'there',
            'resetUrl' => $resetUrl,
            'ttlMin'   => (int)ceil($ttlSeconds / 60),
            'brand'    => $brand,
        ];

        // Render HTML template (resources/views/emails/password_reset.ml.php)
        try {
            $html = $this->renderer->render('emails.password_reset', $vars);
        } catch (\Throwable $e) {
            // Fallback inline HTML (standalone; no @extends or renderer)
            $this->logger?->warning('emails.password_reset template missing, using inline fallback', ['e' => $e]);
            $html = $this->inlineEmailHtml($vars);
        }

        // Try to render a plain-text template (resources/views/emails_text/password_reset.ml.php)
        try {
            $text = $this->renderer->render('emails_text.password_reset', $vars);
            $text = $this->normalizeText($text);
        } catch (\Throwable $e) {
            // Fallback: derive a decent text from HTML (preserve anchor URLs)
            $text = $this->fallbackText($html);
        }

        $this->sendSystem($to, $subject, $html, $text, ['X-Category' => 'system-password-reset']);
    }

    /**
     * @throws \Throwable
     */
    public function adminAlert(string $subject, string $html, ?string $text = null): void
    {
        $ops = $_ENV['INTERNAL_ALERT_TO'] ?? 'ops@monkeysmail.com';
        $this->sendSystem($ops, $subject, $html, $text, headers: ['X-Category' => 'system-admin-alert']);
    }

    /* -------------------- Core send (no tracking/quotas) -------------------- */

    /** Sends via platform transport. No quotas, no tracking. */
    private function sendSystem(string $to, string $subject, string $html, string $text, array $headers = []): void
    {
        $fromEmail = $_ENV['SYSTEM_FROM_EMAIL'] ?? 'no-reply@monkeysmail.com';
        $fromName  = $_ENV['SYSTEM_FROM_NAME']  ?? 'MonkeysMail';

        // Always include text
        $text = $this->normalizeText($text ?: $this->fallbackText($html));

        $payload = [
            'from_email' => $fromEmail,
            'from_name'  => $fromName,
            'reply_to'   => $fromEmail,
            'to'         => [$to],
            'subject'    => $subject,
            'html_body'  => $html,
            'text_body'  => $text,
            'headers'    => array_merge([
                'X-Product'  => 'monkeysmail',
                'X-Internal' => 'true',
            ], $headers),
        ];

        try {
            if (method_exists($this->sender, 'sendRaw')) {
                $this->sender->sendRaw($payload);
            } else {
                $this->sender->send($payload, ['to' => [$to]]);
            }
        } catch (\Throwable $e) {
            $this->logger?->error('Internal mail send failed', ['to' => $to, 'subject' => $subject, 'e' => $e]);
            throw $e;
        }
    }

    /* -------------------- View rendering helpers -------------------- */

    /**
     * Renders using Renderer or treats $keyOrHtml as literal HTML.
     * HTML view : resources/views/emails/{key}.ml.php        => "emails.{key}"
     * TEXT view : resources/views/emails_text/{key}.ml.php   => "emails_text.{key}" (optional)
     */
    private function renderUsingViews(string $keyOrHtml, array $vars, ?string $textOverride): array
    {
        // Inline HTML path (quick notifications)
        if (str_contains($keyOrHtml, '<') && str_contains($keyOrHtml, '>')) {
            // Just substitute and wrap in a minimal standalone email shell
            $html = $this->ensureStandaloneHtml($this->renderInlineString($keyOrHtml, $vars));
            $text = $textOverride ?: null;
            return [$html, $text];
        }

        // Determine view names
        [$htmlView, $textView] = $this->resolveViewNames($keyOrHtml);

        // Render HTML
        $html = $this->renderer->render($htmlView, $vars);

        // Render TEXT (optional)
        $text = null;
        if ($textOverride !== null) {
            $text = $textOverride;
        } else {
            try {
                $text = $this->renderer->render($textView, $vars);
                $text = $this->normalizeText($text);
            } catch (\Throwable) {
                $text = null; // will fallback later if needed
            }
        }

        return [$html, $text];
    }

    /** Accepts 'password_reset' or 'emails.password_reset'. Returns [htmlView, textView]. */
    private function resolveViewNames(string $key): array
    {
        if (str_contains($key, '.')) {
            $htmlView = $key;
            $textView = preg_replace('/^emails\b/', 'emails_text', $htmlView) ?? ('emails_text.' . $key);
            return [$htmlView, $textView];
        }
        return ['emails.' . $key, 'emails_text.' . $key];
    }

    private function renderInlineString(string $tmpl, array $vars): string
    {
        // Very small {{var}} replacement for inline usage
        return (string)preg_replace_callback('/\{\{\s*([a-zA-Z0-9_\.]+)\s*\}\}/', function ($m) use ($vars) {
            $key = $m[1];
            $val = $vars[$key] ?? '';
            return htmlspecialchars((string)$val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }, $tmpl);
    }

    /* -------------------- Inline/standalone HTML fallbacks -------------------- */

    /** Build a minimal standalone HTML email when the template is missing. */
    private function inlineEmailHtml(array $vars): string
    {
        $brand   = htmlspecialchars((string)($vars['brand']   ?? 'MonkeysMail'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $name    = htmlspecialchars((string)($vars['name']    ?? 'there'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $reset   = htmlspecialchars((string)($vars['resetUrl']?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $ttlMin  = (int)($vars['ttlMin'] ?? 60);

        return <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$brand} — Reset your password</title>
</head>
<body style="margin:0;padding:24px;background:#f7f8fa;font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#111;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;margin:0 auto;background:#fff;border-radius:12px;box-shadow:0 1px 4px rgba(0,0,0,.06);">
    <tr><td style="padding:24px 24px 8px;font-size:18px;font-weight:700;">{$brand}</td></tr>
    <tr><td style="padding:8px 24px 24px;line-height:1.5;">
      <p>Hello {$name},</p>
      <p>We received a request to reset your {$brand} password.</p>
      <p>
        <a href="{$reset}" style="display:inline-block;padding:10px 16px;background:#2563eb;color:#fff;text-decoration:none;border-radius:6px;">Reset Password</a>
      </p>
      <p>This link expires in {$ttlMin} minutes. If you didn’t request this, you can ignore this email.</p>
      <p>— {$brand} Security</p>
    </td></tr>
    <tr><td style="padding:12px 24px 24px;font-size:12px;color:#6b7280;">This is a service message from {$brand}.</td></tr>
  </table>
</body></html>
HTML;
    }

    /**
     * If caller passes a fragment (no <html>), wrap it into a minimal email HTML.
     * If it’s already a full HTML doc, return as-is.
     */
    private function ensureStandaloneHtml(string $maybeHtml): string
    {
        if (stripos($maybeHtml, '<html') !== false) {
            return $maybeHtml;
        }
        $brand = htmlspecialchars($_ENV['BRAND_NAME'] ?? 'MonkeysMail', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $inner = $maybeHtml;
        return <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$brand}</title></head>
<body style="margin:0;padding:24px;background:#f7f8fa;font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#111;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;margin:0 auto;background:#fff;border-radius:12px;box-shadow:0 1px 4px rgba(0,0,0,.06);">
    <tr><td style="padding:24px 24px 8px;font-size:18px;font-weight:700;">{$brand}</td></tr>
    <tr><td style="padding:8px 24px 24px;line-height:1.5;">{$inner}</td></tr>
  </table>
</body></html>
HTML;
    }

    /* -------------------- Text fallbacks -------------------- */

    /** Convert HTML to readable text and keep link URLs as "text (url)". */
    private function fallbackText(string $html): string
    {
        $text = preg_replace('#<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', '$2 ($1)', $html);
        $text = strip_tags($text ?? '');
        $text = html_entity_decode($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return $this->normalizeText($text);
    }

    /** Collapse whitespace and trim for nicer plain text. */
    private function normalizeText(string $text): string
    {
        $text = preg_replace('/\r\n|\r/', "\n", $text);
        $text = preg_replace("/[ \t]+/", ' ', $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        return trim((string)$text);
    }
}
