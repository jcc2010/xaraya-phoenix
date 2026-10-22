<?php
/** The aria-* attributes of a control whose field has an error (ids are fixed strings, not input). */
$aria = static fn(string $name, string $id): string => isset($errors[$name]) ? ' aria-invalid="true" aria-describedby="' . $id . '-error"' : '';
$error = static fn(string $name, string $id): string => $x->render('blog::admin/field-error', ['id' => $id, 'error' => $errors[$name] ?? null]);
?>
<?= $x->render('blog::admin/flash', ['flash' => $flash]) ?>
<h1><?= $x->e($x->t($heading)) ?> <small><?= $x->e($blog['title']) ?></small></h1>
<?php if ($formErrors !== []): ?>
<div class="form-error" role="alert"><ul>
<?php foreach ($formErrors as $message): ?>
<li><?= $x->e($message) ?></li>
<?php endforeach ?>
</ul></div>
<?php endif ?>
<form class="admin-form" method="post" action="<?= $x->e($action) ?>" enctype="multipart/form-data">
<?= $x->csrf() ?>
<p class="admin-field">
<label for="post-kind"><?= $x->e($x->t('Kind')) ?></label>
<select id="post-kind" name="kind"<?= $aria('kind', 'post-kind') ?>>
<?php foreach ($kinds as $kind): ?>
<option value="<?= $x->e($kind['value']) ?>"<?= $kind['value'] === $values['kind'] ? ' selected' : '' ?>><?= $x->e($x->t($kind['label'])) ?></option>
<?php endforeach ?>
</select>
<?= $error('kind', 'post-kind') ?>
</p>
<p class="admin-field">
<label for="post-title"><?= $x->e($x->t('Title')) ?></label>
<input id="post-title" name="title" value="<?= $x->e($values['title']) ?>"<?= $aria('title', 'post-title') ?>>
<?= $error('title', 'post-title') ?>
</p>
<p class="admin-field">
<label for="post-content"><?= $x->e($x->t('Content (HTML)')) ?></label>
<textarea id="post-content" name="content" rows="12"<?= $aria('content', 'post-content') ?>><?= $x->e($values['content']) ?></textarea>
<?= $error('content', 'post-content') ?>
</p>
<p class="admin-field">
<label for="post-external-url"><?= $x->e($x->t('Link URL')) ?></label>
<input id="post-external-url" name="external_url" type="url" value="<?= $x->e($values['external_url']) ?>"<?= $aria('external_url', 'post-external-url') ?>>
<?= $error('external_url', 'post-external-url') ?>
</p>
<p class="admin-field">
<label for="post-quote"><?= $x->e($x->t('Quote')) ?></label>
<textarea id="post-quote" name="quote" rows="4"<?= $aria('quote', 'post-quote') ?>><?= $x->e($values['quote']) ?></textarea>
<?= $error('quote', 'post-quote') ?>
</p>
<p class="admin-field">
<label for="post-source-name"><?= $x->e($x->t('Source name')) ?></label>
<input id="post-source-name" name="source_name" value="<?= $x->e($values['source_name']) ?>"<?= $aria('source_name', 'post-source-name') ?>>
<?= $error('source_name', 'post-source-name') ?>
</p>
<p class="admin-field">
<label for="post-source-url"><?= $x->e($x->t('Source URL')) ?></label>
<input id="post-source-url" name="source_url" type="url" value="<?= $x->e($values['source_url']) ?>"<?= $aria('source_url', 'post-source-url') ?>>
<?= $error('source_url', 'post-source-url') ?>
</p>
<p class="admin-field">
<label for="post-tags"><?= $x->e($x->t('Tags (comma-separated)')) ?></label>
<input id="post-tags" name="tags" value="<?= $x->e($values['tags']) ?>"<?= $aria('tags', 'post-tags') ?>>
<?= $error('tags', 'post-tags') ?>
</p>
<p class="admin-field">
<label for="post-date-published"><?= $x->e($x->t('Published')) ?></label>
<input id="post-date-published" name="date_published" type="datetime-local" value="<?= $x->e($values['date_published']) ?>"<?= isset($errors['date_published']) ? ' aria-invalid="true"' : '' ?> aria-describedby="post-date-published-hint<?= isset($errors['date_published']) ? ' post-date-published-error' : '' ?>">
<span class="form-hint" id="post-date-published-hint"><?= $x->e($x->t('Time zone: {tz}. A future time schedules the post.', ['tz' => $timezone])) ?></span>
<?= $error('date_published', 'post-date-published') ?>
</p>
<?php $fileDescribedBy = trim(($fileNotice !== null ? 'post-file-hint' : '') . (isset($errors['file']) ? ' post-file-error' : '')) ?>
<p class="admin-field">
<label for="post-file"><?= $x->e($x->t('Image')) ?></label>
<?php if ($image !== null): ?>
<img class="admin-image" src="<?= $x->e($image['url']) ?>" alt="">
<?php if ($image['uploadId'] !== null): ?>
<input type="hidden" name="upload_id" value="<?= $x->e($image['uploadId']) ?>">
<?php endif ?>
<?php endif ?>
<input id="post-file" name="file" type="file" accept="<?= $x->e($imageTypes) ?>"<?= isset($errors['file']) ? ' aria-invalid="true"' : '' ?><?= $fileDescribedBy !== '' ? ' aria-describedby="' . $fileDescribedBy . '"' : '' ?>>
<?php if ($fileNotice !== null): ?>
<span class="form-hint" id="post-file-hint"><?= $x->e($x->t($fileNotice)) ?></span>
<?php endif ?>
<?= $error('file', 'post-file') ?>
</p>
<?php if ($image !== null && $image['removable']): ?>
<p class="admin-field admin-check">
<input id="post-remove-image" name="remove_image" type="checkbox" value="1"<?= $values['remove_image'] ? ' checked' : '' ?>>
<label for="post-remove-image"><?= $x->e($x->t('Remove the image')) ?></label>
</p>
<?php endif ?>
<?php if ($canPin): ?>
<p class="admin-field admin-check">
<input id="post-pinned" name="pinned" type="checkbox" value="1"<?= $values['pinned'] ? ' checked' : '' ?>>
<label for="post-pinned"><?= $x->e($x->t('Pin to the top of the blog')) ?></label>
</p>
<?php endif ?>
<p class="admin-buttons"><button type="submit" name="save" value="1"><?= $x->e($x->t('Save')) ?></button> <button type="submit" name="preview" value="1"><?= $x->e($x->t('Preview')) ?></button></p>
</form>
<?php if ($preview !== null): ?>
<section class="admin-preview" aria-labelledby="preview-heading"><h2 id="preview-heading"><?= $x->e($x->t('Preview')) ?></h2>
<?= $x->render('blog::partials/post', ['post' => $preview, 'full' => true]) ?>
</section>
<?php endif ?>
