<?php

/**
 * A scripted one-connection HTTP server for StreamFetcherTest. Usage: php raw-http-server.php <options.json>
 *
 * Prints the port it listens on (127.0.0.1, random port), reads one request, writes it to options.transcript and
 * answers. Options: tls (serve TLS with cert and key), mode: "ok" (200 with Content-Length, then the connection is
 * held open), "drip" (headers one byte every 200 ms, never finished), "interim" (a 100 Continue before the 200),
 * "short" (Content-Length 100, two bytes, then close), "chunked" (a chunked body).
 */

declare(strict_types=1);

$options = json_decode((string) file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$tls = !empty($options['tls']);
$context = stream_context_create(['ssl' => [
    'local_cert' => $options['cert'] ?? '',
    'local_pk' => $options['key'] ?? '',
    'verify_peer' => false,
]]);
$server = stream_socket_server(($tls ? 'ssl' : 'tcp') . '://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if ($server === false) {
    fwrite(STDERR, "listen failed: {$error}\n");
    exit(1);
}
$name = (string) stream_socket_get_name($server, false);
echo substr($name, strrpos($name, ':') + 1), "\n";
fflush(STDOUT);

$client = @stream_socket_accept($server, 15);
if ($client === false) {
    exit(1);
}
$request = '';
while (!str_contains($request, "\r\n\r\n") && ($line = fgets($client)) !== false) {
    $request .= $line;
}
file_put_contents((string) $options['transcript'], $request);

$body = '{"ok":true}';
switch ($options['mode'] ?? 'ok') {
    case 'drip':
        foreach (str_split("HTTP/1.0 200 OK\r\nX-Slow: " . str_repeat('z', 200)) as $byte) {
            if (@fwrite($client, $byte) === false) {
                break;
            }
            usleep(200_000);
        }
        break;
    case 'interim':
        fwrite($client, "HTTP/1.1 100 Continue\r\n\r\nHTTP/1.0 200 OK\r\nContent-Type: application/json\r\n\r\n{$body}");
        break;
    case 'short':
        fwrite($client, "HTTP/1.0 200 OK\r\nContent-Length: 100\r\n\r\nab");
        break;
    case 'chunked':
        fwrite($client, "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nContent-Length: 3\r\n\r\n4;ext=1\r\n{\"ok\r\n7\r\n\":true}\r\n0\r\n\r\n");
        break;
    default:
        fwrite($client, "HTTP/1.0 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n\r\n{$body}");
        sleep(5); // a server that ignores Connection: close; Content-Length must end the read
}
fclose($client);
