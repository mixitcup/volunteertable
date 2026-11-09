<?php
declare(strict_types=1);

/**
 * Minimal SMTP client (no dependencies) for sending a plain-text UTF-8 mail.
 *
 * $smtp keys: host, port (default 587), secure ('tls' = STARTTLS (default),
 * 'ssl' = implicit TLS, 'none'), username, password, helo (optional).
 * Throws RuntimeException on any failure.
 */
function vt_smtp_send(
    array $smtp,
    string $fromEmail,
    string $fromName,
    string $toEmail,
    string $toName,
    string $subject,
    string $body
): void {
    $host = (string) ($smtp['host'] ?? '');
    $port = (int) ($smtp['port'] ?? 587);
    $secure = (string) ($smtp['secure'] ?? 'tls');
    $timeout = 15;
    if ($host === '') {
        throw new RuntimeException('SMTP host is not configured');
    }

    $context = stream_context_create(['ssl' => [
        'verify_peer' => true,
        'verify_peer_name' => true,
        'peer_name' => $host,
    ]]);
    $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $socket = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
    if ($socket === false) {
        throw new RuntimeException("SMTP connection to $remote failed: $errstr ($errno)");
    }
    stream_set_timeout($socket, $timeout);

    $expect = function (array $codes) use ($socket): string {
        $response = '';
        while (($line = fgets($socket, 1024)) !== false) {
            $response .= $line;
            // Multi-line replies have a '-' after the code on all but the last line.
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        if (!in_array((int) substr($response, 0, 3), $codes, true)) {
            throw new RuntimeException('Unexpected SMTP reply: ' . trim($response));
        }
        return $response;
    };
    $command = function (string $line, array $codes) use ($socket, $expect): string {
        if (fwrite($socket, $line . "\r\n") === false) {
            throw new RuntimeException('Cannot write to SMTP server');
        }
        return $expect($codes);
    };

    try {
        $helo = (string) ($smtp['helo'] ?? (gethostname() ?: 'localhost'));
        $expect([220]);
        $command('EHLO ' . $helo, [250]);

        if ($secure === 'tls') {
            $command('STARTTLS', [220]);
            $method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
                | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
            if (stream_socket_enable_crypto($socket, true, $method) !== true) {
                throw new RuntimeException('SMTP STARTTLS failed');
            }
            $command('EHLO ' . $helo, [250]);
        }

        if (!empty($smtp['username'])) {
            $command('AUTH LOGIN', [334]);
            $command(base64_encode((string) $smtp['username']), [334]);
            $command(base64_encode((string) ($smtp['password'] ?? '')), [235]);
        }

        $command('MAIL FROM:<' . vt_smtp_address($fromEmail) . '>', [250]);
        $command('RCPT TO:<' . vt_smtp_address($toEmail) . '>', [250, 251]);
        $command('DATA', [354]);

        $domain = substr(strrchr($fromEmail, '@') ?: '@localhost', 1);
        $headers = [
            'Date: ' . date('r'),
            'From: ' . vt_mime_header($fromName) . ' <' . vt_smtp_address($fromEmail) . '>',
            'To: ' . vt_mime_header($toName) . ' <' . vt_smtp_address($toEmail) . '>',
            'Subject: ' . vt_mime_header($subject),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        // Base64 lines never start with '.', so no dot-stuffing is needed.
        $encodedBody = rtrim(chunk_split(base64_encode(str_replace("\n", "\r\n", $body)), 76, "\r\n"));
        $command(implode("\r\n", $headers) . "\r\n\r\n" . $encodedBody . "\r\n.", [250]);

        try {
            $command('QUIT', [221]);
        } catch (RuntimeException $e) {
            // The mail was accepted; a failing QUIT does not matter.
        }
    } finally {
        fclose($socket);
    }
}

/** Encodes a header value as UTF-8 (RFC 2047); also prevents header injection. */
function vt_mime_header(string $value): string
{
    return '=?UTF-8?B?' . base64_encode(str_replace(["\r", "\n"], ' ', $value)) . '?=';
}

function vt_smtp_address(string $email): string
{
    if (preg_match('/[\r\n<>]/', $email) === 1) {
        throw new RuntimeException('Invalid email address');
    }
    return $email;
}
