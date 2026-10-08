<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Db;

use InvalidArgumentException;
use Xaraya\Kernel\Db\Schema\Blueprint;
use Xaraya\Tests\Support\DbTestCase;

final class SelectTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->db->schema()->create('posts', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('title');
            $t->datetime('published');
            $t->int('views')->nullable();
        });
        foreach ([
            ['01m3t19wn8reaww81zg1m47tja', 'A', '2026-10-03 00:00:00', 10],
            ['01m3t19wn8reaww81zg1m47tjc', 'C', '2026-10-02 00:00:00', null],
            ['01m3t19wn8reaww81zg1m47tjb', 'B', '2026-10-02 00:00:00', 30],
            ['01m3t19wn8reaww81zg1m47tjd', 'D', '2026-10-01 00:00:00', 40],
        ] as [$id, $title, $published, $views]) {
            $this->db->insert('posts', compact('id', 'title', 'published', 'views'));
        }
    }

    /** @param list<array<string, mixed>> $rows @return list<mixed> */
    private static function titles(array $rows): array
    {
        return array_column($rows, 'title');
    }

    public function testWhereOrderAndLimit(): void
    {
        $rows = $this->db->select('posts')->where('views', '>=', 20)->orderBy('views', 'desc')->all();
        self::assertSame(['D', 'B'], self::titles($rows));

        $rows = $this->db->select('posts')->orderBy('title')->limit(2, 1)->all();
        self::assertSame(['B', 'C'], self::titles($rows));

        self::assertSame(['A'], self::titles($this->db->select('posts')->where('title', 'like', 'A%')->all()));
    }

    public function testWhereNullAndIn(): void
    {
        self::assertSame(['C'], self::titles($this->db->select('posts')->whereNull('views')->all()));
        self::assertSame(3, $this->db->select('posts')->whereNull('views', not: true)->count());
        self::assertSame(['A', 'D'], self::titles($this->db->select('posts')->whereIn('title', ['D', 'A'])->orderBy('title')->all()));
        self::assertSame([], $this->db->select('posts')->whereIn('title', [])->all());
    }

    public function testKeysetPagingAcrossTies(): void
    {
        $page1 = $this->db->select('posts')->orderBy('published', 'desc')->orderBy('id', 'desc')->limit(2)->all();
        self::assertSame(['A', 'C'], self::titles($page1));

        $last = $page1[1];
        $page2 = $this->db->select('posts')
            ->cursor(['published' => $last['published'], 'id' => $last['id']], '<')
            ->orderBy('published', 'desc')->orderBy('id', 'desc')->limit(2)->all();
        self::assertSame(['B', 'D'], self::titles($page2));
    }

    public function testFirstCountAndColumns(): void
    {
        $first = $this->db->select('posts')->columns('id', 'title')->orderBy('title', 'desc')->first();
        self::assertSame(['id' => '01m3t19wn8reaww81zg1m47tjd', 'title' => 'D'], $first);
        self::assertNull($this->db->select('posts')->where('title', '=', 'Z')->first());
        self::assertSame(2, $this->db->select('posts')->where('published', '=', '2026-10-02 00:00:00')->count());
    }

    public function testFirstRespectsOffset(): void
    {
        $first = $this->db->select('posts')->orderBy('title')->limit(10, 2)->first();
        self::assertSame('C', $first['title']);
    }

    public function testRejectsBadOperatorAndIdentifier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->db->select('posts')->where('title', 'OR 1=1 --', 'x');
    }

    public function testRejectsInjectedColumn(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->db->select('posts')->orderBy('title; DROP TABLE posts');
    }
}
