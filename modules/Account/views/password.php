<?php /** Expects $errors (list<string>), $min (int), $forced (bool: signed in with a temporary password). */ ?>
<div class="page-head">
    <div>
        <h1><?= $forced ? 'Choose a new password' : 'Change password' ?></h1>
        <p class="muted">Signed in as <?= e(current_user()['username']) ?></p>
    </div>
</div>

<?php if ($forced): ?>
    <div class="alert alert-warning" role="status">
        You signed in with a temporary password. Choose your own now; nothing else is available until you do.
    </div>
<?php endif; ?>

<div class="card form-card">
    <?php foreach ($errors as $err): ?>
        <div class="alert alert-danger" role="alert"><?= e($err) ?></div>
    <?php endforeach; ?>

    <form method="post" action="<?= e(url('/account/password')) ?>">
        <?= csrf_field() ?>
        <div class="field">
            <label for="current"><?= $forced ? 'Temporary password' : 'Current password' ?></label>
            <input type="password" id="current" name="current" autocomplete="current-password" required<?= $forced ? ' autofocus' : '' ?>>
            <?php if ($forced): ?><small class="muted">The one you were given (by email, or in person).</small><?php endif; ?>
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
