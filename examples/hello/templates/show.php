<article class="greeting">
<h1><?= $x->e($x->t('Hello, {name}!', ['name' => $greeting['name']])) ?></h1>
<p><time datetime="<?= $x->e($greeting['created']) ?>"><?= $x->e($greeting['created']) ?></time></p>
<?= $x->hooks('item.display', ['module' => 'hello', 'itemtype' => 'greeting', 'id' => $greeting['id'], 'name' => $greeting['name']]) ?>
<p><a href="<?= $x->e($x->url('hello.index')) ?>"><?= $x->e($x->t('All greetings')) ?></a></p>
</article>
