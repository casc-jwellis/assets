<?php
/**
 * Add/edit form. Expects $isNew, $asset (row|null), $f (values), $errors, $version, $conflict,
 * $canRenumber, $types, $buildings, $departments, $writableDepartments.
 */
$action = $isNew ? url('/assets/new') : url('/assets/' . (int) $asset['asset_id'] . '/edit');
$back = $isNew ? url('/assets') : url('/assets/' . (int) $asset['asset_id']);
?>
<div class="page-head">
    <div>
        <a class="back-link" href="<?= e($back) ?>">&larr; <?= $isNew ? 'All assets' : 'Back to asset' ?></a>
        <h1><?= $isNew ? 'New asset' : 'Edit ' . e($asset['asset_number']) ?></h1>
    </div>
</div>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-danger" role="alert">
        <?= e($err) ?>
        <?php if ($conflict && !$isNew): ?>
            <a href="<?= e(url('/assets/' . (int) $asset['asset_id'])) ?>" target="_blank" rel="noopener">Open current version</a>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<form method="post" action="<?= e($action) ?>" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="version" value="<?= e($version) ?>">

    <div class="card form-card wide">
        <h2>Asset</h2>
        <div class="field-row even">
            <div class="field">
                <label for="asset_number">Asset number</label>
                <input type="text" id="asset_number" name="asset_number" value="<?= e($f['asset_number']) ?>" maxlength="32"
                    <?= $canRenumber ? 'required' : 'disabled' ?>>
                <?php if (!$canRenumber): ?><small class="muted">Only administrators can change an asset number.</small><?php endif; ?>
            </div>
            <div class="field">
                <label for="serial_number">Serial number</label>
                <input type="text" id="serial_number" name="serial_number" value="<?= e($f['serial_number']) ?>" maxlength="32">
            </div>
        </div>
        <div class="field">
            <label for="description">Description</label>
            <input type="text" id="description" name="description" value="<?= e($f['description']) ?>" maxlength="64" required>
        </div>
        <div class="field">
            <label for="type_id">Type</label>
            <select id="type_id" name="type_id" required>
                <option value="">Choose…</option>
                <?php foreach ($types as $t): ?>
                    <option value="<?= e($t['type_id']) ?>"<?= (int) $f['type_id'] === (int) $t['type_id'] ? ' selected' : '' ?>><?= e($t['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="card form-card wide section">
        <h2>Location</h2>
        <div class="field">
            <label for="department_id">Department</label>
            <select id="department_id" name="department_id" required>
                <option value="">Choose…</option>
                <?php foreach ($writableDepartments as $d): ?>
                    <option value="<?= e($d['department_id']) ?>"<?= (int) $f['department_id'] === (int) $d['department_id'] ? ' selected' : '' ?>>
                        <?= e($d['abbr']) ?> — <?= e($d['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small class="muted">Only departments you can edit are listed.</small>
        </div>
        <div class="field-row">
            <div class="field">
                <label for="building_id">Building</label>
                <select id="building_id" name="building_id" required>
                    <option value="">Choose…</option>
                    <?php foreach ($buildings as $b): ?>
                        <option value="<?= e($b['building_id']) ?>"<?= (int) $f['building_id'] === (int) $b['building_id'] ? ' selected' : '' ?>>
                            <?= e($b['name']) ?> (<?= e($b['abbr']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="room">Room</label>
                <input type="text" id="room" name="room" value="<?= e($f['room']) ?>" maxlength="8">
            </div>
        </div>
        <?php if (!$isNew): ?>
            <div class="field">
                <label for="reason">Reason for move <span class="muted">(optional)</span></label>
                <input type="text" id="reason" name="reason" value="<?= e($f['reason']) ?>" maxlength="16">
                <small class="muted">If you change the department, building or room, the move is added to the transfer history automatically. Up to 16 characters.</small>
            </div>
        <?php endif; ?>
    </div>

    <div class="card form-card wide section">
        <h2>Purchase</h2>
        <div class="field-row even">
            <div class="field">
                <label for="cost">Cost ($)</label>
                <input type="text" id="cost" name="cost" value="<?= e($f['cost']) ?>" inputmode="decimal" required>
            </div>
            <div class="field">
                <label for="purchase_date">Purchase date</label>
                <input type="date" id="purchase_date" name="purchase_date" value="<?= e($f['purchase_date']) ?>" required>
            </div>
        </div>
        <div class="field-row even">
            <div class="field">
                <label for="po_number">PO number</label>
                <input type="text" id="po_number" name="po_number" value="<?= e($f['po_number']) ?>" maxlength="32">
            </div>
            <div class="field">
                <label for="purchaser_id">Purchased by</label>
                <select id="purchaser_id" name="purchaser_id" required>
                    <option value="">Choose…</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= e($d['department_id']) ?>"<?= (int) $f['purchaser_id'] === (int) $d['department_id'] ? ' selected' : '' ?>>
                            <?= e($d['abbr']) ?> — <?= e($d['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <div class="card form-card wide section">
        <h2>Notes</h2>
        <div class="field">
            <label for="notes" class="sr-only">Notes</label>
            <textarea id="notes" name="notes" rows="6" maxlength="10240"><?= e($f['notes']) ?></textarea>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= $isNew ? 'Create asset' : 'Save changes' ?></button>
        <a class="btn" href="<?= e($back) ?>">Cancel</a>
    </div>
</form>
