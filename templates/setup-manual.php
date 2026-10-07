<?php /** Shown when config/config.php could not be written. Expects $reason, $path, $php, $steps. */ ?>
<div class="card auth-card">
    <div class="auth-brand">
        <h1>One manual step left</h1>
    </div>
    <ul class="steps">
        <?php foreach ($steps as $step): ?>
            <li><?= e($step) ?></li>
        <?php endforeach; ?>
    </ul>
    <div class="alert alert-danger" role="alert"><?= e($reason) ?></div>
    <p>Create the file <code><?= e($path) ?></code> with the contents below (or make its folder writable by the web server and reload setup).
        The page contains your database password, so don't share it.</p>
    <pre class="code-block"><?= e($php) ?></pre>
</div>
