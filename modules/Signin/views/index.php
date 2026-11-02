<?php
/** The Sign-in page. Expects $ready, $v (enabled, client_id), $errors, $problem (string|null), $origin, $secure, $noEmail. */
?>
<div class="wide-col">
<div class="page-head">
    <div>
        <h1>Sign-in</h1>
        <p class="muted">Let people sign in with a Google account instead of a password.</p>
    </div>
</div>

<?php if (!$ready): ?>
    <div class="alert alert-danger" role="alert">
        This needs a database update. Apply migration <code>015_settings.sql</code> on the
        <a href="<?= e(url('/migrate')) ?>">Migrations page</a>.
    </div>
<?php endif; ?>
<?php foreach ($errors as $err): ?>
    <div class="alert alert-danger" role="alert"><?= e($err) ?></div>
<?php endforeach; ?>
<?php if ($problem !== null): ?>
    <div class="alert alert-warning" role="status"><?= e($problem) ?></div>
<?php endif; ?>

<form method="post" action="<?= e(url('/signin')) ?>" autocomplete="off">
    <?= csrf_field() ?>
    <div class="card form-card wide">
        <h2>Sign in with Google</h2>
        <label class="check">
            <input type="checkbox" name="enabled" value="1"<?= $v['enabled'] ? ' checked' : '' ?>>
            <span><strong>Show a "Sign in with Google" button on the sign-in page</strong></span>
        </label>
        <div class="field">
            <label for="client_id">Google client ID</label>
            <input type="text" id="client_id" name="client_id" value="<?= e($v['client_id']) ?>" maxlength="150"
                   placeholder="1234567890-abcdefg.apps.googleusercontent.com" spellcheck="false" autocapitalize="off">
            <small class="muted">From the Google Cloud console (steps below). It is not a secret.</small>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"<?= $ready ? '' : ' disabled' ?>>Save</button>
        </div>
    </div>
</form>

<div class="card form-card wide section">
    <h2>How it works</h2>
    <p class="muted small">
        A person signs in with any Google account (Gmail or a school account). They get in only if exactly one user here has
        that same email address, and Google has confirmed that the address is theirs. Nobody is created automatically:
        add the address to the user first. Disabled users stay locked out, and roles and department access are unchanged.
        Passwords keep working too, which is your way in if Google is ever unavailable.
    </p>
    <?php if ($noEmail > 0): ?>
        <p class="muted small"><strong><?= e($noEmail) ?></strong> active user<?= $noEmail === 1 ? ' has' : 's have' ?> no email address and cannot use Google sign-in until one is added.</p>
    <?php endif; ?>
    <p class="muted small">
        Someone who is still on an emailed temporary password and signs in with Google no longer needs it: the temporary
        password is switched off, and "Forgot password?" can set a real one later.
    </p>
</div>

<div class="card form-card wide section">
    <h2>Setting it up in Google</h2>
    <ol class="steps">
        <li>Open <strong>Google Cloud console → APIs &amp; Services</strong> (console.cloud.google.com) and pick or create a project.</li>
        <li><strong>OAuth consent screen</strong>: choose <em>External</em> so any Google account can sign in, fill in the app name and a support email, and then
            <strong>Publish app</strong> (leave it in <em>Production</em>, not <em>Testing</em>: in Testing only a short list of named people can sign in). No scopes beyond the defaults are needed, so Google does not need to review it.</li>
        <li><strong>Credentials → Create credentials → OAuth client ID</strong>, type <em>Web application</em>.</li>
        <li>Under <strong>Authorized JavaScript origins</strong> add <code><?= e($origin) ?></code> (the address people use to open this site, with no path; add each address if there is more than one, such as a test server).
            <?php if (!$secure): ?><span class="muted"> Google only accepts <code>http://</code> origins for <code>localhost</code>; any other address must be <code>https://</code>.</span><?php endif; ?>
            No redirect URI is needed.</li>
        <li>Copy the <strong>Client ID</strong> (not the secret) into the box above, tick the checkbox and save.</li>
    </ol>
</div>
</div>
