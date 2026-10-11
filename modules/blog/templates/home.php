<header class="blog-header">
<h1 class="blog-title"><?= $x->e($blog['title']) ?></h1>
<?php if ($blog['description'] !== ''): ?>
<p class="blog-description"><?= $x->e($blog['description']) ?></p>
<?php endif ?>
</header>
<?php if ($pinned !== null): ?>
<section class="blog-pinned" aria-label="<?= $x->e($x->t('Pinned post')) ?>">
<?= $x->render('blog::partials/post', ['post' => $pinned, 'full' => false]) ?>
</section>
<?php endif ?>
<?php if ($posts === []): ?>
<p class="blog-empty"><?= $x->e($x->t('No posts yet.')) ?></p>
<?php else: ?>
<div class="post-list">
<?php foreach ($posts as $post): ?>
<?= $x->render('blog::partials/post', ['post' => $post, 'full' => false]) ?>
<?php endforeach ?>
</div>
<?php endif ?>
<?= $x->render('blog::partials/pager', ['pager' => $pager]) ?>
