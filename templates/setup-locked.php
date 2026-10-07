<div class="card auth-card">
    <div class="auth-brand">
        <span class="brand-mark"><?= icon('lock', 22) ?></span>
        <h1>Setup is locked</h1>
    </div>
    <p class="muted">This application is already configured, so the setup page is disabled.
        To run setup again, delete <code>config/config.php</code> on the server first.</p>
    <p><a class="btn btn-block" href="<?= e(url('/')) ?>">Go to the application</a></p>
</div>
