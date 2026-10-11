<?php if ($description !== ''): ?>
<p class="blog-info-description"><?= $x->e($description) ?></p>
<?php endif ?>
<ul class="blog-info-links">
<li><a href="<?= $x->e($aboutUrl) ?>"><?= $x->e($x->t('About {title}', ['title' => $title])) ?></a></li>
<li><a href="<?= $x->e($jsonUrl) ?>" type="application/feed+json"><?= $x->e($x->t('JSON Feed')) ?></a></li>
<li><a href="<?= $x->e($rssUrl) ?>" type="application/rss+xml"><?= $x->e($x->t('RSS')) ?></a></li>
</ul>
