<?php foreach ($flash as $message): ?>
<p class="admin-flash admin-flash-<?= $x->e($message['kind']) ?>" role="<?= $message['kind'] === 'error' ? 'alert' : 'status' ?>"><?= $x->e($message['message']) ?></p>
<?php endforeach ?>
