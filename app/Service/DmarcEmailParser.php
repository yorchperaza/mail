<?php
declare(strict_types=1);

namespace App\Service;

use ZBateson\MailMimeParser\MailMimeParser;
use ZBateson\MailMimeParser\IMessage;
use Psr\Http\Message\StreamInterface;
use ZipArchive;

final class DmarcEmailParser
{
    /**
     * Parse a raw RFC822 email and return 0..N DMARC reports.
     * Each item: ['xml'=>string, 'json'=>array, 'receivedAt'=>ISO-8601]
     */
    public function parse(string $rawMime): array
    {
        $out        = [];
        $receivedAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->format(\DateTimeInterface::ATOM);

        /* ---------------------------------------------------------
         * 0) RAW fallback: handle plain text posts with inline XML
         *    (no proper MIME, just a body containing <feedback>…</feedback>)
         * --------------------------------------------------------- */
        $directXmls = $this->extractXmlFromRaw($rawMime);
        if (!empty($directXmls)) {
            foreach ($directXmls as $xml) {
                if ($this->looksLikeXml($xml)) {
                    $json = $this->xmlToArray($xml);
                    if ($json) {
                        $out[] = ['xml' => $xml, 'json' => $json, 'receivedAt' => $receivedAt];
                    }
                }
            }
            if (!empty($out)) {
                return $out; // already parsed from raw body
            }
        }

        /* ---------------------------------------------------------
         * 1) MIME parse (attachments, gzip/zip, etc.)
         * --------------------------------------------------------- */
        $message = null;
        try {
            $parser  = new MailMimeParser();
            /** @var IMessage $message */
            $message = $parser->parse($rawMime, true);
        } catch (\Throwable) {
            // If MIME parsing blows up, we already tried raw fallback above.
            return $out; // [] or whatever was found via raw
        }

        // Some reporters (rare) embed XML in body
        $inline = trim((string)($message->getTextContent() ?? $message->getHtmlContent() ?? ''));
        if ($inline !== '' && $this->looksLikeXml($inline)) {
            $json = $this->xmlToArray($inline);
            if ($json) $out[] = ['xml' => $inline, 'json' => $json, 'receivedAt' => $receivedAt];
        }

        foreach ($message->getAllAttachmentParts() as $att) {
            $filename = strtolower((string)($att->getFilename() ?? ''));
            $ctypeRaw = strtolower((string)($att->getContentType() ?? ''));
            $ctype    = trim(explode(';', $ctypeRaw, 2)[0]);

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
            if ($bytes === '') continue;

            // ZIP
            if ($ctype === 'application/zip' || str_ends_with($filename, '.zip')) {
                foreach ($this->fromZip($bytes) as $xml) {
                    if ($this->looksLikeXml($xml)) {
                        $json = $this->xmlToArray($xml);
                        if ($json) $out[] = ['xml'=>$xml, 'json'=>$json, 'receivedAt'=>$receivedAt];
                    }
                }
                continue;
            }

            // GZIP
            if ($ctype === 'application/gzip' || $ctype === 'application/x-gzip' || str_ends_with($filename, '.gz')) {
                $xml = $this->fromGzip($bytes);
                if ($xml && $this->looksLikeXml($xml)) {
                    $json = $this->xmlToArray($xml);
                    if ($json) $out[] = ['xml'=>$xml, 'json'=>$json, 'receivedAt'=>$receivedAt];
                }
                continue;
            }

            // Plain XML
            if ($ctype === 'text/xml' || $ctype === 'application/xml' || str_ends_with($filename, '.xml')) {
                $xml = $bytes;
                if ($this->looksLikeXml($xml)) {
                    $json = $this->xmlToArray($xml);
                    if ($json) $out[] = ['xml'=>$xml, 'json'=>$json, 'receivedAt'=>$receivedAt];
                }
                continue;
            }
        }

        return $out;
    }

    private function looksLikeXml(string $s): bool
    {
        return str_starts_with(ltrim($s), '<');
    }

    public function fromGzip(string $bytes): ?string
    {
        $un = @gzdecode($bytes);
        return ($un === false) ? null : $un;
    }

