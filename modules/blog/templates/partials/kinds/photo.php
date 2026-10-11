<?php if (count($post->images) > 1): ?>
<?= $x->render('blog::partials/extras/gallery', ['images' => $post->images]) ?>
<?php elseif ($post->images !== []): ?>
<?php $photo = $post->images[0]; ?>
<figure class="post-photo"><img src="<?= $x->e($photo['url']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer"<?php if ($photo['width'] !== null && $photo['height'] !== null): ?> width="<?= $x->e($photo['width']) ?>" height="<?= $x->e($photo['height']) ?>"<?php endif ?>></figure>
<?php elseif ($post->image !== null): ?>
<figure class="post-photo"><img src="<?= $x->e($post->image) ?>" alt="" loading="lazy" referrerpolicy="no-referrer"></figure>
<?php endif ?>
<?php if ($post->contentHtml !== ''): ?>
<div class="post-content"><?= $post->contentHtml ?></div>
<?php endif ?>
