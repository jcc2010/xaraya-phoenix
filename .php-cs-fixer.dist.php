<?php

declare(strict_types=1);

$dirs = array_values(array_filter(
    [__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/examples', __DIR__ . '/modules', __DIR__ . '/config', __DIR__ . '/public'],
    'is_dir',
));
$finder = (new PhpCsFixer\Finder())->in($dirs);
if (is_file(__DIR__ . '/bin/xar')) {
    $finder->append([__DIR__ . '/bin/xar']);
}

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS' => true,
        'declare_strict_types' => true,
        'no_unused_imports' => true,
        'ordered_imports' => true,
    ])
    ->setFinder($finder);
