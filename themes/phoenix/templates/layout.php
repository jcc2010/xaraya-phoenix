<!doctype html>
<html lang="<?= $x->e($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $x->e($title) ?></title>
<?php foreach ($meta as $name => $value): ?>
<meta <?= str_starts_with((string) $name, 'og:') ? 'property' : 'name' ?>="<?= $x->e($name) ?>" content="<?= $x->e($value) ?>">
<?php endforeach ?>
<?php if ($canonical): ?>
<link rel="canonical" href="<?= $x->e($canonical) ?>">
<?php endif ?>
<?php foreach ($feeds as $feed): ?>
<link rel="alternate" type="<?= $x->e($feed['type']) ?>" title="<?= $x->e($feed['title']) ?>" href="<?= $x->e($feed['href']) ?>">
<?php endforeach ?>
<link rel="stylesheet" href="<?= $x->e($x->asset('theme/phoenix/phoenix.css')) ?>">
<?php foreach ($styles as $href): ?>
<link rel="stylesheet" href="<?= $x->e($href) ?>">
<?php endforeach ?>
</head>
<body>
<a class="skip-link" href="#main"><?= $x->e($x->t('Skip to content')) ?></a>
<header class="site-header">
<p class="site-title"><a href="/" rel="home"><?= $x->e($site) ?></a></p>
<?php if ($settings['tagline'] ?? ''): ?>
<p class="site-tagline"><?= $x->e($settings['tagline']) ?></p>
<?php endif ?>
<?= $x->blocks('header') ?>
</header>
<div class="site-body">
<main id="main" class="site-main" tabindex="-1">
<?= $content ?>
</main>
<?php $sidebar = $x->blocks('sidebar'); ?>
<?php if ($sidebar !== ''): ?>
<aside class="site-sidebar" aria-label="<?= $x->e($x->t('Sidebar')) ?>">
<?= $sidebar ?>
</aside>
<?php endif ?>
</div>
<footer class="site-footer">
<?= $x->blocks('footer') ?>
<p class="site-credit"><?= $x->e($x->t('Powered by {name}', ['name' => 'Xaraya Phoenix'])) ?></p>
</footer>
</body>
</html>
