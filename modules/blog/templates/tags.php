<header class="blog-header">
<p class="blog-name"><?= $x->e($blog['title']) ?></p>
<h1 class="blog-title"><?= $x->e($x->t('Tags')) ?></h1>
</header>
<?php if ($tags === []): ?>
<p class="blog-empty"><?= $x->e($x->t('No tags yet.')) ?></p>
<?php else: ?>
<?= $x->render('blog::partials/tagcloud', ['tags' => $tags]) ?>
<?php endif ?>
