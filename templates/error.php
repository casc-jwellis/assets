<?php /** Expects $status (int), $title (string), $detail (string|null). */ ?>
<div class="card error-card">
    <div class="error-code"><?= e($status) ?></div>
    <h1><?= e($title) ?></h1>
    <?php if (!empty($detail)): ?>
        <?php if ($status === 500): ?>
            <pre class="error-detail"><?= e($detail) ?></pre>
        <?php else: ?>
            <p class="muted"><?= e($detail) ?></p>
        <?php endif; ?>
    <?php endif; ?>
    <p><a class="btn" href="<?= e(url('/')) ?>">Back to home</a></p>
</div>
