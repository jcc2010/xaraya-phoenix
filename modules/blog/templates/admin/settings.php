<?php
$aria = static fn(string $name, string $id): string => isset($errors[$name]) ? ' aria-invalid="true" aria-describedby="' . $id . '-error"' : '';
$error = static fn(string $name, string $id): string => $x->render('blog::admin/field-error', ['id' => $id, 'error' => $errors[$name] ?? null]);
?>
<?= $x->render('blog::admin/flash', ['flash' => $flash]) ?>
<h1><?= $x->e($x->t('Settings')) ?> <small><?= $x->e($blog['title']) ?></small></h1>
<?php if ($formErrors !== []): ?>
<div class="form-error" role="alert"><ul>
<?php foreach ($formErrors as $message): ?>
<li><?= $x->e($message) ?></li>
<?php endforeach ?>
</ul></div>
<?php endif ?>
<p class="admin-mode"><?= $x->e($x->t('Mode: {mode}. Change it with', ['mode' => $blog['mode']])) ?> <code>bin/xar blog:mode <?= $x->e($blog['handle']) ?> mirror|native</code>.</p>
<?php if ($mirror): ?>
<p class="form-notice"><?= $x->e($x->t('The source supplies the title, description, author and language; a sync overwrites them, so they cannot be edited here.')) ?></p>
<?php endif ?>
<form class="admin-form" method="post" action="<?= $x->e($action) ?>">
<?= $x->csrf() ?>
<p class="admin-field">
<label for="blog-title"><?= $x->e($x->t('Title')) ?></label>
<input id="blog-title" name="title" value="<?= $x->e($values['title']) ?>"<?= $aria('title', 'blog-title') ?><?= $mirror && in_array('title', $synced, true) ? ' disabled' : '' ?>>
<?= $error('title', 'blog-title') ?>
</p>
<p class="admin-field">
<label for="blog-description-html"><?= $x->e($x->t('Description (HTML)')) ?></label>
<textarea id="blog-description-html" name="description_html" rows="6"<?= $aria('description_html', 'blog-description-html') ?><?= $mirror && in_array('description_html', $synced, true) ? ' disabled' : '' ?>><?= $x->e($values['description_html']) ?></textarea>
<?= $error('description_html', 'blog-description-html') ?>
</p>
<p class="admin-field">
<label for="blog-author-name"><?= $x->e($x->t('Author name')) ?></label>
<input id="blog-author-name" name="author_name" value="<?= $x->e($values['author_name']) ?>"<?= $aria('author_name', 'blog-author-name') ?><?= $mirror && in_array('author_name', $synced, true) ? ' disabled' : '' ?>>
<?= $error('author_name', 'blog-author-name') ?>
</p>
<p class="admin-field">
<label for="blog-author-url"><?= $x->e($x->t('Author URL')) ?></label>
<input id="blog-author-url" name="author_url" value="<?= $x->e($values['author_url']) ?>"<?= $aria('author_url', 'blog-author-url') ?><?= $mirror && in_array('author_url', $synced, true) ? ' disabled' : '' ?>>
<?= $error('author_url', 'blog-author-url') ?>
</p>
<p class="admin-field">
<label for="blog-language"><?= $x->e($x->t('Language')) ?></label>
<input id="blog-language" name="language" value="<?= $x->e($values['language']) ?>"<?= $aria('language', 'blog-language') ?><?= $mirror && in_array('language', $synced, true) ? ' disabled' : '' ?>>
<?= $error('language', 'blog-language') ?>
</p>
<p class="admin-field">
<label for="blog-post-url-pattern"><?= $x->e($x->t('Post URL pattern')) ?></label>
<input id="blog-post-url-pattern" name="post_url_pattern" value="<?= $x->e($values['post_url_pattern']) ?>"<?= $aria('post_url_pattern', 'blog-post-url-pattern') ?><?= $mirror && in_array('post_url_pattern', $synced, true) ? ' disabled' : '' ?>>
<?= $error('post_url_pattern', 'blog-post-url-pattern') ?>
</p>
<p class="admin-field">
<label for="blog-media"><?= $x->e($x->t('Media')) ?></label>
<select id="blog-media" name="media"<?= $aria('media', 'blog-media') ?>>
<?php foreach ($media as $m): ?>
<option value="<?= $x->e($m) ?>"<?= $m === $values['media'] ? ' selected' : '' ?>><?= $x->e($m) ?></option>
<?php endforeach ?>
</select>
<?= $error('media', 'blog-media') ?>
</p>
<p class="admin-buttons"><button type="submit"><?= $x->e($x->t('Save')) ?></button> <a href="<?= $x->e($backUrl) ?>"><?= $x->e($x->t('Back to posts')) ?></a></p>
</form>
