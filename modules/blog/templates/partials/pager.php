<?php if ($pager['prev'] !== null || $pager['next'] !== null): ?>
<nav class="pager" aria-label="<?= $x->e($x->t('Pages')) ?>">
<?php if ($pager['prev'] !== null): ?>
<a class="pager-prev" href="<?= $x->e($pager['prev']) ?>"><?= $x->e($x->t($pager['prevLabel'])) ?></a>
<?php endif ?>
<?php if ($pager['next'] !== null): ?>
<a class="pager-next" rel="next" href="<?= $x->e($pager['next']) ?>"><?= $x->e($x->t($pager['nextLabel'])) ?></a>
<?php endif ?>
</nav>
<?php endif ?>
