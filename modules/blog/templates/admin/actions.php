<?php foreach ($actions as $action): ?>
<?php if ($action['method'] === 'post'): ?>
<form class="admin-action" method="post" action="<?= $x->e($action['url']) ?>">
<?= $action['csrf'] ? $x->csrf() : '' ?>
<?php foreach ($action['fields'] as $name => $value): ?>
<input type="hidden" name="<?= $x->e($name) ?>" value="<?= $x->e($value) ?>">
<?php endforeach ?>
<button type="submit"><?= $x->e($x->t($action['label'])) ?></button>
</form>
<?php else: ?>
<a href="<?= $x->e($action['url']) ?>"><?= $x->e($x->t($action['label'])) ?></a>
<?php endif ?>
<?php endforeach ?>
