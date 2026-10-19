<?php
/** Shared <head> contents. Expects $title (string|null). */
$appName = app_name();
// Colours chosen on the Theme page come as one small stylesheet after the built-in one (also on the login page).
$themeVersion = app()->module('Theme') !== null ? app()->settings->get('theme.version') : null;
?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<title><?= e(isset($title) && $title !== '' ? $title . ' · ' . $appName : $appName) ?></title>
<script nonce="<?= e(nonce()) ?>">
// Apply the saved/system theme before first paint to avoid a flash of the wrong theme.
(function () {
    try {
        var t = localStorage.getItem('theme');
        if (t !== 'light' && t !== 'dark') {
            t = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }
        document.documentElement.setAttribute('data-theme', t);
    } catch (e) {
        document.documentElement.setAttribute('data-theme', 'light');
    }
})();
</script>
<?php if (app()->module('Theme') !== null): ?>
<?php /* Same box as the sidebar logo, in the theme's accent. app.js swaps data-light/data-dark in as the theme changes. */ ?>
<link rel="icon" type="image/svg+xml" id="favicon"
      href="<?= e(url('/theme/favicon.svg') . '?mode=light&v=' . rawurlencode((string) $themeVersion)) ?>"
      data-light="<?= e(url('/theme/favicon.svg') . '?mode=light&v=' . rawurlencode((string) $themeVersion)) ?>"
      data-dark="<?= e(url('/theme/favicon.svg') . '?mode=dark&v=' . rawurlencode((string) $themeVersion)) ?>">
<?php else: ?>
<link rel="icon" type="image/svg+xml" href="<?= e(asset('favicon.svg')) ?>">
<?php endif; ?>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<?php if ($themeVersion !== null && $themeVersion !== ''): ?>
<link rel="stylesheet" href="<?= e(url('/theme/css') . '?v=' . rawurlencode($themeVersion)) ?>">
<?php endif; ?>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
