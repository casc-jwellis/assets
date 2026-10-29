<?php /** Expects $canAsk (bool): can a new link be requested? */ ?>
<div class="card auth-card">
    <div class="auth-brand">
        <span class="brand-mark"><?= icon('box', 22) ?></span>
        <h1>This link does not work</h1>
        <p class="muted">It may have expired (links last <?= e(\App\Modules\Auth\PasswordResets::LIFETIME_MINUTES) ?> minutes), already been used, or been replaced by a newer one.</p>
    </div>
    <?php if ($canAsk): ?>
        <a class="btn btn-primary btn-block" href="<?= e(url('/forgot')) ?>">Send me a new link</a>
    <?php endif; ?>
    <p class="auth-alt"><a href="<?= e(url('/login')) ?>">&larr; Back to sign in</a></p>
</div>
