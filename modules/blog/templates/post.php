<?= $x->render('blog::partials/post', ['post' => $post, 'full' => true]) ?>
<?php if ($newer !== null || $older !== null): ?>
<nav class="post-nav" aria-label="<?= $x->e($x->t('More posts')) ?>">
<?php if ($newer !== null): ?>
<p class="post-nav-newer"><?= $x->e($x->t('Newer:')) ?> <a href="<?= $x->e($newer['url']) ?>"><?= $x->e($newer['title']) ?></a></p>
<?php endif ?>
<?php if ($older !== null): ?>
<p class="post-nav-older"><?= $x->e($x->t('Older:')) ?> <a href="<?= $x->e($older['url']) ?>"><?= $x->e($older['title']) ?></a></p>
<?php endif ?>
</nav>
<?php endif ?>
