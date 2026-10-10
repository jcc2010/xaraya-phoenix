<h1><?= $x->e($x->t('Page not found')) ?></h1>
<?php if ($message !== $reason): ?>
<p><?= $x->e($message) ?></p>
<?php endif ?>
<p><?= $x->e($x->t('Sorry, there is nothing at this address.')) ?></p>
<p><a href="/"><?= $x->e($x->t('Go to the home page')) ?></a></p>
