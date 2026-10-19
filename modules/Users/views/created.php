<?php
/**
 * Shown once, right after creating a user whose temporary password could not be emailed. Expects $username, $email,
 * $password (the temporary password) and $reason (why it was not emailed).
 */
?>
<div class="narrow-col">
<div class="page-head">
    <div>
        <h1>User created</h1>
        <p class="muted"><?= e($username) ?></p>
    </div>
</div>

<div class="alert alert-warning" role="status">
    <strong>The temporary password was not emailed.</strong> <?= e($reason) ?>
</div>

<div class="card form-card wide">
    <h2>Temporary password</h2>
    <p class="muted small">Give this to <strong><?= e($username) ?></strong> yourself. It is shown only on this page and is not stored anywhere you can look it up again.
        They must choose their own password the first time they sign in. If you lose it, open their account and set a new password.</p>
    <p class="temp-password"><code><?= e($password) ?></code></p>
</div>

<div class="form-actions">
    <a class="btn btn-primary" href="<?= e(url('/users') . $listQs) ?>">Back to users</a>
</div>
</div>
