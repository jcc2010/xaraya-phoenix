<?php $preview ??= false; $href = $post->externalUrl ?? $post->url; $rel = $post->externalUrl !== null ? ' rel="nofollow noopener"' : ''; ?>
<?php if ($preview): ?>
<h2 class="post-title"><?php if ($post->externalUrl !== null): ?><a href="<?= $x->e($href) ?>"<?= $rel ?>><?= $x->e($post->title) ?></a><?php else: ?><?= $x->e($post->title) ?><?php endif ?></h2>
<?php elseif ($full): ?>
<h1 class="post-title"><a href="<?= $x->e($href) ?>"<?= $rel ?>><?= $x->e($post->title) ?></a></h1>
<?php else: ?>
<h2 class="post-title"><a href="<?= $x->e($href) ?>"<?= $rel ?>><?= $x->e($post->title) ?></a></h2>
<?php endif ?>
<?php if ($post->source !== null): ?>
<p class="post-via"><?= $x->e($x->t('via')) ?> <?php if ($post->source['url'] !== null): ?><a href="<?= $x->e($post->source['url']) ?>" rel="nofollow noopener"><?= $x->e($post->source['name']) ?></a><?php else: ?><?= $x->e($post->source['name']) ?><?php endif ?></p>
<?php endif ?>
<?php if ($post->commentHtml !== null): ?>
<div class="post-comment"><?= $post->commentHtml ?></div>
<?php endif ?>
<?php if ($post->contentHtml !== ''): ?>
<div class="post-content"><?= $post->contentHtml ?></div>
<?php endif ?>
