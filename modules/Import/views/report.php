<?php
/** Expects $r: when, plan (tables, fixes, warnings), result (counts, retired, stuck), skipped. */
$labels = [
    'departments' => 'Departments', 'buildings' => 'Buildings', 'asset_types' => 'Asset types', 'users' => 'Users',
    'permissions' => 'Department access rows', 'assets' => 'Assets', 'transfers' => 'Transfers',
];
$plan = $r['plan'];
$res = $r['result'];
?>
<div class="narrow-col">
<div class="page-head">
    <div>
        <h1>Import complete</h1>
        <p class="muted">Finished <?= e($r['when']) ?>. This report stays available until you sign out.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-primary" href="<?= e(url('/assets')) ?>">Browse the assets</a>
    </div>
</div>

<div class="card form-card">
    <h2>Result</h2>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Table</th><th class="num">In the dump</th><th class="num">Now in the database</th></tr></thead>
            <tbody>
            <?php foreach ($plan['tables'] as $table => $t): ?>
                <tr>
                    <td><?= e($labels[$table] ?? $table) ?></td>
                    <td class="num"><?= e(number_format($t['dump'])) ?></td>
                    <td class="num"><?= e(number_format($res['counts'][$table] ?? 0)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="more-note">
        <strong><?= e(number_format($res['retired'])) ?></strong> assets are marked retired (from the old RETIRE department).
        <?php if ($res['stuck'] > 0): ?>
            <strong><?= e(number_format($res['stuck'])) ?></strong> of them <?= $res['stuck'] === 1 ? 'is' : 'are' ?> still in the RETIRE department because their history does not say where <?= $res['stuck'] === 1 ? 'it' : 'they' ?> came from;
            open <?= $res['stuck'] === 1 ? 'it' : 'one' ?> and use <em>Restore…</em> to choose a department when needed.
        <?php else: ?>
            All of them were returned to their original departments.
        <?php endif; ?>
    </p>
</div>

<div class="card form-card section">
    <h2>Fixed automatically <span class="muted">(<?= e(count($plan['fixes'])) ?>)</span></h2>
    <?php if ($plan['fixes'] === []): ?>
        <p class="muted">The data needed no fixes.</p>
    <?php else: ?>
        <ul class="issue-list">
            <?php foreach ($plan['fixes'] as $f): ?>
                <li><strong><?= e(number_format($f['count'])) ?></strong> &times; <?= e($f['label']) ?>
                    <?php if ($f['examples'] !== []): ?><span class="muted small">e.g. <?= e(implode('; ', $f['examples'])) ?></span><?php endif; ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<div class="card form-card section">
    <h2>To follow up <span class="muted">(<?= e(count($plan['warnings'])) ?>)</span></h2>
    <?php if ($plan['warnings'] === []): ?>
        <p class="muted">Nothing to flag.</p>
    <?php else: ?>
        <ul class="issue-list">
            <?php foreach ($plan['warnings'] as $w): ?>
                <li><strong><?= e(number_format($w['count'])) ?></strong> &times; <?= e($w['label']) ?>
                    <?php if ($w['examples'] !== []): ?><span class="muted small">e.g. <?= e(implode('; ', $w['examples'])) ?></span><?php endif; ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<div class="card form-card section">
    <h2>Next steps</h2>
    <ol class="steps">
        <li>Spot-check some assets, transfer histories and users.</li>
        <li>Imported users keep their old passwords; each is upgraded to a modern hash the first time that person signs in.</li>
        <li>When you are happy and have gone live, delete the <code>modules/Import</code> folder to remove this tool.</li>
    </ol>
</div>
</div>
