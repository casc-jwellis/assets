<?php
/**
 * Create/edit form. Expects $isNew, $isSelf, $user (row|null), $f (field values), $perms (dept_id => 'r'|'rw'),
 * $errors, $departments, $timezones, $canDisable, $minPassword, $maxPassword, $temp (new user: a password is generated and emailed, so
 * none is asked for), $mailReady (new user and email can be sent), $mustChange (edit: still on a temporary password),
 * $actorAdmin (an administrator is signed in; otherwise an Asset Manager: no role choice, Read access only),
 * $managerReady (the Asset Manager role exists), $lockedRw (departments where an administrator gave this user Read & write,
 * which an Asset Manager cannot change).
 */
$role = $f['admin'] ? 'admin' : (!empty($f['manager']) ? 'manager' : '');
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

<?php if ($mustChange): ?>
    <div class="alert alert-info" role="status">
        <strong>Still on a temporary password.</strong> This person has not chosen their own yet, and will be asked to when they sign in.
    </div>
<?php endif; ?>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-danger" role="alert"><?= e($err) ?></div>
<?php endforeach; ?>

<form method="post" action="<?= e($action) ?>" autocomplete="off">
    <?= csrf_field() ?>

    <div class="card form-card wide">
        <h2>Account</h2>
        <div class="field">
            <label for="username">Username (used to sign in)</label>
            <input type="text" id="username" name="username" value="<?= e($f['username']) ?>" maxlength="64" required>
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
            <input type="email" id="email" name="email" value="<?= e($f['email']) ?>" maxlength="128"<?= $mailReady ? ' required' : '' ?>>
            <?php if ($mailReady): ?>
                <small class="muted">The temporary password is emailed here.</small>
            <?php elseif (!$isNew): ?>
                <small class="muted">If they forget their password, they can ask for a reset link on the sign-in page; it is sent to this address.</small>
            <?php endif; ?>
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

        <?php if ($actorAdmin): ?>
            <div class="field">
                <label for="role">Role</label>
                <select id="role" name="role"<?= $isSelf ? ' disabled' : '' ?>>
                    <option value=""<?= $role === '' ? ' selected' : '' ?>>User: only the departments chosen below</option>
                    <?php if ($managerReady || $role === 'manager'): ?>
                        <option value="manager"<?= $role === 'manager' ? ' selected' : '' ?>>Asset Manager: every department's assets, and manages ordinary user accounts</option>
                    <?php endif; ?>
                    <option value="admin"<?= $role === 'admin' ? ' selected' : '' ?>>Administrator: everything, including migrations, theme, email and import</option>
                </select>
                <?php if ($isSelf): ?><small class="muted">You cannot change your own role.</small><?php endif; ?>
                <?php if (!$managerReady): ?><small class="muted">Apply the latest migrations (Migrations page) to add the Asset Manager role.</small><?php endif; ?>
            </div>
        <?php else: ?>
            <p class="muted small">This will be an ordinary user account. Department access you give is Read only (see and mark verified).</p>
        <?php endif; ?>
    </div>

    <?php if ($temp): ?>
        <?php /* No password card: a temporary one is generated when the user is created. */ ?>
        <div class="card form-card wide section">
            <h2>Password</h2>
            <p class="muted small">A temporary password is generated when you create the user and <?= $mailReady ? 'emailed to them' : 'shown to you once (email is not set up, so it cannot be sent; an administrator can set it up on the Email page)' ?>.
                The first time they sign in they must choose their own.</p>
        </div>
    <?php elseif ($isNew): ?>
    <div class="card form-card wide section">
        <h2>Password</h2>
        <p class="muted small">Apply the latest migrations (Migrations page) and new users will instead get a generated password by email.</p>
        <div class="field-row even">
            <div class="field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" autocomplete="new-password" minlength="<?= e($minPassword) ?>" maxlength="<?= e($maxPassword) ?>" required>
                <small class="muted">Between <?= e($minPassword) ?> and <?= e($maxPassword) ?> characters.</small>
            </div>
            <div class="field">
                <label for="password2">Confirm password</label>
                <input type="password" id="password2" name="password2" autocomplete="new-password" minlength="<?= e($minPassword) ?>" maxlength="<?= e($maxPassword) ?>" required>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card form-card wide section">
        <div class="card-head"><h2>Department access</h2></div>
        <p class="muted small perm-note">Controls which departments' assets this person can see and mark verified (Read)<?= $actorAdmin ? ' and also change (Read &amp; write)' : '' ?>. Administrators and Asset Managers can access every department regardless.<?= $actorAdmin ? '' : ' Read &amp; write is set by an administrator.' ?></p>
        <div class="table-wrap">
            <table class="table perm-table" data-perm-table>
                <thead><tr><th>Department</th><th class="center">None</th><th class="center">Read</th><?php if ($actorAdmin): ?><th class="center">Read &amp; write</th><?php endif; ?></tr></thead>
                <tbody>
                <?php /* Shown by app.js (it needs the script to do anything); the radios have no name, so they are never submitted. */ ?>
                <tr class="perm-all" data-perm-all hidden>
                    <td><strong>All departments</strong></td>
                    <?php foreach (['' => 'No access to', 'r' => 'Read access to'] + ($actorAdmin ? ['rw' => 'Read and write access to'] : []) as $lv => $what): ?>
                        <td class="center"><input type="radio" data-perm-all-level="<?= e($lv) ?>" aria-label="<?= e($what) ?> every department"></td>
                    <?php endforeach; ?>
                </tr>
                <?php foreach ($departments as $d): ?>
                    <?php $id = (int) $d['department_id']; $level = $perms[$id] ?? ''; ?>
                    <tr>
                        <td><span class="badge"><?= e($d['abbr']) ?></span> <?= e($d['name']) ?></td>
                        <?php if (in_array($id, $lockedRw, true)): ?>
                            <td class="muted small" colspan="2">Read &amp; write (set by an administrator)</td>
                        <?php else: ?>
                            <td class="center"><input type="radio" name="perm[<?= e($id) ?>]" value=""<?= $level === '' ? ' checked' : '' ?> aria-label="No access to <?= e($d['name']) ?>"></td>
                            <td class="center"><input type="radio" name="perm[<?= e($id) ?>]" value="r"<?= $level === 'r' ? ' checked' : '' ?> aria-label="Read access to <?= e($d['name']) ?>"></td>
                            <?php if ($actorAdmin): ?>
                                <td class="center"><input type="radio" name="perm[<?= e($id) ?>]" value="rw"<?= $level === 'rw' ? ' checked' : '' ?> aria-label="Read and write access to <?= e($d['name']) ?>"></td>
                            <?php endif; ?>
                        <?php endif; ?>
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

