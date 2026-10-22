<h1><?= $x->e($x->t('Log in')) ?></h1>
<?php if ($notice): ?>
<p class="form-notice" role="status"><?= $x->e($x->t($notice)) ?></p>
<?php endif ?>
<?php if ($error): ?>
<p class="form-error" id="login-error" role="alert"><?= $x->e($x->t($error, ['minutes' => $minutes])) ?></p>
<?php endif ?>
<form class="auth-form" method="post" action="<?= $x->e($x->url('auth.login.submit')) ?>">
<?= $x->csrf() ?>
<input type="hidden" name="next" value="<?= $x->e($next) ?>">
<p>
<label for="login"><?= $x->e($x->t('Handle or email')) ?></label>
<input id="login" name="login" value="<?= $x->e($login) ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required<?php if ($error): ?> aria-invalid="true" aria-describedby="login-error"<?php endif ?>>
</p>
<p>
<label for="password"><?= $x->e($x->t('Password')) ?></label>
<input id="password" name="password" type="password" autocomplete="current-password" required>
</p>
<p><button type="submit"><?= $x->e($x->t('Log in')) ?></button></p>
</form>
<p><a href="<?= $x->e($x->url('auth.forgot')) ?>"><?= $x->e($x->t('Forgot your password?')) ?></a></p>
