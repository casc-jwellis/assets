<?php /** Expects $username (string), $error (string|null), $canReset (bool: show "Forgot password?"). */ ?>
<div class="card auth-card">
    <div class="auth-brand">
        <span class="brand-mark"><?= icon('box', 22) ?></span>
        <h1><?= e(app_name()) ?></h1>
        <p class="muted">Sign in to continue</p>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/login')) ?>" autocomplete="on">
        <?= csrf_field() ?>
        <div class="field">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" value="<?= e($username) ?>"
                   autocomplete="username" maxlength="64" required autofocus>
        </div>
        <div class="field">
            <label for="password">Password</label>
            <input type="password" id="password" name="password"
                   autocomplete="current-password" maxlength="1024" required>
        </div>
        <button type="submit" class="btn btn-primary btn-block">Sign in</button>
    </form>
    <?php if (!empty($canReset)): ?>
        <p class="auth-alt"><a href="<?= e(url('/forgot')) ?>">Forgot password?</a></p>
    <?php endif; ?>
</div>
