<?php
/**
 * Review a merge. Expects $source (the duplicate that will be deleted), $target (the account to keep),
 * $counts (added, changed, retired, transfers) and $perms (department access after the merge).
 */
$level = static fn(?string $p): string => $p === 'rw' ? 'Read & write' : ($p === 'r' ? 'Read' : 'None');
$name = static fn(array $u): string => trim($u['firstname'] . ' ' . $u['lastname']);
$total = array_sum($counts);
$back = url('/users/' . rawurlencode((string) $source['user_id'])) . $listQs;
?>
<div class="narrow-col">
<div class="page-head">
    <div>
        <a class="back-link" href="<?= e($back) ?>">&larr; Back to <?= e($source['username']) ?></a>
        <h1>Merge users</h1>
        <p class="muted">Nothing has been changed yet.</p>
    </div>
</div>

<div class="card form-card">
    <h2>What will happen</h2>
    <p>Everything that belongs to <strong><?= e($source['username']) ?></strong><?= $name($source) !== '' ? ' (' . e($name($source)) . ')' : '' ?>
        moves to <strong><?= e($target['username']) ?></strong><?= $name($target) !== '' ? ' (' . e($name($target)) . ')' : '' ?>,
        and then <strong><?= e($source['username']) ?></strong> is deleted.
        <?= e($target['username']) ?> keeps their own name, email, password and administrator setting.</p>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Records that move</th><th class="num">Count</th></tr></thead>
            <tbody>
                <tr><td>Assets added by <?= e($source['username']) ?></td><td class="num"><?= e(number_format($counts['added'])) ?></td></tr>
                <tr><td>Assets last changed by <?= e($source['username']) ?></td><td class="num"><?= e(number_format($counts['changed'])) ?></td></tr>
                <tr><td>Assets retired by <?= e($source['username']) ?></td><td class="num"><?= e(number_format($counts['retired'])) ?></td></tr>
                <tr><td>Transfers and retire/restore history by <?= e($source['username']) ?></td><td class="num"><?= e(number_format($counts['transfers'])) ?></td></tr>
            </tbody>
        </table>
    </div>
    <p class="muted small more-note">Names already written into an asset's notes (such as "Retired 2026-01-05 by …") are text and stay as they are.</p>
</div>

<div class="card form-card section">
    <h2>Department access</h2>
    <?php if ($perms === []): ?>
        <p class="muted"><?= e($source['username']) ?> has no department access of their own, so <?= e($target['username']) ?>'s access does not change.</p>
    <?php else: ?>
        <p class="muted small"><?= e($target['username']) ?> ends up with the higher of the two levels in each department.</p>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Department</th><th><?= e($source['username']) ?></th><th><?= e($target['username']) ?> now</th><th><?= e($target['username']) ?> after</th></tr></thead>
                <tbody>
                <?php foreach ($perms as $p): ?>
                    <tr>
                        <td><span class="badge"><?= e($p['abbr']) ?></span> <?= e($p['name']) ?></td>
                        <td><?= e($level($p['src'])) ?></td>
                        <td><?= e($level($p['tgt'])) ?></td>
                        <td><strong><?= e($level($p['result'])) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<form method="post" action="<?= e(url('/users/' . rawurlencode((string) $source['user_id']) . '/merge') . $listQs) ?>" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="into" value="<?= e($target['user_id']) ?>">
    <div class="card form-card danger-card section">
        <h2>Confirm</h2>
        <p class="muted small"><?= e(number_format($total)) ?> record<?= $total === 1 ? '' : 's' ?> will be moved and <strong><?= e($source['username']) ?></strong> will be deleted. This cannot be undone.</p>
        <label class="check">
            <input type="checkbox" name="ack" value="1" required>
            <span>I have a database backup and want these accounts merged.</span>
        </label>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn btn-danger">Merge and delete <?= e($source['username']) ?></button>
        <a class="btn" href="<?= e($back) ?>">Cancel</a>
    </div>
</form>
</div>
