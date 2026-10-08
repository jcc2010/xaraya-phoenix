<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Config;

use PHPUnit\Framework\TestCase;

use function Xaraya\env;

use Xaraya\Kernel\Config\Env;

final class EnvTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        Env::reset();
        $this->file = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($this->file, <<<'ENV'
            # comment line
            APP_NAME="Phoenix Test"
            SINGLE='quoted # not a comment'
            PLAIN=value # trailing comment
            FLAG=true
            OFF=false
            NOTHING=null
            EMPTY=

            ENV);
        Env::load($this->file);
    }

    protected function tearDown(): void
    {
        unlink($this->file);
        putenv('XAR_ENV_TEST_REAL');
        Env::reset();
    }

    public function testParsesValuesQuotesAndComments(): void
    {
        self::assertSame('Phoenix Test', Env::get('APP_NAME'));
        self::assertSame('quoted # not a comment', Env::get('SINGLE'));
        self::assertSame('value', Env::get('PLAIN'));
        self::assertSame('', Env::get('EMPTY'));
    }

    public function testConvertsBooleansAndNull(): void
    {
        self::assertTrue(Env::get('FLAG'));
        self::assertFalse(Env::get('OFF'));
        self::assertSame('fallback', Env::get('NOTHING', 'fallback'));
    }

    public function testDefaultForMissingKey(): void
    {
        self::assertSame(42, Env::get('MISSING_KEY', 42));
        self::assertSame(42, env('MISSING_KEY', 42));
    }

    public function testRealEnvironmentWins(): void
    {
        putenv('XAR_ENV_TEST_REAL=from-process');
        file_put_contents($this->file, "XAR_ENV_TEST_REAL=from-file\n");
        Env::load($this->file);
        self::assertSame('from-process', Env::get('XAR_ENV_TEST_REAL'));
    }

    public function testMissingFileIsIgnored(): void
    {
        Env::load('/nonexistent/.env');
        self::assertSame('Phoenix Test', Env::get('APP_NAME'));
    }
}
