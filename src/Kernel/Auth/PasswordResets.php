<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Auth;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use SensitiveParameter;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Mail\Mailer;
use Xaraya\Kernel\Mail\MailException;
use Xaraya\Kernel\Mail\Message;
use Xaraya\Kernel\Routing\UrlGenerator;
use Xaraya\Kernel\View\Translator;

/**
 * Password reset links: {app.url}/password/reset/<token>, where the token is 32 random bytes and only its sha256 is
 * stored. A link works once, for 60 minutes; asking again replaces it. Using it signs the user out everywhere.
 *
 * The link is built from app.url (UrlGenerator), never from the request's Host, so a forged Host cannot point it
 * elsewhere.
 */
final class PasswordResets
{
    public const TTL = 3600;

    public function __construct(
        private readonly Connection $db,
        private readonly UserRepository $users,
        private readonly SessionStore $sessions,
        private readonly Mailer $mailer,
        private readonly UrlGenerator $urls,
        private readonly Translator $translator,
    ) {}

    /**
     * Mails a link when $email belongs to an active user, and does nothing otherwise; the caller never learns which.
     * The token, its link and its hash are made for every address, so only the row write and the mail hand-off
     * differ between an active account and any other.
     *
     * @throws LogicException when app.url is not set, so links would not be absolute (whatever $email is)
     * @throws MailException when the message could not be handed over (the link is stored all the same)
     */
    public function send(string $email, DateTimeImmutable $now): void
    {
        $token = Session::newId();
        $hash = hash('sha256', $token);
        $url = $this->urls->generate('auth.reset', ['token' => $token], true);
        if (preg_match('#^https?://#i', $url) !== 1) {
            throw new LogicException('Set app.url (APP_URL): password reset links must be absolute');
        }
        $user = str_contains($email, '@') ? $this->users->findByLogin($email) : null;
        if ($user === null || !$user->isActive()) {
            return;
        }
        $this->db->transaction(function (Connection $db) use ($user, $hash, $now): void {
            $db->delete('password_resets', ['user_id' => $user->id]);
            $db->insert('password_resets', [
                'token_hash' => $hash,
                'user_id' => $user->id,
                'expires_at' => $now->modify('+' . self::TTL . ' seconds'),
                'created_at' => $now,
            ]);
        });
        $t = $this->translator;
        try {
            $message = new Message($user->email, $t->t('Reset your password'), $t->t(
                "Someone asked to reset the password of the account {handle}. To choose a new password, open this link:\n\n{url}\n\nThe link works once, for 60 minutes. If you did not ask for it, ignore this email.",
                ['handle' => $user->handle, 'url' => $url],
            ));
        } catch (InvalidArgumentException $e) {
            // A stored address the mailer refuses is a delivery failure like any other (and must not become a 500).
            throw new MailException('The account\'s email address cannot receive mail: ' . $e->getMessage(), 0, $e);
        }
        $this->mailer->send($message);
    }

    /** The active user a valid, unexpired link belongs to. */
    public function find(#[SensitiveParameter] string $token, DateTimeImmutable $now): ?User
    {
        if (preg_match(Session::ID, $token) !== 1) {
            return null;
        }
        $userId = $this->db->fetchValue('SELECT user_id FROM {password_resets} WHERE token_hash = ? AND expires_at > ?', [hash('sha256', $token), $now]);
        $user = $userId === null ? null : $this->users->find((string) $userId);

        return $user !== null && $user->isActive() ? $user : null;
    }

    /**
     * Sets the new password and spends the link, then ends every session of the user and drops any other link they
     * hold, all in one transaction. Returns null when the link is unknown, used or expired. The link is spent by a
     * conditional DELETE, so of two concurrent submissions only the one whose DELETE removed the row goes on.
     *
     * @throws InvalidArgumentException for a password that is empty, too short, too long or contains NUL, before the
     *                                  link is spent
     */
    public function reset(#[SensitiveParameter] string $token, #[SensitiveParameter] string $password, DateTimeImmutable $now): ?User
    {
        UserRepository::checkPassword($password);
        $user = $this->find($token, $now);
        if ($user === null) {
            return null;
        }
        $hash = hash('sha256', $token);

        return $this->db->transaction(function (Connection $db) use ($user, $hash, $password, $now): ?User {
            $spent = $db->query(
                'DELETE FROM {password_resets} WHERE token_hash = ? AND user_id = ? AND expires_at > ?',
                [$hash, $user->id, $now],
            )->rowCount();
            if ($spent !== 1) {
                return null;
            }
            $this->users->setPassword($user, $password);
            $db->delete('password_resets', ['user_id' => $user->id]);
            $this->sessions->deleteForUser($user->id);

            return $user;
        });
    }
}
