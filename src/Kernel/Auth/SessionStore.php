<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Auth;

use DateTimeImmutable;
use SensitiveParameter;
use Xaraya\Kernel\Db\Connection;

/** xar_sessions. Rows are keyed by Session::hash() of the cookie value and expire after an idle lifetime. */
final class SessionStore
{
    /** last_activity is written at most once in this many seconds for an unchanged session. */
    public const TOUCH_SECONDS = 60;

    private bool $ready = false;

    public function __construct(private readonly Connection $db) {}

    public function load(#[SensitiveParameter] string $id, DateTimeImmutable $now): ?Session
    {
        if (preg_match(Session::ID, $id) !== 1 || !$this->ready()) {
            return null;
        }
        $row = $this->db->select('sessions')->where('id', '=', Session::hash($id))->where('expires_at', '>', $now)->first();
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
        );
    }

    /** Stores the session (giving a new one its id) and deletes the row a regenerate() replaced. */
    public function save(Session $session, DateTimeImmutable $now, int $lifetime): void
    {
        $replaced = $session->replaced();
        if ($replaced !== null) {
            $this->db->delete('sessions', ['id' => $replaced]);
        }
        $id = $session->id() ?? Session::newId();
        $this->db->upsert('sessions', [
            'id' => Session::hash($id),
            'user_id' => $session->userId(),
            'data' => json_encode($session->data(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'last_activity' => $now,
            'expires_at' => $now->modify("+{$lifetime} seconds"),
        ], ['id']);
        $session->saved($id);
    }

    /** Extends an unchanged session's idle lifetime, at most once a minute. */
    public function touch(Session $session, DateTimeImmutable $now, int $lifetime): void
    {
        $id = $session->id();
        if ($id === null || ($session->lastActivity !== null && $session->lastActivity > $now->modify('-' . self::TOUCH_SECONDS . ' seconds'))) {
            return;
        }
        $this->db->update('sessions', ['last_activity' => $now, 'expires_at' => $now->modify("+{$lifetime} seconds")], ['id' => Session::hash($id)]);
    }

    /** Deletes the row of a session that ended (logout, or a regenerate with nothing left to save). */
    public function forget(Session $session): void
    {
        if ($session->replaced() !== null) {
            $this->db->delete('sessions', ['id' => $session->replaced()]);
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

    private function ready(): bool
    {
        return $this->ready || ($this->ready = $this->db->hasTable('sessions'));
    }
}
