<h1><?= $x->e($x->t('Greetings')) ?></h1>
<?php if ($greetings === []): ?>
<p><?= $x->e($x->t('Nobody has been greeted yet.')) ?></p>
<?php else: ?>
<ul class="greetings">
<?php foreach ($greetings as $greeting): ?>
<?= $x->include('hello::partials/greeting', ['greeting' => $greeting]) ?>
<?php endforeach ?>
</ul>
<?php endif ?>
