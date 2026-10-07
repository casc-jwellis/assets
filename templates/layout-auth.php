<?php
/** Chrome-less layout for the login page and errors seen while logged out. Expects $content, $title. */
$flashes = app()->session->pullFlashes();
?>
<!doctype html>
<html lang="en" data-theme="light">
<head>
<?php require __DIR__ . '/partials-head.php'; ?>
</head>
<body class="auth-body">
<div class="auth-theme"><?php require __DIR__ . '/partials-theme-toggle.php'; ?></div>
<main class="auth-wrap<?= !empty($wide) ? ' auth-wide' : '' ?>">
    <?php foreach ($flashes as $f): ?>
        <div class="alert alert-<?= e($f['type']) ?>" role="status"><?= e($f['message']) ?></div>
    <?php endforeach; ?>
    <?= $content /* already-rendered, escaped template output */ ?>
</main>
</body>
</html>
