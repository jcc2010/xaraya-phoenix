<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Auth;

use DateTimeImmutable;
use DateTimeZone;
use PDOException;
use RuntimeException;
use Xaraya\Kernel\Db\Connection;

/**
 * Fixed-window counters in xar_throttle: a key's window opens at its first hit and lasts $decaySeconds. Keys are
 * stored as their sha256. Login failures and the `throttle` route middleware use it.
 */
final class Throttle
{
    public function __construct(private readonly Connection $db) {}

    /** Seconds until $key may be tried again once it has $max hits in its window; 0 while it is allowed. */
    public function retryAfter(string $key, int $max, DateTimeImmutable $now): int
    {
        $row = $this->db->select('throttle')->where('key', '=', hash('sha256', $key))->where('reset_at', '>', $now)->first();
        if ($row === null || (int) $row['hits'] < $max) {
            return 0;
        }

        return max(1, (new DateTimeImmutable((string) $row['reset_at'], new DateTimeZone('UTC')))->getTimestamp() - $now->getTimestamp());
    }

    /**
     * Counts a hit and returns the hits in the current window. Every step is one atomic statement, so concurrent
     * requests never lose a count: increment a live window; else restart an expired one (only one request can
     * win that UPDATE); else insert, and if a concurrent request inserted first, go round again.
     */
    public function hit(string $key, int $decaySeconds, DateTimeImmutable $now): int
    {
        $id = hash('sha256', $key);
        $column = $this->db->ident('key');
        $reset = $now->modify("+{$decaySeconds} seconds");
        for ($attempt = 0; $attempt < 3; $attempt++) {
            if ($this->db->query("UPDATE {throttle} SET hits = hits + 1 WHERE {$column} = ? AND reset_at > ?", [$id, $now])->rowCount() > 0) {
                return (int) $this->db->fetchValue("SELECT hits FROM {throttle} WHERE {$column} = ?", [$id]);
            }
            if ($this->db->query("UPDATE {throttle} SET hits = 1, reset_at = ? WHERE {$column} = ? AND reset_at <= ?", [$reset, $id, $now])->rowCount() > 0) {
                return 1;
            }
            if (random_int(1, 100) === 1) {
                $this->db->query('DELETE FROM {throttle} WHERE reset_at <= ?', [$now]);
            }
            try {
                $this->db->insert('throttle', ['key' => $id, 'hits' => 1, 'reset_at' => $reset]);

                return 1;
            } catch (PDOException $e) {
                if (!self::isIntegrityViolation($e)) {
                    throw $e;
                }
                // a concurrent request created the row first: count against it
            }
        }

        throw new RuntimeException('The throttle counter could not be updated');
    }

    /** SQLSTATE class 23: a unique-key violation, i.e. a concurrent request inserted the row first. */
    public static function isIntegrityViolation(PDOException $e): bool
    {
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());

        return str_starts_with($state, '23');
    }

    public function clear(string $key): void
    {
        $this->db->delete('throttle', ['key' => hash('sha256', $key)]);
    }
}
