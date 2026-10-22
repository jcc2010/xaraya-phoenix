<h1><?= $x->e($x->t('Choose a new password')) ?></h1>
<?php if (!$valid): ?>
<p class="form-error" role="alert"><?= $x->e($x->t('This link has expired or has already been used.')) ?></p>
<p><a href="<?= $x->e($x->url('auth.forgot')) ?>"><?= $x->e($x->t('Ask for a new link')) ?></a></p>
<?php else: ?>
<?php if ($error): ?>
<p class="form-error" id="reset-error" role="alert"><?= $x->e($x->t($error)) ?></p>
<?php endif ?>
<form class="auth-form" method="post" action="<?= $x->e($x->url('auth.reset.submit', ['token' => $token])) ?>">
<?= $x->csrf() ?>
<p>
<label for="password"><?= $x->e($x->t('New password')) ?></label>
<input id="password" name="password" type="password" autocomplete="new-password" minlength="8" required aria-describedby="password-hint<?= $error ? ' reset-error' : '' ?>">
<span class="form-hint" id="password-hint"><?= $x->e($x->t('At least 8 characters.')) ?></span>
</p>
<p>
<label for="password_confirmation"><?= $x->e($x->t('Repeat the new password')) ?></label>
<input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="8" required>
</p>
<p><button type="submit"><?= $x->e($x->t('Change the password')) ?></button></p>
</form>
<?php endif ?>
