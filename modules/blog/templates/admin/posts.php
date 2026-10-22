<?= $x->render('blog::admin/flash', ['flash' => $flash]) ?>
<h1><?= $x->e($x->t('Posts')) ?> <small><?= $x->e($blog['title']) ?></small></h1>
<?php if ($actions !== []): ?>
<p class="admin-actions"><?= $x->render('blog::admin/actions', ['actions' => $actions]) ?></p>
<?php endif ?>
<nav class="admin-tabs" aria-label="<?= $x->e($x->t('Post status')) ?>">
<?php foreach ($tabs as $tb): ?>
<a href="<?= $x->e($tb['url']) ?>"<?= $tb['current'] ? ' aria-current="page"' : '' ?>><?= $x->e($x->t($tb['label'])) ?> (<?= $x->e($tb['count']) ?>)</a>
<?php endforeach ?>
</nav>
<form class="admin-search" method="get" role="search">
<label for="admin-q"><?= $x->e($x->t('Search titles')) ?></label>
<input type="search" id="admin-q" name="q" value="<?= $x->e($q) ?>" maxlength="200">
<?php if ($tab !== 'published'): ?><input type="hidden" name="tab" value="<?= $x->e($tab) ?>"><?php endif ?>
<button type="submit"><?= $x->e($x->t('Search')) ?></button>
</form>
<?php if ($rows === []): ?>
<p class="admin-empty"><?= $x->e($x->t('No posts.')) ?></p>
<?php else: ?>
<table class="admin-posts">
<thead><tr><th scope="col"><?= $x->e($x->t('Title')) ?></th><th scope="col"><?= $x->e($x->t('Owner')) ?></th><th scope="col"><?= $x->e($x->t('Date')) ?></th><th scope="col"><?= $x->e($x->t('Status')) ?></th><th scope="col"><?= $x->e($x->t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($rows as $row): ?>
<tr id="post-<?= $x->e($row['id']) ?>">
<td class="admin-title"><?= $x->e($row['title']) ?><?php foreach ($row['tags'] as $tag): ?> <span class="admin-tag"><?= $x->e($tag) ?></span><?php endforeach ?></td>
<td class="admin-owner"><?= $x->e($row['owner']) ?></td>
<td><time datetime="<?= $x->e($row['dateIso']) ?>"><?= $x->e($row['dateDisplay']) ?></time></td>
<td><?= $x->e($x->t($row['status'])) ?></td>
<td class="admin-actions"><?= $x->render('blog::admin/actions', ['actions' => $row['actions']]) ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
<?php endif ?>
<?= $x->render('blog::partials/pager', ['pager' => $pager]) ?>
