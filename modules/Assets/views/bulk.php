<?php
/**
 * Review + details for a bulk retire/transfer (also used for retiring a single asset).
 * Expects $action ('retire'|'transfer'), $targets, $skipped, $skipSummary, $truncated, $bulkLimit,
 * $f (field values), $errors, $methods, $buildings, $writableDepartments, $listQs.
 */
$isRetire = $action === 'retire';
$n = count($targets);
$show = array_slice($targets, 0, 50);
$back = url('/assets') . $listQs;
?>
<div class="narrow-col">
<div class="page-head">
    <div>
        <a class="back-link" href="<?= e($back) ?>">&larr; <?= $listQs !== '' ? 'Back to results' : 'All assets' ?></a>
        <h1><?= $isRetire ? 'Retire' : 'Transfer' ?> <?= e(number_format($n)) ?> asset<?= $n === 1 ? '' : 's' ?></h1>
    </div>
</div>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-danger" role="alert"><?= e($err) ?></div>
<?php endforeach; ?>
<?php if ($truncated): ?>
    <div class="alert alert-info" role="status">Only the first <?= e(number_format($bulkLimit)) ?> matching assets were selected. Run the action again for the rest.</div>
<?php endif; ?>
<?php if ($skipped !== []): ?>
    <div class="alert alert-info" role="status">
        <?= e(count($skipped)) ?> selected asset<?= count($skipped) === 1 ? ' was' : 's were' ?> left out (<?= e($skipSummary) ?>):
        <?= e(implode(', ', array_slice(array_column($skipped, 'asset_number'), 0, 15))) ?><?= count($skipped) > 15 ? ', …' : '' ?>
    </div>
<?php endif; ?>

<form method="post" action="<?= e(url('/assets/bulk/apply') . $listQs) ?>" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= e($action) ?>">
    <?php foreach ($targets as $t): ?>
        <input type="hidden" name="ids[]" value="<?= e((int) $t['asset_id']) ?>">
    <?php endforeach; ?>

    <div class="card form-card wide">
        <h2><?= $isRetire ? 'Retirement details' : 'Move to' ?></h2>
        <?php if ($isRetire): ?>
            <p class="muted small">Retired assets keep their department and location but are hidden from the default list and can no longer be edited, moved or verified. An administrator can restore them. A dated line is added to each asset's notes and to its transfer history.</p>
            <div class="field-row even">
                <div class="field">
                    <label for="retire_date">Retirement date</label>
                    <input type="date" id="retire_date" name="retire_date" value="<?= e($f['retire_date']) ?>" max="<?= e(date('Y-m-d')) ?>" required>
                </div>
                <div class="field">
                    <label for="disposal_method">Disposal method</label>
                    <select id="disposal_method" name="disposal_method" required>
                        <option value="">Choose…</option>
                        <?php foreach ($methods as $m): ?>
                            <option value="<?= e($m) ?>"<?= $f['disposal_method'] === $m ? ' selected' : '' ?>><?= e($m) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="field">
                <label for="retired_notes">Notes <span class="muted">(optional)</span></label>
                <input type="text" id="retired_notes" name="retired_notes" value="<?= e($f['retired_notes']) ?>" maxlength="255">
            </div>
        <?php else: ?>
            <p class="muted small">Leave a field on "keep current" to leave it unchanged on each asset. Every asset that actually moves is added to its transfer history.</p>
            <div class="field">
                <label for="department_id">Department</label>
                <select id="department_id" name="department_id">
                    <option value="0">(keep current)</option>
                    <?php foreach ($writableDepartments as $d): ?>
                        <option value="<?= e($d['department_id']) ?>"<?= (int) $f['department_id'] === (int) $d['department_id'] ? ' selected' : '' ?>>
                            <?= e($d['abbr']) ?> — <?= e($d['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field-row">
                <div class="field">
                    <label for="building_id">Building</label>
                    <select id="building_id" name="building_id">
                        <option value="0">(keep current)</option>
                        <?php foreach ($buildings as $b): ?>
                            <option value="<?= e($b['building_id']) ?>"<?= (int) $f['building_id'] === (int) $b['building_id'] ? ' selected' : '' ?>>
                                <?= e($b['name']) ?> (<?= e($b['abbr']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="room">Room</label>
                    <input type="text" id="room" name="room" value="<?= e($f['room']) ?>" maxlength="8" placeholder="(keep current)">
                </div>
            </div>
            <div class="field">
                <label for="reason">Reason <span class="muted">(optional)</span></label>
                <input type="text" id="reason" name="reason" value="<?= e($f['reason']) ?>" maxlength="16">
                <small class="muted">Up to 16 characters; recorded in each transfer.</small>
            </div>
        <?php endif; ?>
    </div>

    <div class="card section">
        <div class="card-head"><h2><?= e(number_format($n)) ?> asset<?= $n === 1 ? '' : 's' ?> affected</h2></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Asset #</th><th>Description</th><th>Dept</th><th>Location</th></tr></thead>
                <tbody>
                <?php foreach ($show as $t): ?>
                    <tr>
                        <td class="nowrap"><?= e($t['asset_number']) ?></td>
                        <td><?= e($t['description']) ?></td>
                        <td><span class="badge"><?= e($t['dept']) ?></span></td>
                        <td class="nowrap"><?= e(trim(($t['building'] ?? '') . ' ' . $t['room'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($n > count($show)): ?>
            <p class="muted small more-note">…and <?= e(number_format($n - count($show))) ?> more.</p>
        <?php endif; ?>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= $isRetire ? 'Retire' : 'Transfer' ?> <?= e(number_format($n)) ?> asset<?= $n === 1 ? '' : 's' ?></button>
        <a class="btn" href="<?= e($back) ?>">Cancel</a>
    </div>
</form>
</div>
