<?php
/**
 * Create/edit form. Expects $isNew, $isSelf, $user (row|null), $f (field values), $perms (dept_id => 'r'|'rw'),
 * $errors, $departments, $timezones, $canDisable, $minPassword.
 */
$action = ($isNew ? url('/users/new') : url('/users/' . rawurlencode((string) $f['user_id']))) . $listQs;
$back = url('/users') . $listQs;
$never = $user === null || $user['lastlogin'] === null || str_starts_with((string) $user['lastlogin'], '0000') || str_starts_with((string) $user['lastlogin'], '1970-01-01');
?>
<div class="narrow-col">
<div class="page-head">
    <div>
        <a class="back-link" href="<?= e($back) ?>">&larr; <?= $listQs !== '' ? 'Back to results' : 'All users' ?></a>
        <h1><?= $isNew ? 'New user' : e($f['username']) ?></h1>
        <?php if (!$isNew): ?>
            <p class="muted">User ID <?= e($f['user_id']) ?> · Last sign-in <?= $never ? 'never' : e(fmt_date($user['lastlogin'])) ?></p>
        <?php endif; ?>
    </div>
    <?php if (!$isNew && $canDisable): ?>
        <?php /* Separate forms (they cannot nest inside the edit form below). Both are reversible, so no confirmation page. */ ?>
        <div class="page-actions">
            <?php if ($f['disabled']): ?>
                <form method="post" action="<?= e(url('/users/' . rawurlencode((string) $f['user_id']) . '/enable') . $listQs) ?>" class="inline-form">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-primary">Re-enable user</button>
                </form>
            <?php elseif ($isSelf): ?>
                <span class="muted small">You cannot disable your own account.</span>
            <?php else: ?>
                <form method="post" action="<?= e(url('/users/' . rawurlencode((string) $f['user_id']) . '/disable') . $listQs) ?>" class="inline-form">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn" title="Stop this person signing in. Their account, permissions and history are kept.">Disable user</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php if (!$isNew && $f['disabled']): ?>
    <div class="alert alert-warning" role="status">
        <strong>This user is disabled</strong> and cannot sign in. Their account, department access and history are kept; re-enable them to restore access.
    </div>
<?php endif; ?>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-danger" role="alert"><?= e($err) ?></div>
<?php endforeach; ?>

<form method="post" action="<?= e($action) ?>" autocomplete="off">
    <?= csrf_field() ?>

    <div class="card form-card wide">
        <h2>Account</h2>
        <div class="field-row even">
            <div class="field">
                <label for="user_id">User ID</label>
                <?php if ($isNew): ?>
                    <input type="text" id="user_id" name="user_id" value="<?= e($f['user_id']) ?>" maxlength="16" required>
                    <small class="muted">Up to 16 characters. Cannot be changed later.</small>
                <?php else: ?>
                    <input type="text" id="user_id" value="<?= e($f['user_id']) ?>" disabled>
                <?php endif; ?>
            </div>
            <div class="field">
                <label for="username">Username (used to sign in)</label>
                <input type="text" id="username" name="username" value="<?= e($f['username']) ?>" maxlength="64" required>
            </div>
        </div>
        <div class="field-row even">
            <div class="field">
                <label for="firstname">First name</label>
                <input type="text" id="firstname" name="firstname" value="<?= e($f['firstname']) ?>" maxlength="32">
            </div>
            <div class="field">
                <label for="lastname">Last name</label>
                <input type="text" id="lastname" name="lastname" value="<?= e($f['lastname']) ?>" maxlength="32">
            </div>
        </div>
        <div class="field">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="<?= e($f['email']) ?>" maxlength="128">
        </div>
        <div class="field-row even">
            <div class="field">
                <label for="department_id">Home department</label>
                <select id="department_id" name="department_id">
                    <option value="0"<?= (int) $f['department_id'] === 0 ? ' selected' : '' ?>>None</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= e($d['department_id']) ?>"<?= (int) $f['department_id'] === (int) $d['department_id'] ? ' selected' : '' ?>>
                            <?= e($d['abbr']) ?> — <?= e($d['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="timezone">Time zone</label>
                <select id="timezone" name="timezone">
                    <?php foreach ($timezones as $tz): ?>
                        <option value="<?= e($tz) ?>"<?= $f['timezone'] === $tz ? ' selected' : '' ?>><?= e($tz) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <label class="check">
            <input type="checkbox" name="admin" value="1"<?= $f['admin'] ? ' checked' : '' ?><?= $isSelf ? ' disabled' : '' ?>>
            <span><strong>Administrator</strong>: full access to every department, users and migrations<?= $isSelf ? ' (you cannot change your own)' : '' ?></span>
        </label>
    </div>

    <div class="card form-card wide section">
        <h2><?= $isNew ? 'Password' : 'Reset password' ?></h2>
        <?php if (!$isNew): ?>
            <p class="muted small">Leave blank to keep the current password. Tell the person their new password in person; they can change it from the Account page.</p>
        <?php endif; ?>
        <div class="field-row even">
            <div class="field">
                <label for="password"><?= $isNew ? 'Password' : 'New password' ?></label>
                <input type="password" id="password" name="password" autocomplete="new-password" minlength="<?= e($minPassword) ?>"<?= $isNew ? ' required' : '' ?>>
                <small class="muted">At least <?= e($minPassword) ?> characters.</small>
            </div>
            <div class="field">
                <label for="password2">Confirm password</label>
                <input type="password" id="password2" name="password2" autocomplete="new-password" minlength="<?= e($minPassword) ?>"<?= $isNew ? ' required' : '' ?>>
            </div>
        </div>
    </div>

    <div class="card form-card wide section">
        <div class="card-head"><h2>Department access</h2></div>
        <p class="muted small perm-note">Controls which departments' assets this person can see (Read) and change (Read &amp; write). Administrators can access every department regardless.</p>
        <div class="table-wrap">
            <table class="table perm-table">
                <thead><tr><th>Department</th><th class="center">None</th><th class="center">Read</th><th class="center">Read &amp; write</th></tr></thead>
                <tbody>
                <?php foreach ($departments as $d): ?>
                    <?php $id = (int) $d['department_id']; $level = $perms[$id] ?? ''; ?>
                    <tr>
                        <td><span class="badge"><?= e($d['abbr']) ?></span> <?= e($d['name']) ?></td>
                        <td class="center"><input type="radio" name="perm[<?= e($id) ?>]" value=""<?= $level === '' ? ' checked' : '' ?> aria-label="No access to <?= e($d['name']) ?>"></td>
                        <td class="center"><input type="radio" name="perm[<?= e($id) ?>]" value="r"<?= $level === 'r' ? ' checked' : '' ?> aria-label="Read access to <?= e($d['name']) ?>"></td>
                        <td class="center"><input type="radio" name="perm[<?= e($id) ?>]" value="rw"<?= $level === 'rw' ? ' checked' : '' ?> aria-label="Read and write access to <?= e($d['name']) ?>"></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= $isNew ? 'Create user' : 'Save changes' ?></button>
        <a class="btn" href="<?= e($back) ?>">Cancel</a>
    </div>
</form>
</div>
