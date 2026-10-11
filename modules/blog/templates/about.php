<article class="blog-about">
<h1 class="blog-title"><?= $x->e($blog['title']) ?></h1>
<?php if ($descriptionHtml !== ''): ?>
<div class="blog-description"><?= $descriptionHtml ?></div>
<?php endif ?>
<p class="blog-author"><?= $x->e($x->t('By')) ?> <?php if ($authorUrl !== null): ?><a href="<?= $x->e($authorUrl) ?>" rel="author"><?= $x->e($authorName) ?></a><?php else: ?><?= $x->e($authorName) ?><?php endif ?></p>
<h2><?= $x->e($x->t('Follow')) ?></h2>
<ul class="blog-feeds">
<li><a href="<?= $x->e($feeds['json']) ?>" type="application/feed+json"><?= $x->e($x->t('JSON Feed')) ?></a></li>
<li><a href="<?= $x->e($feeds['rss']) ?>" type="application/rss+xml"><?= $x->e($x->t('RSS')) ?></a></li>
<?php if ($subscribeUrl !== null): ?>
<li><a href="<?= $x->e($subscribeUrl) ?>"><?= $x->e($x->t('Subscribe by email')) ?></a></li>
<?php endif ?>
</ul>
</article>
