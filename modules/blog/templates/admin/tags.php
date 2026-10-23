<?= $x->render('blog::admin/flash', ['flash' => $flash]) ?>
<h1><?= $x->e($x->t('Tags')) ?> <small><?= $x->e($blog['title']) ?></small></h1>
<?php if ($mirror): ?>
<p class="admin-mirror-note"><?= $x->e($x->t('Tags come from the source feed; edit them there.')) ?></p>
<?php endif ?>
<?php if ($rows === []): ?>
<p class="admin-empty"><?= $x->e($x->t('No tags.')) ?></p>
<?php else: ?>
<?php $format = $x->t('Showing %shown% of %total% tags.'); ?>
<form class="admin-search admin-tag-filter" method="get" role="search">
<label for="tag-filter"><?= $x->e($x->t('Filter tags')) ?></label>
<input type="search" id="tag-filter" name="q" value="<?= $x->e($q) ?>" maxlength="128" autocomplete="off" spellcheck="false" aria-describedby="tag-filter-count" aria-keyshortcuts="/" data-tag-filter>
<button type="submit"><?= $x->e($x->t('Filter')) ?></button>
<a href="<?= $x->e($clearUrl) ?>" data-tag-clear<?= $q === '' ? ' hidden' : '' ?>><?= $x->e($x->t('Clear')) ?></a>
<span id="tag-filter-count" class="admin-filter-count" role="status" data-format="<?= $x->e($format) ?>"><?= $q === '' ? '' : $x->e(strtr($format, ['%shown%' => (string) $shown, '%total%' => (string) count($rows)])) ?></span>
</form>
<p class="admin-empty" data-tag-empty<?= $shown === 0 ? '' : ' hidden' ?>><?= $x->e($x->t('No tags match the filter.')) ?></p>
<div class="admin-scroll" data-tag-table<?= $shown === 0 ? ' hidden' : '' ?>><table class="admin-tags">
<colgroup><col class="admin-col-name"><col class="admin-col-slug"><col class="admin-col-count"><?php if (!$mirror): ?><col><?php endif ?></colgroup>
<thead><tr><th scope="col"><?= $x->e($x->t('Name')) ?></th><th scope="col"><?= $x->e($x->t('Slug')) ?></th><th scope="col"><?= $x->e($x->t('Posts')) ?></th><?php if (!$mirror): ?><th scope="col"><?= $x->e($x->t('Actions')) ?></th><?php endif ?></tr></thead>
<tbody>
<?php foreach ($rows as $row): ?>
<?php $id = 'tag-' . $row['slug']; ?>
<tr id="<?= $x->e($id) ?>" data-tag-row data-name="<?= $x->e($row['name']) ?>" data-slug="<?= $x->e($row['slug']) ?>"<?= $row['changed'] ? ' class="admin-row-changed"' : '' ?><?= $row['hidden'] ? ' hidden' : '' ?>>
<td class="admin-tag-name"><?php if ($row['publicUrl']): ?><a href="<?= $x->e($row['publicUrl']) ?>"><?= $x->e($row['name']) ?></a><?php else: ?><?= $x->e($row['name']) ?><?php endif ?></td>
<td class="admin-tag-slug" data-label="<?= $x->e($x->t('Slug')) ?>"><code><?= $x->e($row['slug']) ?></code></td>
<td class="admin-tag-count" data-label="<?= $x->e($x->t('Posts')) ?>"><?= $x->e($row['count']) ?></td>
<?php if (!$mirror): ?>
<td class="admin-actions"><div class="admin-tag-actions">
<form class="admin-tag-rename" method="post" action="<?= $x->e($row['renameUrl']) ?>">
<?= $x->csrf() ?>
<?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= $x->e($q) ?>"><?php endif ?>
<label for="<?= $x->e($id) ?>-name"><?= $x->e($x->t('Name')) ?></label>
<input id="<?= $x->e($id) ?>-name" name="name" value="<?= $x->e($row['values']['name']) ?>" maxlength="128"<?= isset($row['errors']['name']) ? ' aria-invalid="true" aria-describedby="' . $x->e($id) . '-name-error"' : '' ?>>
<?= $x->render('blog::admin/field-error', ['id' => $id . '-name', 'error' => $row['errors']['name'] ?? null]) ?>
<label for="<?= $x->e($id) ?>-slug"><?= $x->e($x->t('Slug')) ?></label>
<input id="<?= $x->e($id) ?>-slug" name="slug" value="<?= $x->e($row['values']['slug']) ?>" maxlength="128"<?= isset($row['errors']['slug']) ? ' aria-invalid="true" aria-describedby="' . $x->e($id) . '-slug-error"' : '' ?>>
<?= $x->render('blog::admin/field-error', ['id' => $id . '-slug', 'error' => $row['errors']['slug'] ?? null]) ?>
<button type="submit"><?= $x->e($x->t('Rename')) ?></button>
</form>
<?= $x->render('blog::admin/actions', ['actions' => $row['actions']]) ?>
</div></td>
<?php endif ?>
</tr>
<?php endforeach ?>
</tbody>
</table></div>
<?php endif ?>
<p><a href="<?= $x->e($backUrl) ?>"><?= $x->e($x->t('Back to posts')) ?></a></p>
