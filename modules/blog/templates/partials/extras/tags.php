<ul class="post-tags">
<?php foreach ($tags as $tag): ?>
<li><a href="<?= $x->e($tag['url']) ?>" rel="tag"><?= $x->e($tag['name']) ?></a></li>
<?php endforeach ?>
</ul>
