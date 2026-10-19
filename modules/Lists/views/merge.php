<?php
/**
 * Review a department merge. Expects $source (the department that will be deleted), $target (the one that is kept) and
 * $counts (assets, bought, users, access, shared, raised, history).
 */
$id = (int) $source['department_id'];
$back = url('/lists/departments/' . $id);
$s = $source['abbr'];
$t = $target['abbr'];
$total = $counts['assets'] + $counts['bought'] + $counts['users'] + $counts['access'] + $counts['history'];
?>
<div class="narrow-col">
<div class="page-head">
    <div>
        <a class="back-link" href="<?= e($back) ?>">&larr; Back to <?= e($s) ?></a>
        <h1>Merge departments</h1>
        <p class="muted">Nothing has been changed yet.</p>
    </div>
</div>

<div class="card form-card">
    <h2>What will happen</h2>
    <p>Everything that belongs to <strong><?= e($s . ' · ' . $source['name']) ?></strong> moves to
        <strong><?= e($t . ' · ' . $target['name']) ?></strong>, and then <strong><?= e($s) ?></strong> is deleted.
        <?= e($t) ?> keeps its own abbreviation and name.</p>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>What moves</th><th class="num">Count</th></tr></thead>
            <tbody>
                <tr><td>Assets in <?= e($s) ?></td><td class="num"><?= e(number_format($counts['assets'])) ?></td></tr>
                <tr><td>Assets <?= e($s) ?> bought (the purchasing department)</td><td class="num"><?= e(number_format($counts['bought'])) ?></td></tr>
                <tr><td>Users with <?= e($s) ?> as their home department</td><td class="num"><?= e(number_format($counts['users'])) ?></td></tr>
                <tr><td>Department access entries</td><td class="num"><?= e(number_format($counts['access'])) ?></td></tr>
                <tr><td>Transfer history entries that mention <?= e($s) ?></td><td class="num"><?= e(number_format($counts['history'])) ?></td></tr>
            </tbody>
        </table>
    </div>
    <ul class="usage-list more-note">
        <?php if ($counts['shared'] > 0): ?>
            <li><?= e(number_format($counts['shared'])) ?> user<?= $counts['shared'] === 1 ? ' has' : 's have' ?> access to both departments, so their two entries become one, keeping the higher level<?= $counts['raised'] > 0 ? ' (' . e(number_format($counts['raised'])) . ' of them ' . ($counts['raised'] === 1 ? 'gets' : 'get') . ' read &amp; write on ' . e($t) . ' that they did not have)' : '' ?>.</li>
        <?php endif; ?>
        <?php if ($counts['history'] > 0): ?>
            <li>The history will say <strong><?= e($t) ?></strong> wherever it said <strong><?= e($s) ?></strong>, so it stays attached to a department that exists. This changes what those old entries show.</li>
        <?php endif; ?>
    </ul>
</div>

<form method="post" action="<?= e($back . '/merge') ?>" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="into" value="<?= e((int) $target['department_id']) ?>">
    <div class="card form-card danger-card section">
        <h2>Confirm</h2>
        <p class="muted small"><?= e(number_format($total)) ?> record<?= $total === 1 ? '' : 's' ?> will be moved and <strong><?= e($s) ?></strong> will be deleted. This cannot be undone.</p>
        <label class="check">
            <input type="checkbox" name="ack" value="1" required>
            <span>I have a database backup and want these departments merged.</span>
        </label>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn btn-danger">Merge and delete <?= e($s) ?></button>
        <a class="btn" href="<?= e($back) ?>">Cancel</a>
    </div>
</form>
</div>
