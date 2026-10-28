<?php
/**
 * The messages left by the previous request ("User created.", "The change was saved, but ..."), shown as toasts.
 * Expects $flashes (list of ['type' => success|info|warning|danger, 'message' => string]).
 *
 * The page carries them as inert JSON (a non-executable script block: no CSP exception needed, and the text can never
 * be read as HTML) and app.js turns them into toasts that fade away. With scripting off, <noscript> shows the same
 * messages as ordinary boxes at the top of the page, so nothing is ever lost.
 */
$allowed = ['success', 'info', 'warning', 'danger'];
$items = [];
foreach ($flashes as $f) {
    $items[] = ['type' => in_array($f['type'], $allowed, true) ? $f['type'] : 'info', 'message' => (string) $f['message']];
}
?>
<div class="toasts" id="toasts" aria-live="polite" aria-relevant="additions"></div>
<?php if ($items !== []): ?>
    <script type="application/json" id="flash-data"><?= json_encode($items, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]' ?></script>
    <noscript>
        <?php foreach ($items as $f): ?>
            <div class="alert alert-<?= e($f['type']) ?>" role="status"><?= e($f['message']) ?></div>
        <?php endforeach; ?>
    </noscript>
<?php endif; ?>
