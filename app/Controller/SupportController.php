<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\InternalMailService;
use MonkeysLegion\Http\Message\JsonResponse;
use MonkeysLegion\Repository\RepositoryFactory;
use MonkeysLegion\Router\Attributes\Route;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class SupportController
{
    public function __construct(
        private RepositoryFactory  $repos,
        private InternalMailService $internalMail,
        private ?LoggerInterface    $logger = null,
    ) {}

    /**
     * POST /support
     *
     * Accepts:
     *  - subject: string (required)
     *  - description: string (required)
     *  - attachments[]: file(s) (optional; multipart/form-data)
     *
     * Notes:
     *  - Requires authenticated user (uses request attribute 'user_id')
     *  - Enforces basic size limits (10 MB per file, 20 MB total)
     */
    #[Route(methods: 'POST', path: '/support')]
    public function send(ServerRequestInterface $request): JsonResponse
    {
        // 1) Auth
        $userId = (int) $request->getAttribute('user_id', 0);
        if ($userId <= 0) {
            throw new RuntimeException('Unauthorized', 401);
        }

        $userRepo = $this->repos->getRepository(User::class);
        /** @var ?User $user */
        $user = $userRepo->find($userId);
        if (! $user) {
            throw new RuntimeException('User not found', 404);
        }

        // 2) Parse body (JSON or multipart)
        $contentType = $request->getHeaderLine('Content-Type');
        $body = [];
        if (str_contains($contentType, 'application/json')) {
            $raw = (string) $request->getBody();
            $body = $raw !== '' ? json_decode($raw, true, flags: JSON_THROW_ON_ERROR) : [];
        } else {
            $body = $request->getParsedBody() ?: [];
        }

        $subject     = trim((string)($body['subject'] ?? ''));
        $description = trim((string)($body['description'] ?? ''));

        if ($subject === '' || $description === '') {
            throw new RuntimeException('subject and description are required', 400);
        }

        // 3) Collect attachments (optional; multipart/form-data)
        $attachments = [];
        $files = $request->getUploadedFiles();
        $maybe = $files['attachments'] ?? null;

        // Normalize to array of UploadedFileInterface
        if ($maybe !== null) {
            $list = is_array($maybe) ? $maybe : [$maybe];

            $totalBytes = 0;
            foreach ($list as $idx => $file) {
                if ($file->getError() !== UPLOAD_ERR_OK) {
                    $this->logger?->warning('Skipping failed upload', ['index' => $idx, 'err' => $file->getError()]);
                    continue;
                }

                $size = $file->getSize() ?? 0;
                if ($size > 10 * 1024 * 1024) { // 10 MB per file
                    throw new RuntimeException('Each attachment must be ≤ 10 MB', 400);
                }
                $totalBytes += $size;
                if ($totalBytes > 20 * 1024 * 1024) { // 20 MB total
                    throw new RuntimeException('Total attachments size must be ≤ 20 MB', 400);
                }

                $stream = $file->getStream();
                $content = $stream->getContents(); // raw bytes
                $attachments[] = [
                    'filename'     => $file->getClientFilename() ?: ('attachment-' . ($idx + 1)),
                    'content'      => $content, // raw bytes; MailSender should handle/base64 if needed
                    'content_type' => $file->getClientMediaType() ?: 'application/octet-stream',
                ];
            }
        }

        // 4) Build a lightweight HTML body (you can also move this to a view)
        $brand      = htmlspecialchars((string)($_ENV['BRAND_NAME'] ?? 'MonkeysMail'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeDesc   = nl2br(htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $userAgent  = htmlspecialchars($request->getHeaderLine('User-Agent'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $ip         = htmlspecialchars($request->getServerParams()['REMOTE_ADDR'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $fromName   = $user->getFullName() ?: $user->getEmail();
        $fromEmail  = $user->getEmail();
        $userIdStr  = (string) $user->getId();
        $safeSubj   = $this->e($subject);            // escape BEFORE heredoc
        $safeFrom   = htmlspecialchars($fromName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeEmail  = htmlspecialchars($fromEmail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $html = <<<HTML
<h2 style="margin:0 0 8px 0;font:600 18px system-ui">{$brand} — Support Request</h2>
<p><strong>From:</strong> {$safeFrom} &lt;{$safeEmail}&gt;</p>
<p><strong>User ID:</strong> {$userIdStr}</p>
<p><strong>Subject:</strong> {$safeSubj}</p>
<hr style="border:none;border-top:1px solid #eee;margin:12px 0">
<p style="white-space:pre-wrap;line-height:1.5">{$safeDesc}</p>
<hr style="border:none;border-top:1px solid #eee;margin:12px 0">
<p style="color:#6b7280;font:14px system-ui"><strong>Client:</strong> {$userAgent}<br><strong>IP:</strong> {$ip}</p>
HTML;

        // Plain text fallback
        $text = "Support Request\n"
            . "From: " . ($user->getFullName() ?? $user->getEmail()) . " <{$user->getEmail()}>\n"
            . "User ID: {$user->getId()}\n"
            . "Subject: {$subject}\n"
            . "-----------------------------\n"
            . $description . "\n"
            . "-----------------------------\n"
            . "Client: {$request->getHeaderLine('User-Agent')}\n"
            . "IP: " . ($request->getServerParams()['REMOTE_ADDR'] ?? '');

        // 5) Send via InternalMailService (goes to SUPPORT_TO or fallback)
        $this->internalMail->supportRequest(
            fromEmail: $user->getEmail(),
            fromName:  $user->getFullName() ?? '',
            subject:   $subject,
            html:      $html,
            text:      $text,
            attachments: $attachments,
            headers:   ['X-Category' => 'system-support']
        );

        return new JsonResponse(['status' => 'ok'], 202);
    }

    // Minimal escaper for inline HTML fields (already using htmlspecialchars above for $description)
    private function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
