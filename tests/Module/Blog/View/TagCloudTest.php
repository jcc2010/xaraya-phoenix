<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\View;

use PHPUnit\Framework\TestCase;
use Xaraya\Module\Blog\View\TagCloud;

final class TagCloudTest extends TestCase
{
    public function testStepsFollowCountQuantilesAndTagsSortByName(): void
    {
        $cloud = TagCloud::build([
            ['name' => 'zeta', 'slug' => 'zeta', 'count' => 10],
            ['name' => 'Alpha', 'slug' => 'alpha', 'count' => 1],
            ['name' => 'beta', 'slug' => 'beta', 'count' => 1],
            ['name' => 'gamma', 'slug' => 'gamma', 'count' => 2],
            ['name' => 'delta', 'slug' => 'delta', 'count' => 3],
        ]);
        self::assertSame(
            ['Alpha' => 1, 'beta' => 1, 'delta' => 4, 'gamma' => 3, 'zeta' => 5],
            array_column($cloud, 'step', 'name'),
        );
        self::assertSame(['name' => 'Alpha', 'slug' => 'alpha', 'count' => 1, 'step' => 1], $cloud[0]);
    }

    public function testEqualCountsShareTheSmallestStep(): void
    {
        self::assertSame([1, 1], array_column(TagCloud::build([
            ['name' => 'a', 'slug' => 'a', 'count' => 4],
            ['name' => 'b', 'slug' => 'b', 'count' => 4],
        ]), 'step'));
        self::assertSame([], TagCloud::build([]));
        self::assertSame([1], array_column(TagCloud::build([['name' => 'a', 'slug' => 'a', 'count' => 9]]), 'step'));
        self::assertSame([1, 5], array_column(TagCloud::build([
            ['name' => 'a', 'slug' => 'a', 'count' => 1],
            ['name' => 'b', 'slug' => 'b', 'count' => 2],
        ]), 'step'), 'the most used tag always gets the largest step');
    }
}
