<?php
/** Expects $a (asset row with joined names), $transfers (list), $depreciation (array|null), $updatedBy (string),
 *  $retired (bool), $retiredBy (string), $canEdit, $canRetire, $canRestore (bool), $listQs (string). */
$creator = trim(($a['creator_first'] ?? '') . ' ' . ($a['creator_last'] ?? ''));
$location = trim(($a['building_name'] ?? '') . ($a['room'] !== '' ? ', room ' . $a['room'] : ''), ', ');
?>
<div class="page-head">
    <div>
        <a class="back-link" href="<?= e(url('/assets') . $listQs) ?>">&larr; <?= $listQs !== '' ? 'Back to results' : 'All assets' ?></a>
        <h1><?= e($a['asset_number']) ?></h1>
        <p class="muted"><?= e($a['description']) ?></p>
    </div>
    <div class="page-actions">
        <span class="badge"><?= e($a['dept_abbr']) ?></span>
        <?php if ($retired): ?>
            <span class="badge badge-danger">Retired</span>
        <?php elseif (empty($a['verified_date'])): ?>
            <span class="badge badge-warn">Never verified</span>
        <?php else: ?>
            <span class="badge badge-ok">Verified <?= e(fmt_date($a['verified_date'])) ?></span>
        <?php endif; ?>
        <?php if ($canEdit): ?>
            <form method="post" action="<?= e(url('/assets/' . (int) $a['asset_id'] . '/verify') . $listQs) ?>" class="inline-form">
                <?= csrf_field() ?>
                <button type="submit" class="btn" title="Record that this asset was physically checked today">Mark verified</button>
            </form>
            <a class="btn" href="<?= e(url('/assets/' . (int) $a['asset_id'] . '/edit') . $listQs) ?>">Edit</a>
        <?php endif; ?>
        <?php if ($canRetire): ?>
            <?php /* A single asset retires through the same review page as a bulk retire. */ ?>
            <form method="post" action="<?= e(url('/assets/bulk') . $listQs) ?>" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="retire">
                <input type="hidden" name="ids[]" value="<?= e((int) $a['asset_id']) ?>">
                <button type="submit" class="btn">Retire…</button>
            </form>
        <?php endif; ?>
        <?php if ($canRestore): ?>
            <a class="btn btn-primary" href="<?= e(url('/assets/' . (int) $a['asset_id'] . '/restore') . $listQs) ?>">Restore…</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($retired): ?>
    <div class="alert alert-warning" role="status">
        <strong>This asset is retired</strong>
        <?= !empty($a['retired_date']) ? 'on ' . e(fmt_date($a['retired_date'])) : '(date not recorded)' ?><?php if (!empty($a['disposal_method'])): ?>
            &middot; <?= e($a['disposal_method']) ?><?php endif; ?><?php if ($retiredBy !== ''): ?>
            &middot; by <?= e($retiredBy) ?><?php endif; ?>.
        <?php if (!empty($a['retired_notes'])): ?><br><?= e($a['retired_notes']) ?><?php endif; ?>
        It cannot be edited, moved or verified<?= $canRestore ? '' : ' unless an administrator restores it' ?>.
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-head"><h2>Details</h2></div>
    <dl class="detail-grid">
        <div><dt>Asset number</dt><dd><?= e($a['asset_number']) ?></dd></div>
        <div><dt>Serial number</dt><dd><?= e($a['serial_number'] !== '' ? $a['serial_number'] : '—') ?></dd></div>
        <div><dt>Type</dt><dd><?= e($a['type_name'] ?? '—') ?></dd></div>
        <div><dt>Description</dt><dd><?= e($a['description']) ?></dd></div>
        <div><dt>Department</dt><dd><?= e($a['dept_name'] ?? '—') ?> <span class="muted">(<?= e($a['dept_abbr']) ?>)</span></dd></div>
        <div><dt>Location</dt><dd><?= e($location !== '' ? $location : '—') ?> <?php if (!empty($a['building_abbr'])): ?><span class="muted">(<?= e($a['building_abbr']) ?>)</span><?php endif; ?></dd></div>
        <div><dt>Cost</dt><dd><?= e(fmt_money($a['cost'])) ?></dd></div>
        <div><dt>Purchase date</dt><dd><?= e(fmt_date($a['purchase_date'])) ?></dd></div>
        <div><dt>PO number</dt><dd><?= e($a['po_number'] !== '' ? $a['po_number'] : '—') ?></dd></div>
        <div><dt>Purchased by</dt><dd><?= e($a['purchaser_name'] ?? '—') ?> <?php if (!empty($a['purchaser_abbr'])): ?><span class="muted">(<?= e($a['purchaser_abbr']) ?>)</span><?php endif; ?></dd></div>
        <div>
            <dt>Depreciation</dt>
            <dd>
                <?php if ($depreciation === null): ?>
                    —
                <?php else: ?>
                    <?= e($a['depreciation_years']) ?> years,
                    <?= $depreciation['done'] ? 'fully depreciated' : 'until' ?>
                    <?= e($depreciation['end']) ?>
                <?php endif; ?>
            </dd>
        </div>
        <div><dt>Last verified</dt><dd><?= e(fmt_date($a['verified_date'])) ?></dd></div>
        <div><dt>Added by</dt><dd><?= e($creator !== '' ? $creator : $a['user_id']) ?></dd></div>
        <div><dt>Added on</dt><dd><?= e(fmt_date($a['created_date'])) ?></dd></div>
        <?php if (!empty($a['updated_date'])): ?>
            <div><dt>Last changed</dt><dd><?= e(fmt_date($a['updated_date'])) ?> <span class="muted">by <?= e($updatedBy) ?></span></dd></div>
        <?php endif; ?>
    </dl>
</div>

<div class="card section">
    <div class="card-head"><h2>Notes</h2></div>
    <?php if (trim((string) $a['notes']) === ''): ?>
        <p class="empty">No notes.</p>
    <?php else: ?>
        <div class="notes"><?= e($a['notes']) ?></div>
    <?php endif; ?>
</div>

<div class="card section">
    <div class="card-head"><h2>Transfer history</h2></div>
    <?php if ($transfers === []): ?>
        <p class="empty">This asset has never been transferred.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th>Date</th><th>From</th><th>To</th><th>Reason</th><th>By</th></tr>
                </thead>
                <tbody>
                <?php foreach ($transfers as $t): ?>
                    <?php $by = trim(($t['firstname'] ?? '') . ' ' . ($t['lastname'] ?? '')); ?>
                    <tr>
                        <td class="nowrap"><?= e(fmt_date($t['transfer_date'])) ?></td>
                        <td class="nowrap"><span class="badge"><?= e($t['department_from']) ?></span> <?= e($t['location_from']) ?></td>
                        <td class="nowrap"><span class="badge"><?= e($t['department_to']) ?></span> <?= e($t['location_to']) ?></td>
                        <td><?= e($t['reason'] ?? '') ?></td>
                        <td><?= e($by !== '' ? $by : $t['user_id']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
