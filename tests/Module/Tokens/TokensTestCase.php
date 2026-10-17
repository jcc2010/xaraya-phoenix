<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Tokens;

use Xaraya\Kernel\App;
use Xaraya\Tests\Module\Blog\BlogTestCase;

abstract class TokensTestCase extends BlogTestCase
{
    /** Enables blog, then tokens; returns a fresh app. */
    protected function enableTokens(): App
    {
        $this->enableBlog();
        [$code, $out, $err] = $this->xar(['module:enable', 'tokens']);
        self::assertSame(0, $code, $out . $err);

        return $this->app();
    }

    /**
     * Creates a token through `xar token:create` and returns its plaintext.
     *
     * @param list<string> $extra e.g. ['--blog=notes', '--expires=1d']
     */
    protected function token(string $scopes = 'blog:write', array $extra = []): string
    {
        [$code, $out, $err] = $this->xar(['token:create', '--name=test', '--scopes=' . $scopes, ...$extra]);
        self::assertSame(0, $code, $out . $err);
        $lines = explode("\n", trim($out));

        return (string) end($lines);
    }
}
