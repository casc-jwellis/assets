<?php
/** Confirm deleting a department or type. Expects $kind, $meta (one, One), $row, $use (blockers, notes, counts). */
$isDept = $kind === 'departments';
$label = $isDept ? $row['abbr'] . ' · ' . $row['name'] : $row['name'];
$id = (int) $row[$isDept ? 'department_id' : 'type_id'];
$back = url('/lists/' . $kind . '/' . $id);
?>
<div class="narrow-col">
<div class="page-head">
    <div>
        <a class="back-link" href="<?= e($back) ?>">&larr; Back to <?= e($isDept ? $row['abbr'] : $row['name']) ?></a>
        <h1>Delete <?= e($meta['one']) ?></h1>
    </div>
</div>

<?php if ($use['blockers'] !== []): ?>
    <div class="card form-card danger-card">
        <p><strong><?= e($label) ?></strong> cannot be deleted yet:</p>
        <ul class="usage-list">
            <?php foreach ($use['blockers'] as $b): ?>
                <li><?= e($b) ?></li>
            <?php endforeach; ?>
        </ul>
        <p class="muted small">Renaming it is always possible. Deleting only works once nothing depends on it.</p>
        <div class="form-actions">
            <a class="btn btn-primary" href="<?= e($back) ?>">Back</a>
        </div>
    </div>
<?php else: ?>
    <div class="card form-card danger-card">
        <p>Delete <?= e($meta['one']) ?> <strong><?= e($label) ?></strong>? Nothing is using it.</p>
        <?php if ($use['notes'] !== []): ?>
            <ul class="usage-list">
                <?php foreach ($use['notes'] as $note): ?>
                    <li><?= e($note) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <p class="muted small">This cannot be undone.</p>
        <form method="post" action="<?= e($back . '/delete') ?>">
            <?= csrf_field() ?>
            <div class="form-actions">
                <button type="submit" class="btn btn-danger">Delete <?= e($meta['one']) ?></button>
                <a class="btn" href="<?= e($back) ?>">Cancel</a>
            </div>
        </form>
    </div>
<?php endif; ?>
</div>
