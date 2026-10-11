<section class="post-mentions">
<h2><?= $x->e($x->t('Mentions')) ?></h2>
<ul>
<?php foreach ($mentions as $mention): ?>
<li><a href="<?= $x->e($mention['source']) ?>" rel="nofollow noopener ugc"><?= $x->e($mention['title']) ?></a><?php if ($mention['date'] !== null): ?> <span class="mention-date"><?= $x->e($mention['date']) ?></span><?php endif ?></li>
<?php endforeach ?>
</ul>
</section>
