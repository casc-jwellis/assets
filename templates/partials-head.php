<?php
/** Shared <head> contents. Expects $title (string|null). */
$appName = (string) app()->config->get('app.name', 'Asset Manager');
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
<link rel="icon" type="image/svg+xml" href="<?= e(asset('favicon.svg')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
