<?php /**
 * Expects $username (string), $error (string|null), $canReset (bool: show "Forgot password?"), $googleClientId
 * (string|null: show Google's button) and $googleNonce (string, goes inside the signed token Google returns).
 */ ?>
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

    <?php if (!empty($googleClientId)): ?>
        <div class="auth-or"><span>or</span></div>
        <?php /* Google's script turns these two divs into the button; when it is used, app.js posts the signed token to /login/google. */ ?>
        <div class="google-signin">
            <div id="g_id_onload" data-client_id="<?= e($googleClientId) ?>" data-callback="assetsGoogleSignIn"
                 data-nonce="<?= e($googleNonce) ?>" data-auto_prompt="false"></div>
            <div class="g_id_signin" data-type="standard" data-theme="outline" data-size="large" data-shape="rectangular"
                 data-text="signin_with" data-logo_alignment="left" data-width="280"></div>
        </div>
        <form method="post" action="<?= e(url('/login/google')) ?>" id="google-form" hidden>
            <?= csrf_field() ?>
            <input type="hidden" name="credential" value="">
        </form>
        <script src="https://accounts.google.com/gsi/client" async></script>
    <?php endif; ?>
</div>
