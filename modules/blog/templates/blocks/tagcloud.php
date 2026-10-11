<?= $x->render('blog::partials/tagcloud', ['tags' => $tags]) ?>
<p class="blog-tags-all"><a href="<?= $x->e($allUrl) ?>"><?= $x->e($x->t('All tags')) ?></a></p>
