<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Blocks;

use Xaraya\Kernel\Auth\Http\LoginController;

/**
 * The built-in "login" block: a plain link to the login page for guests (no form, so it never needs a session or
 * CSRF token and an anonymous page stays publicly cacheable); the user's name and a logout button otherwise (a
 * signed-in response already carries a session, so it is private).
 */
final class LoginBlock implements Block
{
    public function render(array $config, BlockContext $context): string
    {
        $x = $context->helpers;
        $user = $x->user();
        if ($user === null) {
            $uri = $context->request()?->getUri();
            $next = $uri === null ? '/' : LoginController::safeNext($uri->getPath() . ($uri->getQuery() === '' ? '' : '?' . $uri->getQuery()));

            return '<p><a href="' . $x->e($x->url('auth.login', $next === '/' ? [] : ['next' => $next])) . '">' . $x->e($x->t('Log in')) . '</a></p>';
        }

        return '<p>' . $x->e($x->t('Signed in as {name}', ['name' => $user->displayName()])) . '</p>'
            . '<form class="logout-form" method="post" action="' . $x->e($x->url('auth.logout')) . '">' . $x->csrf()
            . '<button type="submit">' . $x->e($x->t('Log out')) . '</button></form>';
    }
}
