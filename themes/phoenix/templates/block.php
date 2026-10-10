<section class="block <?= $x->e($typeClass) ?>"<?php if ($title): ?> aria-labelledby="block-<?= $x->e($id) ?>-title"<?php endif ?>>
<?php if ($title): ?>
<h2 class="block-title" id="block-<?= $x->e($id) ?>-title"><?= $x->e($title) ?></h2>
<?php endif ?>
<?= $content ?>
</section>
