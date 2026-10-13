<?php
/**
 * The Email page. Expects $ready, $v (settings), $errors, $events (key => label), $log, $testTo, $canStore,
 * $siteName, $maxRecipients.
 */
$statusClass = ['sent' => 'badge-ok', 'failed' => 'badge-danger', 'unknown' => 'badge-warn'];
?>
<div class="narrow-col" data-email-page>
<div class="page-head">
    <div>
        <h1>Email</h1>
        <p class="muted">Send a notification email when assets are added, retired or transferred.</p>
    </div>
</div>

<?php if (!$ready): ?>
    <div class="alert alert-danger" role="alert">
        Email needs a database update. Apply migrations <code>015_settings.sql</code> and <code>016_email_log.sql</code> on the
        <a href="<?= e(url('/migrate')) ?>">Migrations page</a>.
    </div>
<?php endif; ?>
<?php foreach ($errors as $err): ?>
    <div class="alert alert-danger" role="alert"><?= e($err) ?></div>
<?php endforeach; ?>
<?php if ($ready && $v['has_password'] && !$v['password_ok']): ?>
    <div class="alert alert-warning" role="status">The saved password cannot be read (the key file <code>storage/secret.key</code> is missing or was replaced). Enter the password again and save.</div>
<?php endif; ?>
<?php if ($ready && !$canStore): ?>
    <div class="alert alert-warning" role="status">The PHP <code>openssl</code> extension is not available, so a password cannot be stored and encrypted mail cannot be used.</div>
<?php endif; ?>

<form method="post" action="<?= e(url('/email')) ?>" autocomplete="off">
    <?= csrf_field() ?>

    <div class="card form-card wide">
        <h2>Notifications</h2>
        <label class="check">
            <input type="checkbox" name="enabled" value="1"<?= $v['enabled'] ? ' checked' : '' ?>>
            <span><strong>Send notification emails</strong></span>
        </label>
        <div class="field">
            <span class="label-like">Send one when</span>
            <?php foreach ($events as $key => $label): ?>
                <label class="check">
                    <input type="checkbox" name="events[<?= e($key) ?>]" value="1"<?= in_array($key, $v['events'], true) ? ' checked' : '' ?>>
                    <span><?= e($label) ?></span>
                </label>
            <?php endforeach; ?>
        </div>
        <div class="field">
            <label for="recipients">Who receives them</label>
            <textarea id="recipients" name="recipients" rows="5" spellcheck="false" placeholder="assets@example.edu&#10;it-director@example.edu"><?= e(implode("\n", $v['recipients'])) ?></textarea>
            <small class="muted">One email address per line (commas also work). Up to <?= e($maxRecipients) ?>. Everyone listed gets every notification.</small>
        </div>
    </div>

    <div class="card form-card wide section">
        <h2>Mail server (SMTP)</h2>
        <p class="muted small">
            <button type="button" class="btn-link" data-preset-gmail>Fill in Gmail settings</button>
            · Gmail does not accept your normal password here. Turn on 2-Step Verification for the sending account, create an
            <strong>app password</strong> (Google Account → Security → App passwords) and use that as the password below.
        </p>
        <div class="field-row">
            <div class="field">
                <label for="security">Connection</label>
                <select id="security" name="security">
                    <option value="starttls"<?= $v['security'] === 'starttls' ? ' selected' : '' ?>>STARTTLS (port 587, recommended)</option>
                    <option value="implicit"<?= $v['security'] === 'implicit' ? ' selected' : '' ?>>SSL/TLS (port 465)</option>
                    <option value="none"<?= $v['security'] === 'none' ? ' selected' : '' ?>>None (unencrypted; only for a relay on this same machine)</option>
                </select>
            </div>
            <div class="field">
                <label for="port">Port <span class="muted">(optional)</span></label>
                <input type="text" id="port" name="port" inputmode="numeric" value="<?= e($v['port'] ?? '') ?>" placeholder="587" maxlength="5">
            </div>
        </div>
        <div class="field">
            <label for="host">Server</label>
            <input type="text" id="host" name="host" value="<?= e($v['host']) ?>" placeholder="smtp.gmail.com" maxlength="255" autocapitalize="off" spellcheck="false">
        </div>
        <div class="field-row even">
            <div class="field">
                <label for="username">Username <span class="muted">(usually the full email address)</span></label>
                <input type="text" id="username" name="username" value="<?= e($v['username']) ?>" maxlength="255" autocapitalize="off" spellcheck="false" autocomplete="off">
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" autocomplete="new-password" maxlength="500"
                       placeholder="<?= $v['has_password'] ? '•••••••• (saved; leave blank to keep it)' : '' ?>"<?= $canStore ? '' : ' disabled' ?>>
                <small class="muted">Stored encrypted; never shown again.</small>
                <?php if ($v['has_password']): ?>
                    <label class="check"><input type="checkbox" name="clear_password" value="1"><span>Remove the saved password</span></label>
                <?php endif; ?>
            </div>
        </div>
        <div class="field-row even">
            <div class="field">
                <label for="from_address">Send from</label>
                <input type="text" id="from_address" name="from_address" value="<?= e($v['from_address']) ?>" placeholder="assets@example.edu" maxlength="254" autocapitalize="off" spellcheck="false">
                <small class="muted">With Gmail this must be the account above (or one of its aliases).</small>
            </div>
            <div class="field">
                <label for="from_name">Sender name <span class="muted">(optional)</span></label>
                <input type="text" id="from_name" name="from_name" value="<?= e($v['from_name']) ?>" placeholder="<?= e($siteName) ?>" maxlength="64">
            </div>
        </div>
    </div>

    <div class="card form-card wide section">
        <h2>Links in the emails</h2>
        <div class="field">
            <label for="base_url">Address of this site <span class="muted">(optional)</span></label>
            <input type="text" id="base_url" name="base_url" value="<?= e($v['base_url']) ?>" placeholder="https://assets.example.edu" maxlength="200" autocapitalize="off" spellcheck="false">
            <small class="muted">Used to build the links to assets. Leave blank to use the address the page was opened on.</small>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary"<?= $ready ? '' : ' disabled' ?>>Save email settings</button>
    </div>
