<?php
/**
 * Add / edit a department, building or asset type. Expects $kind ('departments'|'buildings'|'types'), $meta (one, One,
 * abbr_max, cascade), $hasAbbr, $isNew, $row (existing row or null), $v (field values), $errors, $use (usage summary or
 * null), $nameMax, $yearsMax.
 */
$pk = $meta['pk'];
$action = $isNew ? url('/lists/' . $kind . '/new') : url('/lists/' . $kind . '/' . (int) $row[$pk]);
$counts = $use['counts'] ?? [];
$plural = static fn(int $n, string $word, string $many = ''): string => number_format($n) . ' ' . ($n === 1 ? $word : ($many !== '' ? $many : $word . 's'));
?>
<div class="narrow-col">
<div class="page-head">
    <div>
        <a class="back-link" href="<?= e(url('/lists')) ?>">&larr; Reference data</a>
        <h1><?= $isNew ? 'Add ' . e($meta['one']) : e($hasAbbr ? $row['abbr'] . ' · ' . $row['name'] : $row['name']) ?></h1>
    </div>
    <?php if (!$isNew): ?>
        <div class="page-actions">
            <a class="btn" href="<?= e($action . '/delete') ?>">Delete…</a>
        </div>
    <?php endif; ?>
</div>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-danger" role="alert"><?= e($err) ?></div>
<?php endforeach; ?>

<form method="post" action="<?= e($action) ?>" autocomplete="off">
    <?= csrf_field() ?>
    <div class="card form-card wide">
        <h2><?= $isNew ? 'New ' . e($meta['one']) : e($meta['One']) ?></h2>
        <?php if ($hasAbbr): ?>
            <div class="field">
                <label for="abbr">Abbreviation</label>
                <input type="text" id="abbr" name="abbr" value="<?= e($v['abbr']) ?>" maxlength="<?= e($meta['abbr_max']) ?>" required autocapitalize="characters" spellcheck="false">
                <small class="muted">Up to <?= e($meta['abbr_max']) ?> letters, digits, hyphens or underscores. Shown in lists<?= $kind === 'departments' ? ' and recorded in transfer history' : ', and the start of each location in transfer history (for example MAIN101)' ?>. Always saved in capitals.</small>
                <?php if (!$isNew && $meta['cascade'] && ($counts['history'] ?? 0) > 0): ?>
                    <small class="muted">If you change it, the <?= e($plural($counts['history'], 'transfer history entry', 'transfer history entries')) ?> that mention <strong><?= e($row['abbr']) ?></strong> are updated to the new abbreviation, so the history stays attached to this department.</small>
                <?php elseif (!$isNew && !$meta['cascade']): ?>
                    <small class="muted">If you change it, past transfer history keeps the old abbreviation: it records where the asset was at the time.</small>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <div class="field">
            <label for="name">Name</label>
            <input type="text" id="name" name="name" value="<?= e($v['name']) ?>" maxlength="<?= e($nameMax) ?>" required>
        </div>
        <?php if ($kind === 'types'): ?>
            <div class="field">
                <label for="years">Depreciation (years)</label>
                <input type="text" id="years" name="years" inputmode="numeric" value="<?= e($v['years']) ?>" maxlength="3" required>
                <small class="muted">How many years until an asset of this type is fully depreciated, from 0 to <?= e($yearsMax) ?> (0 for none). Used on each asset's page.</small>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!$isNew): ?>
        <div class="card form-card wide section">
            <h2>In use</h2>
            <ul class="usage-list">
                <?php if ($kind === 'departments'): ?>
                    <li><strong><?= e(number_format($counts['assets'])) ?></strong> asset<?= $counts['assets'] === 1 ? '' : 's' ?> in this department</li>
                    <li><strong><?= e(number_format($counts['bought'])) ?></strong> asset<?= $counts['bought'] === 1 ? '' : 's' ?> bought by it</li>
                    <li><strong><?= e(number_format($counts['users'])) ?></strong> user<?= $counts['users'] === 1 ? '' : 's' ?> with it as their home department</li>
                    <li><strong><?= e(number_format($counts['access'])) ?></strong> user<?= $counts['access'] === 1 ? '' : 's' ?> with department access</li>
                    <li><strong><?= e(number_format($counts['history'])) ?></strong> transfer history entr<?= $counts['history'] === 1 ? 'y' : 'ies' ?></li>
                <?php elseif ($kind === 'buildings'): ?>
                    <li><strong><?= e(number_format($counts['assets'])) ?></strong> asset<?= $counts['assets'] === 1 ? '' : 's' ?> in this building</li>
                <?php else: ?>
                    <li><strong><?= e(number_format($counts['assets'])) ?></strong> asset<?= $counts['assets'] === 1 ? '' : 's' ?> of this type</li>
                <?php endif; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= $isNew ? 'Add ' . e($meta['one']) : 'Save changes' ?></button>
        <a class="btn" href="<?= e(url('/lists')) ?>">Cancel</a>
    </div>
</form>
</div>
