<?php
/**
 * Tiny pure-PHP SMTP client + MIME builder. No Composer dependency.
 *
 * Speaks SMTP AUTH LOGIN over STARTTLS or implicit SSL. Sends multipart/mixed
 * messages with an alternative text/html body and any number of binary
 * attachments. Enough for our outbound invoice + variation email needs;
 * not a full RFC 5321/5322/2045 implementation, but it covers what cPanel
 * mail servers expect for authenticated client submissions.
 *
 * Usage:
 *   SmtpMailer::send([
 *       'to'          => 'client@example.com',
 *       'subject'     => 'Invoice CV-00123',
 *       'text'        => 'Plain text body...',
 *       'html'        => '<p>HTML body...</p>',          // optional
 *       'attachments' => [['name' => 'CV-123.pdf', 'mime' => 'application/pdf', 'data' => $pdfBytes]],
 *       'cc'          => ['erik@cadviz.co.nz'],          // optional
 *       'bcc'         => ['accounts@cadviz.co.nz'],      // optional
 *       'reply_to'    => 'accounts@cadviz.co.nz',        // optional
 *   ]);
 *
 * Throws SmtpException on any failure. Caller catches and surfaces.
 */

if (!defined('SMTP_HOST') && file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

class SmtpException extends \Exception {}

class SmtpMailer
{
    public static function isConfigured(): bool
    {
        return defined('SMTP_HOST') && SMTP_HOST !== ''
            && defined('SMTP_USER') && SMTP_USER !== ''
            && defined('SMTP_PASS') && SMTP_PASS !== ''
            && SMTP_PASS !== 'PUT-MAILBOX-PASSWORD-HERE';
    }

    public static function send(array $msg): void
    {
        if (!self::isConfigured()) {
            throw new SmtpException('SMTP_* constants are not configured. See config.smtp.sample.php.');
        }

        $to       = (array)($msg['to'] ?? []);
        if (!is_array($to)) $to = [$to];
        if (empty($to)) throw new SmtpException('No recipient.');
        $cc       = (array)($msg['cc']  ?? []);
        $bcc      = (array)($msg['bcc'] ?? []);
        $subject  = $msg['subject'] ?? '(no subject)';
        $text     = $msg['text']    ?? '';
        $html     = $msg['html']    ?? '';
        $atts     = $msg['attachments'] ?? [];
        $replyTo  = $msg['reply_to'] ?? SMTP_FROM_EMAIL;

        // ── Build the MIME message ────────────────────────────────────────
        $boundary    = 'cv_' . bin2hex(random_bytes(8));
        $altBoundary = 'cv_alt_' . bin2hex(random_bytes(8));
        $eol = "\r\n";

        $headers  = "From: " . self::encodeFrom() . $eol;
        $headers .= "To: " . implode(', ', array_map([self::class, 'encodeAddr'], $to)) . $eol;
        if (!empty($cc))  $headers .= "Cc: " . implode(', ', array_map([self::class, 'encodeAddr'], $cc)) . $eol;
        $headers .= "Reply-To: " . self::encodeAddr($replyTo) . $eol;
        $headers .= "Subject: " . self::encodeHeader($subject) . $eol;
        $headers .= "Date: " . date('r') . $eol;
        $headers .= "Message-ID: <" . bin2hex(random_bytes(8)) . "@" . self::heloHost() . ">" . $eol;
        $headers .= "MIME-Version: 1.0" . $eol;
        $headers .= "X-Mailer: CADViz/SMTP" . $eol;

        if (!empty($atts)) {
            $headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"" . $eol;
            $body  = "This is a multipart message in MIME format." . $eol . $eol;
            $body .= "--$boundary" . $eol;
            $body .= self::altPart($altBoundary, $text, $html);
            foreach ($atts as $a) {
                $body .= "--$boundary" . $eol;
                $body .= self::attachmentPart($a);
            }
            $body .= "--$boundary--" . $eol;
        } else {
            // No attachments — just the alternative part inline
            $headers .= "Content-Type: multipart/alternative; boundary=\"$altBoundary\"" . $eol;
            $body  = self::altInner($altBoundary, $text, $html);
        }

        // ── Talk to the SMTP server ──────────────────────────────────────
        $allRecipients = array_merge($to, $cc, $bcc);
        self::transmit($headers, $body, $allRecipients);
    }

    // ── MIME builders ─────────────────────────────────────────────────────

    private static function altPart(string $boundary, string $text, string $html): string
    {
        $eol = "\r\n";
        $out  = "Content-Type: multipart/alternative; boundary=\"$boundary\"" . $eol . $eol;
        $out .= self::altInner($boundary, $text, $html);
        return $out;
    }

    private static function altInner(string $boundary, string $text, string $html): string
    {
        $eol = "\r\n";
        $out  = "--$boundary" . $eol;
        $out .= "Content-Type: text/plain; charset=utf-8" . $eol;
        $out .= "Content-Transfer-Encoding: 8bit" . $eol . $eol;
        $out .= ($text !== '' ? $text : strip_tags($html)) . $eol . $eol;
        if ($html !== '') {
            $out .= "--$boundary" . $eol;
            $out .= "Content-Type: text/html; charset=utf-8" . $eol;
            $out .= "Content-Transfer-Encoding: 8bit" . $eol . $eol;
            $out .= $html . $eol . $eol;
        }
        $out .= "--$boundary--" . $eol . $eol;
        return $out;
    }

    private static function attachmentPart(array $a): string
    {
        $eol  = "\r\n";
        $name = $a['name'] ?? 'attachment.bin';
        $mime = $a['mime'] ?? 'application/octet-stream';
        $data = $a['data'] ?? '';
        $out  = "Content-Type: $mime; name=\"$name\"" . $eol;
        $out .= "Content-Transfer-Encoding: base64" . $eol;
        $out .= "Content-Disposition: attachment; filename=\"$name\"" . $eol . $eol;
        $out .= chunk_split(base64_encode($data)) . $eol;
        return $out;
    }

    private static function encodeFrom(): string
    {
        return self::encodeAddr(SMTP_FROM_EMAIL, defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : null);
    }

    private static function encodeAddr(string $email, ?string $name = null): string
    {
        if ($name) return '"' . self::encodeHeader($name) . '" <' . $email . '>';
        return $email;
    }

    private static function encodeHeader(string $s): string
    {
        if (preg_match('/[^\x20-\x7e]/', $s)) {
            return '=?UTF-8?B?' . base64_encode($s) . '?=';
        }
        return $s;
    }

    private static function heloHost(): string
    {
        return $_SERVER['SERVER_NAME'] ?? 'localhost.localdomain';
    }

    // ── SMTP transport ────────────────────────────────────────────────────

    private static function transmit(string $headers, string $body, array $recipients): void
    {
        $enc = strtolower(defined('SMTP_ENCRYPTION') ? SMTP_ENCRYPTION : 'tls');
        $host = SMTP_HOST;
        $port = (int)(defined('SMTP_PORT') ? SMTP_PORT : ($enc === 'ssl' ? 465 : 587));
        $connHost = ($enc === 'ssl') ? "ssl://$host" : $host;

        $errno = 0; $errstr = '';
        $sock = @stream_socket_client("$connHost:$port", $errno, $errstr, 15);
        if (!$sock) throw new SmtpException("SMTP connect $host:$port failed: $errstr");
        stream_set_timeout($sock, 20);

        $expect = function(int $code) use ($sock) {
            $line = ''; $resp = '';
            do {
                $line = fgets($sock, 4096);
                if ($line === false) throw new SmtpException("SMTP read failed");
                $resp .= $line;
            } while (isset($line[3]) && $line[3] === '-');
            if ((int)substr($resp, 0, 3) !== $code) {
                throw new SmtpException("SMTP expected $code, got: " . trim($resp));
            }
            return $resp;
        };
        $write = function(string $cmd) use ($sock) {
            fwrite($sock, $cmd . "\r\n");
        };

        $expect(220);
        $write('EHLO ' . self::heloHost());
        $expect(250);

        if ($enc === 'tls') {
            $write('STARTTLS');
            $expect(220);
            $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT
                    | (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT : 0)
                    | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
            if (!stream_socket_enable_crypto($sock, true, $crypto)) {
                throw new SmtpException('STARTTLS upgrade failed');
            }
            $write('EHLO ' . self::heloHost());
            $expect(250);
        }

        $write('AUTH LOGIN');
        $expect(334);
        $write(base64_encode(SMTP_USER));
        $expect(334);
        $write(base64_encode(SMTP_PASS));
        $expect(235);

        $write('MAIL FROM:<' . SMTP_FROM_EMAIL . '>');
        $expect(250);
        foreach ($recipients as $rcpt) {
            // Strip any "Name <addr>" wrapper to a bare address
            if (preg_match('/<([^>]+)>/', $rcpt, $m)) $rcpt = $m[1];
            $write('RCPT TO:<' . $rcpt . '>');
            $expect(250);
        }

        $write('DATA');
        $expect(354);
        // Per RFC 5321, lines starting with '.' must be doubled
        $payload = preg_replace('/^\./m', '..', $headers . "\r\n" . $body);
        fwrite($sock, $payload . "\r\n.\r\n");
        $expect(250);

        $write('QUIT');
        // Don't strictly require 221 (some servers race the close)
        fclose($sock);
    }
}
