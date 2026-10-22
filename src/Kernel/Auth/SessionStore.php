<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Auth;

use DateTimeImmutable;
use SensitiveParameter;
use Xaraya\Kernel\Db\Connection;

/**
 * xar_sessions. Rows are keyed by Session::hash() of the cookie value. A session dies after an idle lifetime or,
 * whatever its activity, $maxLifetime seconds after it was created (or its user last logged in).
 */
final class SessionStore
{
    /** last_activity is written at most once in this many seconds for an unchanged session. */
    public const TOUCH_SECONDS = 60;

    private bool $ready = false;

    /** @param int $maxLifetime the absolute lifetime in seconds (session.max_lifetime) */
    public function __construct(private readonly Connection $db, private readonly int $maxLifetime = 86400) {}

    public function load(#[SensitiveParameter] string $id, DateTimeImmutable $now): ?Session
    {
        if (preg_match(Session::ID, $id) !== 1 || !$this->ready()) {
            return null;
        }
        $row = $this->db->select('sessions')
            ->where('id', '=', Session::hash($id))
            ->where('expires_at', '>', $now)
            ->where('created_at', '>', $now->modify('-' . $this->maxLifetime . ' seconds'))
            ->first();
        if ($row === null) {
            return null;
        }
        $data = json_decode((string) $row['data'], true);
        /** @var array<string, mixed> $data */
        $data = is_array($data) ? $data : [];

        return Session::loaded(
            $id,
            $row['user_id'] === null ? null : (string) $row['user_id'],
            $data,
            new DateTimeImmutable((string) $row['last_activity'], $now->getTimezone()),
            new DateTimeImmutable((string) $row['created_at'], $now->getTimezone()),
        );
    }

    /**
     * Stores the session and deletes the row a regenerate() replaced. A new id (a fresh session, or one that
     * regenerated) is inserted; an existing one is only ever updated, so a session that was deleted meanwhile
     * (a logout or deleteForUser() in another request) stays deleted. Returns false, storing nothing, when the
     * session is gone or the sessions table does not exist yet; the caller then must not send its cookie.
     */
    public function save(Session $session, DateTimeImmutable $now, int $lifetime): bool
    {
        if (!$this->ready()) {
            return false;
        }
        $replaced = $session->replaced();
        // A plain regenerate() of a session ended elsewhere must not carry it over to a new id. A login may: it
        // is a new authentication (and login() clears createdAt).
        if ($replaced !== null && $this->db->delete('sessions', ['id' => $replaced]) === 0 && $session->createdAt() !== null) {
            return false;
        }
        $createdAt = $session->createdAt() ?? $now;
        $row = [
            'user_id' => $session->userId(),
            'data' => json_encode($session->data(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'last_activity' => $now,
            'expires_at' => $this->expiry($now, $createdAt, $lifetime),
        ];
        $id = $session->id();
        if ($id === null) {
            $id = Session::newId();
            $this->db->insert('sessions', ['id' => Session::hash($id), ...$row, 'created_at' => $createdAt]);
        } elseif (!$this->updateExisting(Session::hash($id), $row)) {
            return false;
        }
        $session->saved($id, $createdAt);

        return true;
    }

    /** Extends an unchanged session's idle lifetime, at most once a minute and never past its absolute lifetime. */
    public function touch(Session $session, DateTimeImmutable $now, int $lifetime): void
    {
        $id = $session->id();
        if ($id === null || ($session->lastActivity !== null && $session->lastActivity > $now->modify('-' . self::TOUCH_SECONDS . ' seconds'))) {
            return;
        }
        $this->db->update(
            'sessions',
            ['last_activity' => $now, 'expires_at' => $this->expiry($now, $session->createdAt() ?? $now, $lifetime)],
            ['id' => Session::hash($id)],
        );
    }

    /** Deletes the row of a session that ended (logout, a regenerate with nothing left to save, or emptied data). */
    public function forget(Session $session): void
    {
        $id = $session->id();
        foreach (array_filter([$session->replaced(), $id === null ? null : Session::hash($id)]) as $hash) {
            if ($this->ready()) {
                $this->db->delete('sessions', ['id' => $hash]);
            }
        }
    }

    /** Signs a user out everywhere (a password change, a disabled account). Returns the number of sessions ended. */
    public function deleteForUser(string $userId): int
    {
        return $this->ready() ? $this->db->delete('sessions', ['user_id' => $userId]) : 0;
    }

    /** Deletes expired sessions. */
    public function gc(DateTimeImmutable $now): int
    {
        return $this->db->query('DELETE FROM {sessions} WHERE expires_at <= ?', [$now])->rowCount();
    }

    /** @param array<string, mixed> $row */
    private function updateExisting(string $hash, array $row): bool
    {
        if ($this->db->update('sessions', $row, ['id' => $hash]) > 0) {
            return true;
        }

        // MySQL counts changed rows, not matched ones: an update within the same second may change nothing.
        return $this->db->select('sessions')->where('id', '=', $hash)->count() > 0;
    }

    /** The idle expiry, capped by the absolute lifetime. */
    private function expiry(DateTimeImmutable $now, DateTimeImmutable $createdAt, int $lifetime): DateTimeImmutable
    {
        return min($now->modify("+{$lifetime} seconds"), $createdAt->modify('+' . $this->maxLifetime . ' seconds'));
    }

    private function ready(): bool
    {
        return $this->ready || ($this->ready = $this->db->hasTable('sessions'));
    }
}
