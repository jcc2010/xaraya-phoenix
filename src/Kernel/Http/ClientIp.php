<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The client's IP address: REMOTE_ADDR. Only when REMOTE_ADDR is one of http.trusted_proxies is X-Forwarded-For
 * read, right to left, and the first address that is not a trusted proxy wins.
 */
final class ClientIp
{
    /** @var list<string> canonical addresses */
    private readonly array $trustedProxies;

    /** @param list<string> $trustedProxies addresses of reverse proxies, in any notation */
    public function __construct(array $trustedProxies = [])
    {
        $this->trustedProxies = array_map(self::canonical(...), $trustedProxies);
    }

    private static function canonical(string $ip): string
    {
        $packed = filter_var($ip, FILTER_VALIDATE_IP) === false ? false : inet_pton($ip);

        return $packed === false ? $ip : (string) inet_ntop($packed);
    }

    public function of(ServerRequestInterface $request): string
    {
        $remote = $request->getServerParams()['REMOTE_ADDR'] ?? '';
        $remote = is_string($remote) ? $remote : '';
        if (!in_array(self::canonical($remote), $this->trustedProxies, true)) {
            return $remote;
        }
        foreach (array_reverse(explode(',', $request->getHeaderLine('X-Forwarded-For'))) as $hop) {
            $hop = trim($hop);
            if (filter_var($hop, FILTER_VALIDATE_IP) === false) {
                break;
            }
            $hop = self::canonical($hop);
            if (!in_array($hop, $this->trustedProxies, true)) {
                return $hop;
            }
        }

        return $remote;
    }

    /**
     * The throttle bucket of an address: IPv4 per address (an IPv4-mapped IPv6 address counts as IPv4), IPv6 per
     * /64, because one subscriber usually holds a whole /64. Anything that is not an IP address is returned as is.
     */
    public static function bucket(string $ip): string
    {
        $packed = filter_var($ip, FILTER_VALIDATE_IP) === false ? false : inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return $ip;
        }
        if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            return (string) inet_ntop(substr($packed, 12));
        }

        return inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
    }
}
