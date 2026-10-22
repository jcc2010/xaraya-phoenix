<h1><?= $x->e($x->t('Forgot your password?')) ?></h1>
<?php if ($sent): ?>
<p class="form-notice" role="status"><?= $x->e($x->t('If an account uses that address, we have sent it a link to choose a new password. The link works for 60 minutes.')) ?></p>
<?php else: ?>
<p><?= $x->e($x->t('Enter the email address of your account and we will send you a link to choose a new password.')) ?></p>
<form class="auth-form" method="post" action="<?= $x->e($x->url('auth.forgot.submit')) ?>">
<?= $x->csrf() ?>
<p>
<label for="email"><?= $x->e($x->t('Email')) ?></label>
<input id="email" name="email" type="email" autocomplete="email" required>
</p>
<p><button type="submit"><?= $x->e($x->t('Send the link')) ?></button></p>
</form>
<?php endif ?>
<p><a href="<?= $x->e($x->url('auth.login')) ?>"><?= $x->e($x->t('Back to log in')) ?></a></p>
