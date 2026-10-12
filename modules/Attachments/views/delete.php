<?php
/** Confirm deleting one attachment. Expects $a (attachment row with asset_number), $listQs. */
$back = url('/assets/' . (int) $a['asset_id']) . $listQs . '#attachments';
?>
<div class="narrow-col">
<div class="page-head">
    <div>
        <a class="back-link" href="<?= e($back) ?>">&larr; Back to <?= e($a['asset_number']) ?></a>
        <h1>Delete attachment</h1>
    </div>
</div>

<div class="card form-card danger-card">
    <p>Delete <strong><?= e($a['original_name']) ?></strong> (<?= e(fmt_bytes($a['size_bytes'])) ?>) from asset <strong><?= e($a['asset_number']) ?></strong>?</p>
    <?php if ($a['caption'] !== ''): ?><p class="muted"><?= e($a['caption']) ?></p><?php endif; ?>
    <p class="muted small">The file is removed from the server. This cannot be undone.</p>
    <?php if (str_starts_with((string) $a['mime'], 'image/')): ?>
        <p><img class="attach-preview" src="<?= e(url('/attachments/' . (int) $a['attachment_id'] . ($a['has_thumb'] ? '/thumb' : ''))) ?>" alt="<?= e($a['original_name']) ?>"></p>
    <?php endif; ?>
    <form method="post" action="<?= e(url('/attachments/' . (int) $a['attachment_id'] . '/delete') . $listQs) ?>">
        <?= csrf_field() ?>
        <div class="form-actions">
            <button type="submit" class="btn btn-danger">Delete file</button>
            <a class="btn" href="<?= e($back) ?>">Cancel</a>
        </div>
    </form>
</div>
</div>
