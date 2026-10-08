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

    /** @return list<array<string, mixed>> */
    private function seedTies(): array
    {
        $this->db->schema()->create('entries', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->datetime('published');
        });
        $rows = [
            ['01m3t19wn8reaww81zg1m47ta1', '2026-10-03 00:00:00'],
            ['01m3t19wn8reaww81zg1m47ta2', '2026-10-02 00:00:00'],
            ['01m3t19wn8reaww81zg1m47ta3', '2026-10-02 00:00:00'],
            ['01m3t19wn8reaww81zg1m47ta4', '2026-10-02 00:00:00'],
            ['01m3t19wn8reaww81zg1m47ta5', '2026-10-01 00:00:00'],
        ];
        foreach ($rows as [$id, $published]) {
            $this->db->insert('entries', compact('id', 'published'));
        }

        return $this->db->select('entries')->orderBy('published')->orderBy('id')->all();
    }

    /** @return list<string> */
    private function walk(string $direction, string $op): array
    {
        $seen = [];
        $cursor = null;
        for ($guard = 0; $guard < 10; $guard++) {
            $query = $this->db->select('entries')->orderBy('published', $direction)->orderBy('id', $direction)->limit(2);
            if ($cursor !== null) {
                $query->cursor(['published' => $cursor['published'], 'id' => $cursor['id']], $op);
            }
            $page = $query->all();
            if ($page === []) {
                break;
            }
            self::assertLessThanOrEqual(2, count($page));
            array_push($seen, ...array_map('strval', array_column($page, 'id')));
            $cursor = $page[count($page) - 1];
        }

        return $seen;
    }

    public function testDescendingKeysetWalksEveryRowOnce(): void
    {
        $ascending = array_map('strval', array_column($this->seedTies(), 'id'));
        self::assertSame(array_reverse($ascending), $this->walk('desc', '<'));
    }

    public function testAscendingKeysetWalksEveryRowOnce(): void
    {
        $ascending = array_map('strval', array_column($this->seedTies(), 'id'));
        self::assertSame($ascending, $this->walk('asc', '>'));
    }

    public function testCursorRejectsNullValuesAndPositionalKeys(): void
    {
        foreach ([['published' => '2026-10-01 00:00:00', 'id' => null], ['2026-10-01 00:00:00']] as $cursor) {
            try {
                $this->db->select('posts')->cursor($cursor, '<');
                self::fail('expected InvalidArgumentException');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
