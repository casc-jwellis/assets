<?php /** Expects $typed (string). */ ?>
<div class="card auth-card">
    <div class="auth-brand">
        <span class="brand-mark"><?= icon('box', 22) ?></span>
        <h1>Forgot your password?</h1>
        <p class="muted">Enter your username or email address and we will send you a link to choose a new one.</p>
    </div>

    <form method="post" action="<?= e(url('/forgot')) ?>" autocomplete="on">
        <?= csrf_field() ?>
        <div class="field">
            <label for="identifier">Username or email</label>
            <input type="text" id="identifier" name="identifier" value="<?= e($typed) ?>"
                   autocomplete="username" maxlength="128" required autofocus autocapitalize="off" spellcheck="false">
        </div>
        <button type="submit" class="btn btn-primary btn-block">Send the link</button>
    </form>
    <p class="auth-alt"><a href="<?= e(url('/login')) ?>">&larr; Back to sign in</a></p>
</div>
