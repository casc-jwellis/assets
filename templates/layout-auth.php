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
    <?php require __DIR__ . '/partials-flashes.php'; /* toasts: see app.js */ ?>
    <?= $content /* already-rendered, escaped template output */ ?>
</main>
</body>
</html>
