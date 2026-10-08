<?php
/**
 * Expects $token, $filename, $size, $report (tables, fixes, warnings), $skipped (table => statements),
 * $notes (list<string>), $needsPhrase (bool), $admin (signed-in user).
 */
$labels = [
    'departments' => 'Departments', 'buildings' => 'Buildings', 'asset_types' => 'Asset types', 'users' => 'Users',
    'permissions' => 'Department access rows', 'assets' => 'Assets', 'transfers' => 'Transfers',
];
?>
<div class="narrow-col">
<div class="page-head">
    <div>
        <a class="back-link" href="<?= e(url('/import')) ?>">&larr; Start over</a>
        <h1>Review the import</h1>
        <p class="muted"><?= e($filename) ?> · <?= e(number_format($size / 1048576, 1)) ?> MB · nothing has been changed yet</p>
    </div>
</div>

<?php foreach ($notes as $n): ?>
    <div class="alert alert-info" role="status"><?= e($n) ?></div>
<?php endforeach; ?>

<div class="card form-card">
    <h2>What would be imported</h2>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Table</th><th class="num">Rows in the dump</th><th class="num">Will be imported</th></tr></thead>
            <tbody>
            <?php foreach ($report['tables'] as $table => $t): ?>
                <tr>
                    <td><?= e($labels[$table] ?? $table) ?></td>
                    <td class="num"><?= e(number_format($t['dump'])) ?></td>
                    <td class="num"><?= e(number_format($t['import'])) ?><?= $t['import'] !== $t['dump'] ? ' <span class="muted">(' . e(($t['import'] > $t['dump'] ? '+' : '') . ($t['import'] - $t['dump'])) . ')</span>' : '' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($skipped !== []): ?>
        <p class="muted small more-note">Ignored: <?= e(implode(', ', array_map(static fn($t, $n) => "{$t} ({$n} statement" . ($n === 1 ? '' : 's') . ')', array_keys($skipped), $skipped))) ?>.</p>
    <?php endif; ?>
</div>

<div class="card form-card section">
    <h2>Fixed automatically <span class="muted">(<?= e(count($report['fixes'])) ?>)</span></h2>
    <?php if ($report['fixes'] === []): ?>
        <p class="muted">The data needed no fixes.</p>
    <?php else: ?>
        <ul class="issue-list">
            <?php foreach ($report['fixes'] as $f): ?>
                <li>
                    <strong><?= e(number_format($f['count'])) ?></strong> &times; <?= e($f['label']) ?>
                    <?php if ($f['examples'] !== []): ?><span class="muted small">e.g. <?= e(implode('; ', $f['examples'])) ?></span><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<div class="card form-card section">
    <h2>Needs your attention afterwards <span class="muted">(<?= e(count($report['warnings'])) ?>)</span></h2>
    <?php if ($report['warnings'] === []): ?>
        <p class="muted">Nothing to flag.</p>
    <?php else: ?>
        <ul class="issue-list">
            <?php foreach ($report['warnings'] as $w): ?>
                <li>
                    <strong><?= e(number_format($w['count'])) ?></strong> &times; <?= e($w['label']) ?>
                    <?php if ($w['examples'] !== []): ?><span class="muted small">e.g. <?= e(implode('; ', $w['examples'])) ?></span><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<form method="post" action="<?= e(url('/import/run')) ?>" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= e($token) ?>">
    <div class="card form-card section">
        <h2>Confirm</h2>
        <p class="muted small">The tables above will be emptied and replaced. Your account (<?= e($admin['username']) ?>) is kept as it is.
            After loading, the retirement backfill (migrations 007 and 008) runs on the new data.</p>
        <label class="check">
            <input type="checkbox" name="ack" value="1" required>
            <span>I have a database backup and want the existing data replaced.</span>
        </label>
        <?php if ($needsPhrase): ?>
            <div class="field">
                <label for="phrase">The database already contains data. Type <strong>REPLACE</strong> to continue.</label>
                <input type="text" id="phrase" name="phrase" autocomplete="off" required>
            </div>
        <?php endif; ?>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Replace the data and import</button>
        <a class="btn" href="<?= e(url('/import')) ?>">Cancel</a>
    </div>
</form>
</div>
