<h1><?= $x->e($status) ?> <?= $x->e($reason) ?></h1>
<?php if ($message !== $reason): ?>
<p><?= $x->e($message) ?></p>
<?php endif ?>
<p><a href="/"><?= $x->e($x->t('Go to the home page')) ?></a></p>
