<?= $x->render('blog::admin/flash', ['flash' => $flash]) ?>
<h1><?= $x->e($x->t('Blog admin')) ?></h1>
<?php if ($blogs === []): ?>
<p class="admin-empty"><?= $x->e($x->t('No blogs yet.')) ?></p>
<?php else: ?>
<table class="admin-blogs">
<thead><tr><th scope="col"><?= $x->e($x->t('Blog')) ?></th><th scope="col"><?= $x->e($x->t('Mode')) ?></th><th scope="col"><?= $x->e($x->t('Posts')) ?></th><th scope="col"><?= $x->e($x->t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($blogs as $b): ?>
<tr id="blog-<?= $x->e($b['handle']) ?>">
<td><strong><?= $x->e($b['title']) ?></strong> <code><?= $x->e($b['handle']) ?></code></td>
<td><?= $x->e($b['mode']) ?></td>
<td><?= $x->e($x->t('{n} published', ['n' => $b['counts']['published']])) ?>, <?= $x->e($x->t('{n} scheduled', ['n' => $b['counts']['scheduled']])) ?>, <?= $x->e($x->t('{n} deleted', ['n' => $b['counts']['deleted']])) ?></td>
<td class="admin-actions">
<a href="<?= $x->e($b['publicUrl']) ?>"><?= $x->e($x->t('View blog')) ?></a>
<?php if ($b['postsUrl']): ?> <a href="<?= $x->e($b['postsUrl']) ?>"><?= $x->e($x->t('Posts')) ?></a><?php endif ?>
<?php if ($b['newPostUrl']): ?> <a href="<?= $x->e($b['newPostUrl']) ?>"><?= $x->e($x->t('New post')) ?></a><?php endif ?>
<?php if ($b['settingsUrl']): ?> <a href="<?= $x->e($b['settingsUrl']) ?>"><?= $x->e($x->t('Settings')) ?></a><?php endif ?>
</td>
</tr>
<?php endforeach ?>
</tbody>
</table>
<?php endif ?>
<?php if ($newBlogUrl): ?>
<p><a href="<?= $x->e($newBlogUrl) ?>"><?= $x->e($x->t('New blog')) ?></a></p>
<?php endif ?>
