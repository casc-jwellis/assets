<?php /** Expects $errors (list<string>), $min (int). */ ?>
<div class="page-head">
    <div>
        <h1>Change password</h1>
        <p class="muted">Signed in as <?= e(current_user()['username']) ?></p>
    </div>
</div>

<div class="card form-card">
    <?php foreach ($errors as $err): ?>
        <div class="alert alert-danger" role="alert"><?= e($err) ?></div>
    <?php endforeach; ?>

    <form method="post" action="<?= e(url('/account/password')) ?>">
        <?= csrf_field() ?>
        <div class="field">
            <label for="current">Current password</label>
            <input type="password" id="current" name="current" autocomplete="current-password" required>
        </div>
        <div class="field">
            <label for="new">New password</label>
            <input type="password" id="new" name="new" autocomplete="new-password" minlength="<?= e($min) ?>" required>
            <small class="muted">At least <?= e($min) ?> characters.</small>
        </div>
        <div class="field">
            <label for="confirm">Confirm new password</label>
            <input type="password" id="confirm" name="confirm" autocomplete="new-password" minlength="<?= e($min) ?>" required>
        </div>
        <button type="submit" class="btn btn-primary">Update password</button>
    </form>
</div>
