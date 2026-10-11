<ul class="blog-recent">
<?php foreach ($posts as $post): ?>
<li><a href="<?= $x->e($post['url']) ?>"><?= $x->e($post['title']) ?></a> <time datetime="<?= $x->e($post['publishedIso']) ?>"><?= $x->e($post['publishedDisplay']) ?></time></li>
<?php endforeach ?>
</ul>
