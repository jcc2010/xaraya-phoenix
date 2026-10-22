<?php $preview ??= false; /* an admin preview: no links to the post's (not yet real) URL, and an <h2> title */ ?>
<article class="post post-<?= $x->e($post->kind) ?><?= $post->pinned ? ' post-pinned' : '' ?>">
<?php if ($post->pinned): ?>
<p class="post-pinned-label"><?= $x->e($x->t('Pinned')) ?></p>
<?php endif ?>
<?php if ($post->kind !== 'link'): ?>
<?php if ($preview): ?>
<h2 class="post-title"><?= $x->e($post->title) ?></h2>
<?php elseif ($full): ?>
<h1 class="post-title"><?= $x->e($post->title) ?></h1>
<?php else: ?>
<h2 class="post-title"><a href="<?= $x->e($post->url) ?>"><?= $x->e($post->title) ?></a></h2>
<?php endif ?>
<?php endif ?>
<?= $x->render('blog::partials/kinds/' . $post->kind, ['post' => $post, 'full' => $full, 'preview' => $preview]) ?>
<?php if ($post->video !== null): ?>
<?= $x->render('blog::partials/extras/video', ['video' => $post->video]) ?>
<?php endif ?>
<?php if ($post->podcast !== null): ?>
<?= $x->render('blog::partials/extras/podcast', ['podcast' => $post->podcast]) ?>
<?php endif ?>
<?php if ($post->song !== null): ?>
<?= $x->render('blog::partials/extras/song', ['song' => $post->song]) ?>
<?php endif ?>
<footer class="post-footer">
<?php if ($preview): ?>
<p class="post-meta"><time datetime="<?= $x->e($post->publishedIso) ?>"><?= $x->e($post->publishedDisplay) ?></time></p>
<?php else: ?>
<p class="post-meta"><a class="post-permalink" href="<?= $x->e($post->url) ?>"><time datetime="<?= $x->e($post->publishedIso) ?>"><?= $x->e($post->publishedDisplay) ?></time></a></p>
<?php endif ?>
<?php if ($post->tags !== []): ?>
<?= $x->render('blog::partials/extras/tags', ['tags' => $post->tags]) ?>
<?php endif ?>
</footer>
<?php if ($full && $post->mentions !== []): ?>
<?= $x->render('blog::partials/extras/mentions', ['mentions' => $post->mentions]) ?>
<?php endif ?>
</article>
