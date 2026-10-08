<?php
/** Admin: restore a retired asset. Expects $a, $currentDept, $retiredBy, $selected (dept id), $note, $errors, $departments, $listQs. */
$back = url('/assets/' . (int) $a['asset_id']) . $listQs;
?>
<div class="page-head">
    <div>
        <a class="back-link" href="<?= e($back) ?>">&larr; Back to asset</a>
        <h1>Restore <?= e($a['asset_number']) ?></h1>
        <p class="muted"><?= e($a['description']) ?></p>
    </div>
</div>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-danger" role="alert"><?= e($err) ?></div>
<?php endforeach; ?>

<form method="post" action="<?= e(url('/assets/' . (int) $a['asset_id'] . '/restore') . $listQs) ?>" autocomplete="off">
    <?= csrf_field() ?>
    <div class="card form-card wide">
        <h2>Return to service</h2>
        <p class="muted small">
            Retired <?= !empty($a['retired_date']) ? e(fmt_date($a['retired_date'])) : '(date not recorded)' ?><?php if (!empty($a['disposal_method'])): ?>, <?= e($a['disposal_method']) ?><?php endif; ?><?php if ($retiredBy !== ''): ?> by <?= e($retiredBy) ?><?php endif; ?>.
            It is currently in <?= e($currentDept) ?>. Restoring clears the retirement details, records the restoration in the asset's transfer history, and adds a dated line to its notes.
        </p>
        <div class="field">
            <label for="department_id">Return to department</label>
            <select id="department_id" name="department_id" required>
                <option value="">Choose…</option>
                <?php foreach ($departments as $d): ?>
                    <option value="<?= e($d['department_id']) ?>"<?= $selected === (int) $d['department_id'] ? ' selected' : '' ?>>
                        <?= e($d['abbr']) ?> — <?= e($d['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="restore_notes">Notes <span class="muted">(optional)</span></label>
            <input type="text" id="restore_notes" name="restore_notes" value="<?= e($note) ?>" maxlength="255">
            <small class="muted">Added to the asset's notes, for example why it is back in service.</small>
        </div>
    </div>
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Restore asset</button>
        <a class="btn" href="<?= e($back) ?>">Cancel</a>
    </div>
</form>
