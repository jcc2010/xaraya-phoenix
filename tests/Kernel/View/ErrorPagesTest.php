<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\View;

use Nyholm\Psr7\ServerRequest;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Http\Middleware\SecureHtml;
use Xaraya\Kernel\Http\MiddlewareRegistry;
use Xaraya\Kernel\Module\ModuleRegistry;
use Xaraya\Tests\Support\AppTestCase;
use Xaraya\Tests\Support\Fixtures;
use Xaraya\Tests\Support\Html;
use Xaraya\Tests\Support\ViewFixtures;

final class ErrorPagesTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ViewFixtures::themes($this->tmp . '/themes');
        Fixtures::theme($this->tmp . '/themes', 'brokenerr', ['engine' => 'php'], [
            'templates/layout.php' => '<html><body><?= $content ?></body></html>',
            'templates/error/default.php' => '<h1>x</h1><?php throw new \RuntimeException("error template exploded"); ?>',
        ]);
        Fixtures::theme($this->tmp . '/themes', 'brokenlayout', ['engine' => 'php'], [
            'templates/layout.php' => '<?php throw new \RuntimeException("layout exploded"); ?>',
            'templates/error/default.php' => '<h1>x</h1>',
        ]);
        ViewFixtures::shop($this->tmp . '/modules');
        $this->app()->container()->get(ModuleRegistry::class)->enable('shop');
    }

    /** @param array<string, mixed> $overrides */
    private function app(array $overrides = []): App
    {
        return $this->boot([$this->tmp . '/modules'], ['themes.paths' => [$this->tmp . '/themes'], 'app.theme' => 'plain', ...$overrides]);
    }

    private function log(): string
    {
        return implode('', array_map('file_get_contents', glob($this->tmp . '/logs/*') ?: []));
    }

    public function testNotFoundUsesTheThemeTemplateInsideTheLayout(): void
    {
        $response = $this->app()->handle(new ServerRequest('GET', '/nope'));
        self::assertSame(404, $response->getStatusCode());
        $html = Html::normalize((string) $response->getBody());
        self::assertStringContainsString('<title>404 Not Found</title>', $html);
        self::assertStringContainsString('<h1>Missing: Not Found</h1>', $html);
    }

    public function testOtherStatusesUseErrorDefaultAndKeepTheirHeaders(): void
    {
        $response = $this->app()->handle(new ServerRequest('DELETE', '/shop'));
        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET', $response->getHeaderLine('Allow'));
        self::assertStringContainsString('<h1>405 Method Not Allowed</h1>', Html::normalize((string) $response->getBody()));
    }

    public function testJsonClientsStillGetJson(): void
    {
        $response = $this->app()->handle((new ServerRequest('GET', '/nope'))->withHeader('Accept', 'application/json'));
        self::assertSame(['error' => ['status' => 404, 'message' => 'Not Found']], json_decode((string) $response->getBody(), true));
    }

    public function testServerErrorsAreThemedAndMaskedInProduction(): void
    {
        $response = $this->app(['app.debug' => false])->handle(new ServerRequest('GET', '/shop/boom'));
        self::assertSame(500, $response->getStatusCode());
        $html = Html::normalize((string) $response->getBody());
        self::assertStringContainsString('<h1>500 Server Error</h1>', $html);
        self::assertStringNotContainsString('exploded', $html);
    }

    public function testDebugServerErrorsKeepTheBuiltInPageWithItsTrace(): void
    {
        $body = (string) $this->app()->handle(new ServerRequest('GET', '/shop/boom'))->getBody();
        self::assertStringContainsString('shop exploded', $body);
        self::assertStringContainsString('<pre>', $body);
        self::assertStringNotContainsString('data-tagline', $body);
    }

    public function testMissingThemeFallsBackToTheBuiltInPageAndLogs(): void
    {
        $response = $this->app(['app.theme' => 'nope'])->handle(new ServerRequest('GET', '/nope'));
        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('<h1>404 Not Found</h1>', (string) $response->getBody());
        self::assertStringContainsString("Unknown theme 'nope'", $this->log());
    }

    public function testThrowingErrorTemplateFallsBackToTheBuiltInPageAndLogs(): void
    {
        $response = $this->app(['app.theme' => 'brokenerr'])->handle(new ServerRequest('GET', '/nope'));
        self::assertSame(404, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('<h1>404 Not Found</h1>', $body);
        self::assertStringNotContainsString('exploded', $body);
        self::assertStringContainsString('error template exploded', $this->log());
    }

    public function testThrowingLayoutFallsBackToTheBuiltInPageInProduction(): void
    {
        $response = $this->app(['app.theme' => 'brokenlayout', 'app.debug' => false])->handle(new ServerRequest('GET', '/shop/boom'));
        self::assertSame(500, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('<h1>500 Server Error</h1>', $body);
        self::assertStringNotContainsString('exploded', $body);
        self::assertStringContainsString('layout exploded', $this->log());
    }

    public function testSecureHtmlAliasIsRegistered(): void
    {
        self::assertInstanceOf(SecureHtml::class, $this->app()->container()->get(MiddlewareRegistry::class)->resolve('secureHtml'));
    }
}
