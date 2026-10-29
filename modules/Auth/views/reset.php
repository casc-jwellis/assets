<?php /** Expects $username (string), $errors (list<string>), $min, $max (int). */ ?>
<div class="card auth-card">
    <div class="auth-brand">
        <span class="brand-mark"><?= icon('box', 22) ?></span>
        <h1>Choose a new password</h1>
        <p class="muted">For <?= e($username) ?></p>
    </div>

    <?php foreach ($errors as $err): ?>
        <div class="alert alert-danger" role="alert"><?= e($err) ?></div>
    <?php endforeach; ?>

    <form method="post" action="" autocomplete="off">
        <?= csrf_field() ?>
        <div class="field">
            <label for="new">New password</label>
            <input type="password" id="new" name="new" autocomplete="new-password" minlength="<?= e($min) ?>" maxlength="<?= e($max) ?>" required autofocus>
            <small class="muted">Between <?= e($min) ?> and <?= e($max) ?> characters.</small>
        </div>
        <div class="field">
            <label for="confirm">Confirm new password</label>
            <input type="password" id="confirm" name="confirm" autocomplete="new-password" minlength="<?= e($min) ?>" maxlength="<?= e($max) ?>" required>
        </div>
        <button type="submit" class="btn btn-primary btn-block">Change password</button>
    </form>
</div>
