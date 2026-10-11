<div class="gallery">
<?php foreach ($images as $image): ?>
<img src="<?= $x->e($image['url']) ?>" alt="" loading="lazy"<?php if ($image['width'] !== null && $image['height'] !== null): ?> width="<?= $x->e($image['width']) ?>" height="<?= $x->e($image['height']) ?>"<?php endif ?>>
<?php endforeach ?>
</div>
