<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Blocks;

/** A block type with an admin form; the admin UI that calls these arrives with Plan 3. */
interface ConfigurableBlock extends Block
{
    /** @param array<string, mixed> $config */
    public function form(array $config): string;

    /**
     * @param array<string, mixed> $input submitted form fields
     * @return array<string, mixed> the config to store
     * @throws \InvalidArgumentException on invalid input
     */
    public function validate(array $input): array;
}