</form>

<?php if ($ready): ?>
    <form method="post" action="<?= e(url('/email/test')) ?>" class="card form-card wide section" autocomplete="off">
        <?= csrf_field() ?>
        <h2>Send a test email</h2>
        <p class="muted small">Uses the settings as last saved. Save first if you just changed something.</p>
        <div class="field">
            <label for="test_to">Send the test to</label>
            <input type="text" id="test_to" name="test_to" value="<?= e($testTo) ?>" maxlength="254" autocapitalize="off" spellcheck="false" required>
        </div>
        <button type="submit" class="btn">Send test email</button>
    </form>

    <div class="card section">
        <div class="card-head"><h2>Recent emails</h2></div>
        <?php if ($log === []): ?>
            <p class="empty">Nothing sent yet.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>When</th><th>Subject</th><th>To</th><th>Result</th></tr></thead>
                    <tbody>
                    <?php foreach ($log as $row): ?>
                        <tr>
                            <td class="nowrap"><?= e(substr((string) $row['created_date'], 0, 16)) ?></td>
                            <td><?= e($row['subject']) ?></td>
                            <?php $to = explode(', ', (string) $row['recipients']); ?>
                            <td class="log-to" title="<?= e($row['recipients']) ?>"><?= e($to[0]) ?><?= count($to) > 1 ? ' <span class="muted">+' . e(count($to) - 1) . ' more</span>' : '' ?></td>
                            <td>
                                <span class="badge <?= e($statusClass[$row['status']] ?? '') ?>"><?= e($row['status']) ?></span>
                                <?php if ($row['detail'] !== ''): ?><div class="muted small"><?= e($row['detail']) ?></div><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="muted small more-note">"Sent" means the mail server accepted it, not that it reached an inbox. The most recent 500 are kept.</p>
        <?php endif; ?>
    </div>
<?php endif; ?>
</div>
<script src="<?= e(asset('js/email.js')) ?>" defer></script>