<?php if (!$isNew && !$isSelf && !empty($mergeTargets)): /* (empty for an Asset Manager: merging stays with administrators) */ ?>
    <?php parse_str(ltrim((string) $listQs, '?'), $keep); /* carry the list's search/page/status through the picker */ ?>
    <form method="get" action="<?= e(url('/users/' . rawurlencode((string) $f['user_id']) . '/merge')) ?>" class="card form-card wide section" autocomplete="off">
        <h2>Merge into another user</h2>
        <p class="muted small">Use this when the same person has two accounts. Everything this account added, changed, retired or transferred moves to the account you pick, then
            <strong><?= e($f['username']) ?></strong> is deleted. You will see exactly what moves before anything changes.</p>
        <?php foreach ($keep as $k => $v): ?>
            <?php if (is_string($v)): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endif; ?>
        <?php endforeach; ?>
        <div class="field">
            <label for="merge-into">Keep this account</label>
            <select id="merge-into" name="into" required>
                <option value="">Choose a user…</option>
                <?php foreach ($mergeTargets as $m): ?>
                    <?php $mn = trim($m['firstname'] . ' ' . $m['lastname']); ?>
                    <option value="<?= e($m['user_id']) ?>"><?= e($m['username']) ?><?= $mn !== '' ? ' — ' . e($mn) : '' ?><?= !empty($m['disabled']) ? ' (disabled)' : '' ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn">Review the merge</button>
    </form>
<?php endif; ?>
</div>
