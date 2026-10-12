<?php
/**
 * The Attachments card on the asset page. Expects $ready, $items (rows + uploader names), $asset, $canWrite,
 * $maxFile, $maxTotal, $maxCount, $iniUpload, $iniPost, $listQs.
 */
$mb = static fn(int $b): string => $b >= PHP_INT_MAX / 2 ? 'no limit' : fmt_bytes($b);
$assetId = (int) $asset['asset_id'];
?>
<div class="card section" id="attachments">
    <div class="card-head"><h2>Attachments<?= $ready && $items !== [] ? ' <span class="muted">(' . e(count($items)) . ')</span>' : '' ?></h2></div>

    <?php if (!$ready): ?>
        <p class="muted">Attachments need a database update. Apply migration <code>014_asset_attachments.sql</code> on the <a href="<?= e(url('/migrate')) ?>">Migrations page</a>.</p>
    <?php else: ?>
        <?php if ($canWrite): ?>
            <form method="post" action="<?= e(url('/assets/' . $assetId . '/attachments') . $listQs) ?>" enctype="multipart/form-data" class="attach-form" autocomplete="off" data-upload-form>
                <?= csrf_field() ?>
                <div class="field">
                    <label for="attach-files">Add photos or documents</label>
                    <?php /* Works as a plain file chooser; app.js adds drag-and-drop onto the dashed box. */ ?>
                    <div class="dropzone" data-dropzone>
                        <p class="dropzone-text" data-dropzone-text>Choose files</p>
                        <input type="file" id="attach-files" name="files[]" multiple required
                               accept=".jpg,.jpeg,.png,.webp,.pdf,.txt,.csv,image/jpeg,image/png,image/webp,application/pdf,text/plain,text/csv"
                               data-max-bytes="<?= e($maxFile >= PHP_INT_MAX / 2 ? 0 : $maxFile) ?>"
                               data-max-total="<?= e($maxTotal >= PHP_INT_MAX / 2 ? 0 : $maxTotal) ?>"
                               data-max-count="<?= e($maxCount) ?>">
                        <ul class="file-list" data-file-list hidden></ul>
                    </div>
                    <small class="muted">JPG, PNG, WebP, PDF, TXT or CSV. Up to <?= e($mb($maxFile)) ?> each, <?= e($maxCount) ?> files at a time
                        (upload_max_filesize <?= e($iniUpload) ?>, post_max_size <?= e($iniPost) ?>).</small>
                    <small class="upload-warning" data-upload-warning hidden>These files are too big or too many for the server to accept in one go. Choose fewer or smaller files.</small>
                </div>
                <div class="field">
                    <label for="attach-caption">Caption <span class="muted">(optional, shown with each file you add now)</span></label>
                    <input type="text" id="attach-caption" name="caption" maxlength="255">
                </div>
                <button type="submit" class="btn">Upload</button>
            </form>
        <?php endif; ?>

        <?php if ($items === []): ?>
            <p class="empty">No attachments yet.</p>
        <?php else: ?>
            <div class="attach-grid">
            <?php foreach ($items as $it): ?>
                <?php
                $id = (int) $it['attachment_id'];
                $isImage = str_starts_with((string) $it['mime'], 'image/');
                $by = trim(($it['firstname'] ?? '') . ' ' . ($it['lastname'] ?? '')) ?: (string) ($it['username'] ?? '');
                $open = url('/attachments/' . $id);
                $label = $it['caption'] !== '' ? $it['caption'] : $it['original_name'];
                ?>
                <div class="attach-tile">
                    <a class="attach-thumb" href="<?= e($open) ?>" target="_blank" rel="noopener" title="Open <?= e($it['original_name']) ?>">
                        <?php if ($isImage): ?>
                            <img src="<?= e($it['has_thumb'] ? $open . '/thumb' : $open) ?>" alt="<?= e($label) ?>" loading="lazy">
                        <?php else: ?>
                            <span class="attach-icon"><?= icon('file', 36) ?></span>
                            <span class="attach-kind"><?= e(strtoupper(pathinfo((string) $it['original_name'], PATHINFO_EXTENSION))) ?></span>
                        <?php endif; ?>
                    </a>
                    <div class="attach-body">
                        <div class="attach-name" title="<?= e($it['original_name']) ?>"><?= e($it['original_name']) ?></div>
                        <?php if ($it['caption'] !== ''): ?><div class="attach-caption"><?= e($it['caption']) ?></div><?php endif; ?>
                        <div class="muted small">
                            <?= e(fmt_bytes($it['size_bytes'])) ?> · <?= e(fmt_date($it['uploaded_date'])) ?> · <?= e($by !== '' ? $by : 'Missing user') ?>
                        </div>
                        <div class="attach-actions small">
                            <a href="<?= e($open) ?>" target="_blank" rel="noopener">Open</a>
                            <a href="<?= e($open . '?download=1') ?>">Download</a>
                            <?php if ($canWrite): ?>
                                <a href="<?= e(url('/attachments/' . $id . '/delete') . $listQs) ?>" class="attach-delete">Delete</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
