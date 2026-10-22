<?php

/**
 * A scripted one-connection SMTP server for SmtpMailerTest. Usage: php fake-smtp-server.php <options.json>
 *
 * Prints the port it listens on (127.0.0.1, random port), serves one connection and writes everything the client sent
 * to options.transcript. Options: ext (EHLO lines after the first), tls ("none", "starttls" or "ssl") with cert and
 * key, user and password (AUTH must match them), reply (a map of command verb to a canned reply line), silent (never
 * greet), hangup_on_quit.
 */

declare(strict_types=1);

$options = json_decode((string) file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$tls = $options['tls'] ?? 'none';
$context = stream_context_create(['ssl' => [
    'local_cert' => $options['cert'] ?? '',
    'local_pk' => $options['key'] ?? '',
    'verify_peer' => false,
]]);
$server = stream_socket_server(($tls === 'ssl' ? 'ssl' : 'tcp') . '://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if ($server === false) {
    fwrite(STDERR, "listen failed: {$error}\n");
    exit(1);
}
$name = (string) stream_socket_get_name($server, false);
echo substr($name, strrpos($name, ':') + 1), "\n";
fflush(STDOUT);

$client = @stream_socket_accept($server, 15);
if ($client === false) {
    fwrite(STDERR, 'accept failed: ' . (error_get_last()['message'] ?? '?') . "\n");
    exit(1);
}
$transcript = '';
$say = static function (string $line) use (&$client): void {
    fwrite($client, $line . "\r\n");
};
if (!empty($options['silent'])) {
    sleep(5);
    exit(0);
}
$say('220 fake.test ESMTP');
$secure = $tls === 'ssl';
$authStep = null;
$inData = false;
while (($line = fgets($client)) !== false) {
    $transcript .= $line;
    if ($inData) {
        if ($line === ".\r\n") {
            $inData = false;
            $say($options['reply']['MESSAGE'] ?? '250 queued');
        }
        continue;
    }
    $text = rtrim($line, "\r\n");
    $verb = strtoupper((string) strtok($text, ' :'));
    if ($authStep !== null) {
        if ($authStep === 'user') {
            $authStep = base64_decode($text) === ($options['user'] ?? null) ? 'password' : 'bad';
            $say('334 UGFzc3dvcmQ6');
            continue;
        }
        $ok = $authStep === 'password' && base64_decode($text) === ($options['password'] ?? null);
        $authStep = null;
        $say($ok ? '235 ok' : '535 bad credentials');
        continue;
    }
    if (isset($options['reply'][$verb])) {
        $say($options['reply'][$verb]);
        continue;
    }
    switch ($verb) {
        case 'EHLO':
            $ext = $options['ext'] ?? [];
            if ($tls === 'starttls' && !$secure) {
                $ext[] = 'STARTTLS';
            }
            $lines = ['fake.test', ...$ext];
            foreach ($lines as $i => $l) {
                $say('250' . ($i === count($lines) - 1 ? ' ' : '-') . $l);
            }
            break;
        case 'STARTTLS':
            $say('220 go ahead');
            stream_context_set_option($client, 'ssl', 'local_cert', $options['cert']);
            stream_context_set_option($client, 'ssl', 'local_pk', $options['key']);
            if (!stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) {
                exit(1);
            }
            $secure = true;
            $transcript .= "[tls]\r\n";
            break;
        case 'AUTH':
            $parts = explode(' ', $text);
            if (strtoupper($parts[1] ?? '') === 'PLAIN') {
                $expected = "\0" . ($options['user'] ?? '') . "\0" . ($options['password'] ?? '');
                $say(base64_decode($parts[2] ?? '') === $expected ? '235 ok' : '535 bad credentials');
            } else {
                $authStep = 'user';
                $say('334 VXNlcm5hbWU6');
            }
            break;
        case 'MAIL':
        case 'RCPT':
            $say('250 ok');
            break;
        case 'DATA':
            $inData = true;
            $say('354 go');
            break;
        case 'QUIT':
            if (empty($options['hangup_on_quit'])) {
                $say('221 bye');
            }
            break 2;
        default:
            $say('502 unknown');
    }
}
file_put_contents($options['transcript'], $transcript);
