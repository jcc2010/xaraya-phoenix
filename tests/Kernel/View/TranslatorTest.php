<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\View;

use Xaraya\Kernel\Module\ModuleRegistry;
use Xaraya\Kernel\View\Translator;
use Xaraya\Kernel\View\ViewException;
use Xaraya\Tests\Support\AppTestCase;
use Xaraya\Tests\Support\Fixtures;

final class TranslatorTest extends AppTestCase
{
    /** @param array<string, string> $messages */
    private function catalog(string $dir, string $locale, array $messages): string
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents("{$dir}/{$locale}.php", '<?php return ' . var_export($messages, true) . ';');

        return $dir;
    }

    public function testFallsBackToTheKeyAndReplacesPlaceholders(): void
    {
        $t = new Translator('en', []);
        self::assertSame('Hello, Ada!', $t->t('Hello, {name}!', ['name' => 'Ada']));
        self::assertSame('{missing} stays', $t->t('{missing} stays', ['other' => 'x']));
        self::assertSame('7 items', $t->t('{n} items', ['n' => 7]));
    }

    public function testLaterDirectoriesWinAndRegionalLocalesExtendTheBase(): void
    {
        $module = $this->catalog($this->tmp . '/module/lang', 'fr', ['Hello' => 'Bonjour', 'Bye' => 'Au revoir']);
        $this->catalog($this->tmp . '/module/lang', 'fr_CA', ['Bye' => 'Bye-bye']);
        $theme = $this->catalog($this->tmp . '/theme/lang', 'fr', ['Hello' => 'Salut']);
        $t = new Translator('fr-CA', [$module, $theme]);
        self::assertSame('Salut', $t->t('Hello'));
        self::assertSame('Bye-bye', $t->t('Bye'));
        self::assertSame('fr-CA', $t->locale());
    }

    public function testInvalidLocaleAndCatalogThrow(): void
    {
        try {
            new Translator('../etc', []);
            self::fail('expected an invalid locale to throw');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        mkdir($this->tmp . '/bad');
        file_put_contents($this->tmp . '/bad/en.php', '<?php return "nope";');
        $this->expectException(ViewException::class);
        (new Translator('en', [$this->tmp . '/bad']))->t('x');
    }

    public function testAppReadsEnabledModulesThenTheThemeChain(): void
    {
        Fixtures::module($this->tmp . '/modules', 'delta', [], ['lang/fr.php' => "<?php return ['Hi' => 'Salut (delta)', 'Only' => 'Seulement'];"]);
        Fixtures::theme($this->tmp . '/themes', 'base', [], ['lang/fr.php' => "<?php return ['Hi' => 'Salut (base)'];"]);
        Fixtures::theme($this->tmp . '/themes', 'kid', ['parent' => 'base'], ['lang/fr.php' => "<?php return ['Hi' => 'Salut (kid)'];"]);
        $overrides = ['themes.paths' => [$this->tmp . '/themes'], 'app.theme' => 'kid', 'app.locale' => 'fr'];
        $this->boot([$this->tmp . '/modules'], $overrides)->container()->get(ModuleRegistry::class)->enable('delta');

        $t = $this->boot([$this->tmp . '/modules'], $overrides)->container()->get(Translator::class);
        self::assertSame('Salut (kid)', $t->t('Hi'));
        self::assertSame('Seulement', $t->t('Only'));

        $noTheme = $this->boot([$this->tmp . '/modules'], [...$overrides, 'app.theme' => 'missing'])->container()->get(Translator::class);
        self::assertSame('Salut (delta)', $noTheme->t('Hi'));
    }
}
