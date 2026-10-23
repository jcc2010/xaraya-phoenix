<?= $x->render('blog::admin/flash', ['flash' => $flash]) ?>
<h1><?= $x->e($x->t('Tags')) ?> <small><?= $x->e($blog['title']) ?></small></h1>
<?php if ($mirror): ?>
<p class="admin-mirror-note"><?= $x->e($x->t('Tags come from the source feed; edit them there.')) ?></p>
<?php endif ?>
<?php if ($rows === []): ?>
<p class="admin-empty"><?= $x->e($x->t('No tags.')) ?></p>
<?php else: ?>
<div class="admin-scroll"><table class="admin-tags">
<thead><tr><th scope="col"><?= $x->e($x->t('Name')) ?></th><th scope="col"><?= $x->e($x->t('Slug')) ?></th><th scope="col"><?= $x->e($x->t('Posts')) ?></th><?php if (!$mirror): ?><th scope="col"><?= $x->e($x->t('Actions')) ?></th><?php endif ?></tr></thead>
<tbody>
<?php foreach ($rows as $row): ?>
<?php $id = 'tag-' . $row['slug']; ?>
<tr id="<?= $x->e($id) ?>">
<td><?php if ($row['publicUrl']): ?><a href="<?= $x->e($row['publicUrl']) ?>"><?= $x->e($row['name']) ?></a><?php else: ?><?= $x->e($row['name']) ?><?php endif ?></td>
<td><code><?= $x->e($row['slug']) ?></code></td>
<td><?= $x->e($row['count']) ?></td>
<?php if (!$mirror): ?>
<td class="admin-actions">
<form class="admin-tag-rename" method="post" action="<?= $x->e($row['renameUrl']) ?>">
<?= $x->csrf() ?>
<label for="<?= $x->e($id) ?>-name"><?= $x->e($x->t('Name')) ?></label>
<input id="<?= $x->e($id) ?>-name" name="name" value="<?= $x->e($row['values']['name']) ?>" maxlength="128"<?= isset($row['errors']['name']) ? ' aria-invalid="true" aria-describedby="' . $x->e($id) . '-name-error"' : '' ?>>
<?= $x->render('blog::admin/field-error', ['id' => $id . '-name', 'error' => $row['errors']['name'] ?? null]) ?>
<label for="<?= $x->e($id) ?>-slug"><?= $x->e($x->t('Slug')) ?></label>
<input id="<?= $x->e($id) ?>-slug" name="slug" value="<?= $x->e($row['values']['slug']) ?>" maxlength="128"<?= isset($row['errors']['slug']) ? ' aria-invalid="true" aria-describedby="' . $x->e($id) . '-slug-error"' : '' ?>>
<?= $x->render('blog::admin/field-error', ['id' => $id . '-slug', 'error' => $row['errors']['slug'] ?? null]) ?>
<button type="submit"><?= $x->e($x->t('Rename')) ?></button>
</form>
<?= $x->render('blog::admin/actions', ['actions' => $row['actions']]) ?>
</td>
<?php endif ?>
</tr>
<?php endforeach ?>
</tbody>
</table></div>
<?php endif ?>
<p><a href="<?= $x->e($backUrl) ?>"><?= $x->e($x->t('Back to posts')) ?></a></p>
