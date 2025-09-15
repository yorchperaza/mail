<?php
declare(strict_types=1);

namespace App\Service;

use ZBateson\MailMimeParser\MailMimeParser;
use ZBateson\MailMimeParser\IMessage;
use Psr\Http\Message\StreamInterface;
use ZipArchive;

final class TlsRptEmailParser
{
    /**
     * @param string $rawMime Full RFC822 message
     * @return array<int,array{json:array, receivedAt:string}>
     */
    public function parse(string $rawMime): array
    {
        $parser  = new MailMimeParser();
        /** @var IMessage $message */
        $message = $parser->parse($rawMime, true);

        $out        = [];
        $receivedAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->format(\DateTimeInterface::ATOM);

        // Inline JSON (some reporters send JSON in the body)
        $inline = trim((string)($message->getTextContent() ?? $message->getHtmlContent() ?? ''));
        if ($inline !== '' && ($json = $this->maybeJson($inline))) {
            $out[] = ['json' => $json, 'receivedAt' => $receivedAt];
        }

        // Attachments (.json / .gz / .zip)
        foreach ($message->getAllAttachmentParts() as $att) {
            $filename = strtolower((string)($att->getFilename() ?? ''));
            $ctypeRaw = strtolower((string)($att->getContentType() ?? ''));
            $ctype    = trim(explode(';', $ctypeRaw, 2)[0]); // strip params like charset=...

            // Prefer decoded string content
            $bytes = '';
            if (method_exists($att, 'getContent')) {
                $bytes = (string) ($att->getContent() ?? '');
            }
            if ($bytes === '' && method_exists($att, 'getContentStream')) {
                $stream = $att->getContentStream();
                if ($stream instanceof StreamInterface) {
                    $bytes = (string) $stream->getContents();
                } elseif (is_resource($stream)) {
                    $bytes = (string) (stream_get_contents($stream) ?: '');
                    @fclose($stream);
                }
            }
            if ($bytes === '') {
                continue;
            }

            // application/json / application/tlsrpt+json
            if ($ctype === 'application/json' || $ctype === 'application/tlsrpt+json') {
                if ($json = $this->maybeJson($bytes)) {
                    $out[] = ['json' => $json, 'receivedAt' => $receivedAt];
                }
                continue;
            }

            // .gz or gzip content type
            if ($ctype === 'application/gzip' || $ctype === 'application/x-gzip' || str_ends_with($filename, '.gz')) {
                if ($json = $this->fromGzip($bytes)) {
                    $out[] = ['json' => $json, 'receivedAt' => $receivedAt];
                }
                continue;
            }

            // .zip or application/zip
            if ($ctype === 'application/zip' || str_ends_with($filename, '.zip')) {
                foreach ($this->fromZip($bytes) as $j) {
                    $out[] = ['json' => $j, 'receivedAt' => $receivedAt];
                }
                continue;
            }
        }

        return $out;
    }

    private function maybeJson(string $s): ?array
    {
        $s = trim($s);
        if ($s === '') return null;
        $j = json_decode($s, true);
        return is_array($j) ? $j : null;
        // Consider json_last_error() if you want detailed errors
    }

    private function fromGzip(string $bytes): ?array
    {
        $un = @gzdecode($bytes);
        if ($un === false) return null;
        return $this->maybeJson($un);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fromZip(string $bytes): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'tlsrpt_zip_');
        file_put_contents($tmp, $bytes);

        $out = [];
        $zip = new ZipArchive();
        if ($zip->open($tmp) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (!preg_match('~\.json(\.gz)?$~i', $name)) continue;

                $stream = $zip->getStream($name);
                if ($stream === false) continue;
                $content = stream_get_contents($stream) ?: '';
                fclose($stream);

                if (preg_match('~\.json\.gz$~i', $name)) {
                    if ($json = $this->fromGzip($content)) $out[] = $json;
                } else {
                    if ($json = $this->maybeJson($content)) $out[] = $json;
                }
            }
            $zip->close();
        }
        @unlink($tmp);
        return $out;
    }
}
