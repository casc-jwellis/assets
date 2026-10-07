<?php /** Expects $steps (list<string>). */ ?>
<div class="card auth-card">
    <div class="auth-brand">
        <span class="brand-mark"><?= icon('box', 22) ?></span>
        <h1>Setup complete</h1>
    </div>
    <ul class="steps">
        <?php foreach ($steps as $step): ?>
            <li><?= e($step) ?></li>
        <?php endforeach; ?>
    </ul>
    <p class="muted small">The setup page is now locked. For extra safety you can delete <code>public/setup.php</code>.</p>
    <a class="btn btn-primary btn-block" href="<?= e(url('/login')) ?>">Continue to sign in</a>
</div>
