<form class="search-form" role="search" method="get" action="<?= $x->e($action) ?>">
<label><span class="visually-hidden"><?= $x->e($x->t('Search this blog')) ?></span><input type="search" name="q" value="<?= $x->e($q) ?>" maxlength="200" required></label>
<button type="submit"><?= $x->e($x->t('Search')) ?></button>
</form>
