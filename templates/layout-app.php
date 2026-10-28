<?php
/** Main application shell: sidebar navigation + top bar. Expects $content, $title. */
$appName = app_name();
$user = current_user();
$flashes = app()->session->pullFlashes();
?>
<!doctype html>
<html lang="en" data-theme="light">
<head>
<?php require __DIR__ . '/partials-head.php'; ?>
</head>
<body>
<div class="shell">
    <aside class="sidebar" id="sidebar" aria-label="Main navigation">
        <a class="brand" href="<?= e(url('/')) ?>">
            <span class="brand-mark"><?= icon('box', 20) ?></span>
            <span><?= e($appName) ?></span>
        </a>
        <nav class="nav">
            <?php foreach (app()->auth->mustChangePassword() ? [] : app()->navItems() as $item): /* nothing else is usable until a temporary password is replaced */ ?>
                <a href="<?= e(url($item['path'])) ?>"
                   class="nav-link<?= is_active($item['path']) ? ' is-active' : '' ?>"
                   <?= is_active($item['path']) ? 'aria-current="page"' : '' ?>>
                    <?= icon($item['icon']) ?>
                    <span><?= e($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
    </aside>
    <div class="scrim" data-nav-close></div>

    <div class="main">
        <header class="topbar">
            <button type="button" class="icon-btn menu-btn" data-nav-toggle aria-label="Open menu" aria-controls="sidebar">
                <?= icon('menu') ?>
            </button>
            <div class="topbar-title"><?= e($title ?? '') ?></div>
            <div class="topbar-actions">
                <?php require __DIR__ . '/partials-theme-toggle.php'; ?>
                <a class="user-chip" href="<?= e(url('/account/password')) ?>" title="Account settings">
                    <?= icon('user') ?>
                    <span><?= e(trim($user['firstname'] . ' ' . $user['lastname']) ?: $user['username']) ?></span>
                </a>
                <form method="post" action="<?= e(url('/logout')) ?>" class="inline-form">
                    <?= csrf_field() ?>
                    <button type="submit" class="icon-btn" aria-label="Sign out" title="Sign out"><?= icon('log-out') ?></button>
                </form>
            </div>
        </header>

        <main class="content">
            <?php require __DIR__ . '/partials-flashes.php'; /* toasts: see app.js */ ?>
            <?= $content /* already-rendered, escaped template output */ ?>
        </main>
    </div>
</div>
</body>
</html>
