<?php
declare(strict_types=1);

// Privater lokaler SMTP-Testserver, niemals Teil eines Release.
[$script, $directory, $mode] = $argv;
$context = stream_context_create(['ssl' => ['local_cert' => $directory . '/certificate.pem',
    'local_pk' => $directory . '/key.pem', 'verify_peer' => false]]);
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if ($server === false) { exit(2); }
file_put_contents($directory . '/port', substr(strrchr(stream_socket_get_name($server, false), ':'), 1));
$client = stream_socket_accept($server, 10);
if ($client === false) { exit(3); }
stream_set_timeout($client, 5);
$tls = static fn (): bool => @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER) === true;
if ($mode === 'ssl' && !$tls()) { exit(4); }
fwrite($client, "220 localhost test SMTP\r\n");
$authenticated = false;
for ($i = 0; $i < 30 && ($line = fgets($client)) !== false; $i++) {
    $command = strtoupper(strtok(trim($line), ' '));
    switch ($command) {
        case 'EHLO':
            fwrite($client, "250-localhost\r\n250-STARTTLS\r\n250 AUTH LOGIN\r\n");
            break;
        case 'STARTTLS':
            fwrite($client, "220 Start TLS\r\n");
            if (!$tls()) { exit(4); }
            break;
        case 'AUTH':
            fwrite($client, "334 VXNlcm5hbWU6\r\n");
            $username = base64_decode(trim((string) fgets($client)), true);
            fwrite($client, "334 UGFzc3dvcmQ6\r\n");
            $password = base64_decode(trim((string) fgets($client)), true);
            $authenticated = $username === 'local-test' && $password === 'test-only';
            fwrite($client, $authenticated ? "235 Authenticated\r\n" : "535 Authentication failed\r\n");
            break;
        case 'MAIL': case 'RCPT':
            fwrite($client, $authenticated ? "250 Accepted\r\n" : "530 Authenticate first\r\n");
            break;
        case 'DATA':
            fwrite($client, "354 End with dot\r\n");
            $message = '';
            while (($part = fgets($client)) !== false && $part !== ".\r\n") { $message .= $part; }
            file_put_contents($directory . '/message', $message);
            fwrite($client, "250 Queued\r\n");
            break;
        case 'QUIT':
            fwrite($client, "221 Bye\r\n");
            fclose($client);
            fclose($server);
            exit;
        default:
            fwrite($client, "250 OK\r\n");
    }
}
fclose($client);
fclose($server);
