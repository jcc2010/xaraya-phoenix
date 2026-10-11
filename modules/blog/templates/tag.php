<header class="blog-header">
<p class="blog-name"><?= $x->e($blog['title']) ?></p>
<h1 class="blog-title"><?= $x->e($x->t('Tagged “{tag}”', ['tag' => $tag['name']])) ?></h1>
</header>
<?php if ($posts === []): ?>
<p class="blog-empty"><?= $x->e($x->t('No more posts.')) ?></p>
<?php else: ?>
<div class="post-list">
<?php foreach ($posts as $post): ?>
<?= $x->render('blog::partials/post', ['post' => $post, 'full' => false]) ?>
<?php endforeach ?>
</div>
<?php endif ?>
<?= $x->render('blog::partials/pager', ['pager' => $pager]) ?>
