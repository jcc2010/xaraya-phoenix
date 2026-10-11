<ul class="tag-cloud">
<?php foreach ($tags as $tag): ?>
<li class="tag-step-<?= $x->e($tag['step']) ?>"><a href="<?= $x->e($tag['url']) ?>" rel="tag"><?= $x->e($tag['name']) ?></a> <span class="tag-count"><?= $x->e($tag['count']) ?></span></li>
<?php endforeach ?>
</ul>
