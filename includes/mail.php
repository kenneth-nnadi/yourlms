<?php
declare(strict_types=1);

function mail_is_enabled(array $config): bool
{
    $smtp = $config['smtp'] ?? [];
    return !empty($smtp['enabled']) && !empty($smtp['host']);
}

function send_mail(array $config, string $to, string $subject, string $body): bool
{
    $smtp = $config['smtp'] ?? [];
    $from = $smtp['from'] ?? 'noreply@localhost';
    $fromName = $smtp['from_name'] ?? ($config['app_name'] ?? 'YourLMS');

    if (mail_is_enabled($config)) {
        return smtp_send($smtp, $from, $fromName, $to, $subject, $body);
    }

    $headers = [
        'From: ' . mail_format_address($fromName, $from),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
    ];
    return mail($to, $subject, $body, implode("\r\n", $headers));
}

function mail_format_address(string $name, string $email): string
{
    $safeName = str_replace(['"', "\r", "\n"], '', $name);
    return "\"{$safeName}\" <{$email}>";
}

function smtp_send(array $smtp, string $from, string $fromName, string $to, string $subject, string $body): bool
{
    $host = $smtp['host'];
    $port = (int) ($smtp['port'] ?? 587);
    $user = $smtp['user'] ?? '';
    $pass = $smtp['pass'] ?? '';
    $secure = strtolower((string) ($smtp['secure'] ?? 'tls'));

    $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host;
    $socket = @fsockopen($remote, $port, $errno, $errstr, 15);
    if (!$socket) {
        return false;
    }

    stream_set_timeout($socket, 15);

    if (!smtp_expect($socket, [220])) {
        fclose($socket);
        return false;
    }

    $ehloHost = $_SERVER['SERVER_NAME'] ?? 'localhost';
    smtp_cmd($socket, "EHLO {$ehloHost}");
    if (!smtp_expect($socket, [250])) {
        smtp_cmd($socket, "HELO {$ehloHost}");
        if (!smtp_expect($socket, [250])) {
            fclose($socket);
            return false;
        }
    }

    if ($secure === 'tls') {
        smtp_cmd($socket, 'STARTTLS');
        if (!smtp_expect($socket, [220])) {
            fclose($socket);
            return false;
        }
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket);
            return false;
        }
        smtp_cmd($socket, "EHLO {$ehloHost}");
        if (!smtp_expect($socket, [250])) {
            fclose($socket);
            return false;
        }
    }

    if ($user !== '') {
        smtp_cmd($socket, 'AUTH LOGIN');
        if (!smtp_expect($socket, [334])) {
            fclose($socket);
            return false;
        }
        smtp_cmd($socket, base64_encode($user));
        if (!smtp_expect($socket, [334])) {
            fclose($socket);
            return false;
        }
        smtp_cmd($socket, base64_encode($pass));
        if (!smtp_expect($socket, [235])) {
            fclose($socket);
            return false;
        }
    }

    smtp_cmd($socket, 'MAIL FROM:<' . $from . '>');
    if (!smtp_expect($socket, [250])) {
        fclose($socket);
        return false;
    }
    smtp_cmd($socket, 'RCPT TO:<' . $to . '>');
    if (!smtp_expect($socket, [250, 251])) {
        fclose($socket);
        return false;
    }
    smtp_cmd($socket, 'DATA');
    if (!smtp_expect($socket, [354])) {
        fclose($socket);
        return false;
    }

    $message = "From: " . mail_format_address($fromName, $from) . "\r\n";
    $message .= "To: <{$to}>\r\n";
    $message .= 'Subject: ' . smtp_encode_header($subject) . "\r\n";
    $message .= "MIME-Version: 1.0\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $message .= "\r\n";
    $message .= str_replace(["\r\n", "\r"], "\n", $body);
    $message = str_replace("\n.", "\n..", $message);
    $message = str_replace("\n", "\r\n", $message);
    fwrite($socket, $message . "\r\n.\r\n");
    if (!smtp_expect($socket, [250])) {
        fclose($socket);
        return false;
    }

    smtp_cmd($socket, 'QUIT');
    fclose($socket);
    return true;
}

function smtp_cmd($socket, string $cmd): void
{
    fwrite($socket, $cmd . "\r\n");
}

function smtp_expect($socket, array $codes): bool
{
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }
    $code = (int) substr($response, 0, 3);
    return in_array($code, $codes, true);
}

function smtp_encode_header(string $text): string
{
    if (preg_match('/[^\x20-\x7E]/', $text)) {
        return '=?UTF-8?B?' . base64_encode($text) . '?=';
    }
    return $text;
}