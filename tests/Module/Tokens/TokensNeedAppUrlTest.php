<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Tokens;

use Xaraya\Kernel\Module\ModuleException;
use Xaraya\Kernel\Module\ModuleRegistry;

final class TokensNeedAppUrlTest extends TokensTestCase
{
    public function testEnablingTokensWithoutAnAbsoluteAppUrlIsRefused(): void
    {
        $this->enableBlog();
        foreach (['', 'localhost:8080', '/blog', 'ftp://example.com'] as $url) {
            [$code, , $err] = $this->xar(['module:enable', 'tokens'], $this->app(['app.url' => $url]));
            self::assertSame(1, $code, $url);
            self::assertStringContainsString("Module 'tokens' needs app.url (APP_URL)", $err, $url);
            self::assertFalse($this->app()->container()->get(ModuleRegistry::class)->isEnabled('tokens'), $url);
        }
        [$code, $out, $err] = $this->xar(['module:enable', 'tokens'], $this->app(['app.url' => 'HTTPS://blog.example.com']));
        self::assertSame(0, $code, $out . $err);
    }

    public function testBootRefusesAnEnabledTokensModuleWithoutAppUrl(): void
    {
        $this->enableTokens();
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage("Module 'tokens' needs app.url (APP_URL)");
        $this->app(['app.url' => '']);
    }
}
