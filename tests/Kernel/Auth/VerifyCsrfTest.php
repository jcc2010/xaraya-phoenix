<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Auth;

use Xaraya\Tests\Support\AuthTestCase;
use Xaraya\Tests\Support\Browser;

final class VerifyCsrfTest extends AuthTestCase
{
    public function testTheHelperPrintsTheSessionTokenAsAHiddenField(): void
    {
        $browser = new Browser($this->probeApp());
        $browser->get('/probe/form');
        $token = $browser->csrfField();
        self::assertNotNull($token);
        self::assertMatchesRegularExpression('/^<form method="post"><input type="hidden" name="_csrf" value="[A-Za-z0-9_-]{43}"><\/form>$/', $browser->body());
        self::assertArrayHasKey('xar_session', $browser->cookies, 'the token lives in a stored session');
        $browser->get('/probe/form');
        self::assertSame($token, $browser->csrfField(), 'one token per session');
    }

    public function testUnsafeMethodsNeedTheTokenAsAFieldOrHeader(): void
    {
        $browser = new Browser($this->probeApp());
        $browser->get('/probe/form');
        $token = (string) $browser->csrfField();

        self::assertSame('ok POST', (string) $browser->post('/probe/form')->getBody());
        foreach (['PUT', 'PATCH', 'DELETE'] as $method) {
            $response = $browser->send($browser->request($method, '/probe/form', ['X-CSRF-Token' => $token]));
            self::assertSame("ok {$method}", (string) $response->getBody());
        }
    }

    public function testAMissingWrongOrForeignTokenIs403(): void
    {
        $app = $this->probeApp();
        $victim = new Browser($app);
        $victim->get('/probe/form');
        $attacker = new Browser($app);
        $attacker->get('/probe/form');
        $foreign = (string) $attacker->csrfField();

        $missing = $victim->send($victim->request('POST', '/probe/form'));
        foreach ([$missing, $victim->post('/probe/form', ['_csrf' => '']), $victim->post('/probe/form', ['_csrf' => 'guess']), $victim->post('/probe/form', ['_csrf' => $foreign])] as $i => $response) {
            self::assertSame(403, $response->getStatusCode(), "case {$i}");
            self::assertStringContainsString('The form has expired', (string) $response->getBody());
        }
        $json = $victim->send($victim->request('DELETE', '/probe/form', ['Accept' => 'application/json']));
        self::assertSame(403, $json->getStatusCode());
        self::assertSame('application/json', $json->getHeaderLine('Content-Type'));

        $fresh = new Browser($app);
        self::assertSame(403, $fresh->post('/probe/form', ['_csrf' => $foreign])->getStatusCode(), 'no session, no token');
    }

    public function testSafeMethodsAndCsrfOffRoutesAreNotChecked(): void
    {
        $app = $this->probeApp();
        $browser = new Browser($app);
        self::assertSame(200, $browser->get('/probe/plain')->getStatusCode());
        self::assertSame(200, $browser->send($browser->request('HEAD', '/probe/plain'))->getStatusCode());
        self::assertSame(204, $browser->post('/probe/login/' . $this->user($app, 'ada')->id)->getStatusCode());
    }
}
