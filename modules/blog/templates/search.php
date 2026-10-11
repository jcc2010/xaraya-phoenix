<header class="blog-header">
<p class="blog-name"><?= $x->e($blog['title']) ?></p>
<h1 class="blog-title"><?= $x->e($x->t('Search')) ?></h1>
</header>
<?= $x->render('blog::partials/searchform', ['action' => $action, 'q' => $q]) ?>
<?php if ($q !== ''): ?>
<p class="search-count"><?= $x->e($total === 1 ? $x->t('1 result') : $x->t('{count} results', ['count' => $total])) ?></p>
<?php endif ?>
<?php if ($results !== []): ?>
<ol class="search-results">
<?php foreach ($results as $result): ?>
<li class="search-result">
<h2 class="search-result-title"><a href="<?= $x->e($result['url']) ?>"><?= $x->e($result['title']) ?></a></h2>
<?php if ($result['snippet'] !== ''): ?>
<p class="search-snippet"><?= $result['snippet'] ?></p>
<?php endif ?>
<p class="post-meta"><time datetime="<?= $x->e($result['publishedIso']) ?>"><?= $x->e($result['publishedDisplay']) ?></time></p>
</li>
<?php endforeach ?>
</ol>
<?php endif ?>
<?= $x->render('blog::partials/pager', ['pager' => $pager]) ?>
