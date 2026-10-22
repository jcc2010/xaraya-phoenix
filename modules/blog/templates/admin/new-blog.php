<?php
$aria = static fn(string $name, string $id): string => isset($errors[$name]) ? ' aria-invalid="true" aria-describedby="' . $id . '-error"' : '';
$error = static fn(string $name, string $id): string => $x->render('blog::admin/field-error', ['id' => $id, 'error' => $errors[$name] ?? null]);
?>
<?= $x->render('blog::admin/flash', ['flash' => $flash]) ?>
<h1><?= $x->e($x->t('New blog')) ?></h1>
<?php if ($formErrors !== []): ?>
<div class="form-error" role="alert"><ul>
<?php foreach ($formErrors as $message): ?>
<li><?= $x->e($message) ?></li>
<?php endforeach ?>
</ul></div>
<?php endif ?>
<form class="admin-form" method="post" action="<?= $x->e($action) ?>">
<?= $x->csrf() ?>
<p class="admin-field">
<label for="blog-handle"><?= $x->e($x->t('Handle')) ?></label>
<input id="blog-handle" name="handle" value="<?= $x->e($values['handle']) ?>" pattern="[a-z0-9_-]{2,32}" required<?= $aria('handle', 'blog-handle') ?>>
<?= $error('handle', 'blog-handle') ?>
</p>
<p class="admin-field">
<label for="blog-title"><?= $x->e($x->t('Title')) ?></label>
<input id="blog-title" name="title" value="<?= $x->e($values['title']) ?>" required<?= $aria('title', 'blog-title') ?>>
<?= $error('title', 'blog-title') ?>
</p>
<p class="admin-mode"><?= $x->e($x->t('Mode: native. Mirror blogs are created with')) ?> <code>bin/xar blog:create</code>.</p>
<p class="admin-buttons"><button type="submit"><?= $x->e($x->t('Create blog')) ?></button></p>
</form>
