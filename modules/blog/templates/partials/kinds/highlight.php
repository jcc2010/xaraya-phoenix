<?php if ($post->quote !== null): ?>
<blockquote class="post-quote"<?php if ($post->source !== null && $post->source['url'] !== null): ?> cite="<?= $x->e($post->source['url']) ?>"<?php endif ?>><p><?= $x->e($post->quote) ?></p></blockquote>
<?php elseif ($post->contentHtml !== ''): ?>
<div class="post-content"><?= $post->contentHtml ?></div>
<?php endif ?>
<?php if ($post->source !== null): ?>
<p class="post-source">— <?php if ($post->source['url'] !== null): ?><a href="<?= $x->e($post->source['url']) ?>" rel="nofollow noopener"><?= $x->e($post->source['name']) ?></a><?php else: ?><?= $x->e($post->source['name']) ?><?php endif ?></p>
<?php endif ?>
<?php if ($post->commentHtml !== null): ?>
<div class="post-comment"><?= $post->commentHtml ?></div>
<?php endif ?>