    /** @return string[] XML files extracted */
    public function fromZip(string $bytes): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'dmarc_zip_');
        file_put_contents($tmp, $bytes);

        $out = [];
        $zip = new ZipArchive();
        if ($zip->open($tmp) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (!preg_match('~\.xml(\.gz)?$~i', $name)) continue;

                $stream = $zip->getStream($name);
                if ($stream === false) continue;
                $content = stream_get_contents($stream) ?: '';
                fclose($stream);

                if (preg_match('~\.xml\.gz$~i', $name)) {
                    $xml = $this->fromGzip($content);
                    if ($xml) $out[] = $xml;
                } else {
                    $out[] = $content;
                }
            }
            $zip->close();
        }
        @unlink($tmp);
        return $out;
    }

    /**
     * Convert DMARC aggregate XML into normalized JSON-ish array
     * (covers RFC 7489-style reports).
     */
    public function xmlToArray(string $xml): ?array
    {
        libxml_use_internal_errors(true);
        $sx = simplexml_load_string($xml);
        if ($sx === false) return null;
        $sx = json_decode(json_encode($sx), true) ?: [];

        // Walk standard structure
        $ri  = $sx['report_metadata'] ?? $sx['report-metadata'] ?? [];
        $pp  = $sx['policy_published'] ?? $sx['policy-published'] ?? [];
        $recs = $sx['record'] ?? [];
        if (isset($recs['row'])) $recs = [$recs]; // single -> list

        $org  = (string)($ri['org_name'] ?? $ri['org-name'] ?? '');
        $rid  = (string)($ri['report_id'] ?? $ri['report-id'] ?? '');
        $begin= (int)($ri['date_range']['begin'] ?? $ri['date-range']['begin'] ?? 0);
        $end  = (int)($ri['date_range']['end']   ?? $ri['date-range']['end']   ?? 0);

        $domain = (string)($pp['domain'] ?? '');
        $adkim  = (string)($pp['adkim'] ?? null);
        $aspf   = (string)($pp['aspf'] ?? null);
        $p      = (string)($pp['p'] ?? null);
        $sp     = (string)($pp['sp'] ?? null);
        $pct    = isset($pp['pct']) ? (int)$pp['pct'] : null;

        $rows = [];
        foreach ($recs as $r) {
            $row = $r['row'] ?? [];
            $ident = $r['identifiers'] ?? [];
            $polRes= $r['policy_evaluated'] ?? $row['policy_evaluated'] ?? [];

            // results
            $spfRes  = $polRes['spf']  ?? ($row['policy_evaluated']['spf']  ?? null);
            $dkimRes = $polRes['dkim'] ?? ($row['policy_evaluated']['dkim'] ?? null);
            $disp    = $polRes['disposition'] ?? ($row['policy_evaluated']['disposition'] ?? null);

            $rows[] = [
                'source_ip'   => (string)($row['source_ip'] ?? ''),
                'count'       => (int)($row['count'] ?? 0),
                'disposition' => $disp,
                'dkim'        => $dkimRes,
                'spf'         => $spfRes,
                'header_from' => (string)($ident['header_from'] ?? $ident['header-from'] ?? ''),
                'auth_results'=> $r['auth_results'] ?? $r['auth-results'] ?? null,
            ];
        }

        // ISO datetimes
        $startIso = $begin ? (new \DateTimeImmutable("@$begin"))->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM) : null;
        $endIso   = $end   ? (new \DateTimeImmutable("@$end"))  ->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM) : null;

        return [
            'org_name'     => $org,
            'report_id'    => $rid,
            'policy'       => [
                'domain' => $domain,
                'adkim'  => $adkim,
                'aspf'   => $aspf,
                'p'      => $p,
                'sp'     => $sp,
                'pct'    => $pct,
            ],
            'date_range'   => ['start' => $startIso, 'end' => $endIso, 'begin' => $begin, 'end_epoch' => $end],
            'rows'         => $rows,
            '_raw_xml_len' => strlen($xml),
        ];
    }

    /** Try to extract 1..N DMARC XML blocks directly from the raw MIME/body. */
    private function extractXmlFromRaw(string $raw): array
    {
        // Strip headers if present (everything before the first blank line)
        $parts = preg_split("/\R\R/", $raw, 2);
        $body  = $parts[1] ?? $raw;

        // Find all <feedback>...</feedback> blocks (DMARC aggregate)
        $out = [];
        if (preg_match_all('~(<\?xml[^>]*\?>\s*)?<feedback\b[\s\S]*?</feedback>~i', $body, $m)) {
            foreach ($m[0] as $xml) {
                $out[] = trim($xml);
            }
        }
        return $out;
    }
}
