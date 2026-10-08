<?php /** Setup form. Expects $f (values), $errors (list<string>). */ ?>
<div class="card auth-card">
    <div class="auth-brand">
        <span class="brand-mark"><?= icon('database', 22) ?></span>
        <h1>Set up Asset Manager</h1>
        <p class="muted">Enter your database details to get started.</p>
    </div>

    <?php foreach ($errors as $err): ?>
        <div class="alert alert-danger" role="alert"><?= e($err) ?></div>
    <?php endforeach; ?>

    <form method="post" action="<?= e(url('/setup.php')) ?>" autocomplete="off">
        <?= csrf_field() ?>

        <section class="form-section">
            <h2>Application</h2>
            <div class="field">
                <label for="app_name">Application name</label>
                <input type="text" id="app_name" name="app_name" value="<?= e($f['app_name']) ?>" maxlength="64" required>
            </div>
            <div class="field">
                <label for="idle_minutes">Sign users out after (minutes of inactivity)</label>
                <input type="number" id="idle_minutes" name="idle_minutes" value="<?= e($f['idle_minutes']) ?>" min="1" max="1440" required>
            </div>
        </section>

        <section class="form-section">
            <h2>Database</h2>
            <div class="field-row">
                <div class="field">
                    <label for="db_host">Host</label>
                    <input type="text" id="db_host" name="db_host" value="<?= e($f['db_host']) ?>" required>
                </div>
                <div class="field">
                    <label for="db_port">Port</label>
                    <input type="number" id="db_port" name="db_port" value="<?= e($f['db_port']) ?>" min="1" max="65535" required>
                </div>
            </div>
            <div class="field">
                <label for="db_name">Database name</label>
                <input type="text" id="db_name" name="db_name" value="<?= e($f['db_name']) ?>" required>
                <small class="muted">Created automatically if it doesn't exist (the user needs the CREATE privilege). A new, empty database is filled from <code>assets.schema.sql</code>.</small>
            </div>
            <div class="field-row even">
                <div class="field">
                    <label for="db_user">User</label>
                    <input type="text" id="db_user" name="db_user" value="<?= e($f['db_user']) ?>" autocomplete="off" required>
                </div>
                <div class="field">
                    <label for="db_pass">Password</label>
                    <input type="password" id="db_pass" name="db_pass" autocomplete="new-password">
                </div>
            </div>
            <label class="check">
                <input type="checkbox" name="migrate" value="1"<?= !empty($f['migrate']) ? ' checked' : '' ?>>
                <span>Apply database updates now (recommended; needed before passwords can be upgraded)</span>
            </label>
        </section>

        <section class="form-section">
            <h2>Administrator <span class="muted">(optional)</span></h2>
            <p class="muted small">Creates an administrator account, or promotes the existing user with that username and sets a new password. Leave blank to sign in with an existing account.</p>
            <div class="field-row even">
                <div class="field">
                    <label for="admin_user">Username</label>
                    <input type="text" id="admin_user" name="admin_user" value="<?= e($f['admin_user']) ?>" maxlength="64" autocomplete="off">
                </div>
                <div class="field">
                    <label for="admin_email">Email</label>
                    <input type="email" id="admin_email" name="admin_email" value="<?= e($f['admin_email']) ?>" maxlength="128" autocomplete="off">
                </div>
            </div>
            <div class="field-row even">
                <div class="field">
                    <label for="admin_first">First name</label>
                    <input type="text" id="admin_first" name="admin_first" value="<?= e($f['admin_first']) ?>" maxlength="32" autocomplete="off">
                </div>
                <div class="field">
                    <label for="admin_last">Last name</label>
                    <input type="text" id="admin_last" name="admin_last" value="<?= e($f['admin_last']) ?>" maxlength="32" autocomplete="off">
                </div>
            </div>
            <div class="field-row even">
                <div class="field">
                    <label for="admin_pass">Password</label>
                    <input type="password" id="admin_pass" name="admin_pass" autocomplete="new-password" minlength="10">
                </div>
                <div class="field">
                    <label for="admin_pass2">Confirm password</label>
                    <input type="password" id="admin_pass2" name="admin_pass2" autocomplete="new-password" minlength="10">
                </div>
            </div>
        </section>

        <button type="submit" class="btn btn-primary btn-block">Save and finish setup</button>
    </form>
</div>
