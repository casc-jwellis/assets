<?php
/**
 * The Theme page. Expects $ready, $v (name, palette, colors), $errors, $palettes (key => label/light/dark),
 * $roles (role => label), $nameMax, $minContrast.
 *
 * Colours are applied from public/static/js/theme.js (the page's content security policy allows no inline
 * styles, so swatches and the preview are coloured through the DOM, not through style attributes).
 */
$modes = ['light' => 'Light mode', 'dark' => 'Dark mode'];
$selected = $v['palette'];
?>
<div class="narrow-col" data-theme-page data-min-contrast="<?= e($minContrast) ?>"
     data-palettes="<?= e(json_encode($palettes, JSON_UNESCAPED_SLASHES)) ?>">
<div class="page-head">
    <div>
        <h1>Theme</h1>
        <p class="muted">The site name and colours everyone sees, including the sign-in page.</p>
    </div>
</div>

<?php if (!$ready): ?>
    <div class="alert alert-danger" role="alert">
        The theme needs a database update. Apply migration <code>015_settings.sql</code> on the
        <a href="<?= e(url('/migrate')) ?>">Migrations page</a>.
    </div>
<?php endif; ?>
<?php foreach ($errors as $err): ?>
    <div class="alert alert-danger" role="alert"><?= e($err) ?></div>
<?php endforeach; ?>

<form method="post" action="<?= e(url('/theme')) ?>" autocomplete="off">
    <?= csrf_field() ?>

    <div class="card form-card wide">
        <h2>Site name</h2>
        <div class="field">
            <label for="site_name">Shown in the sidebar, on the sign-in page and in the browser tab</label>
            <input type="text" id="site_name" name="site_name" value="<?= e($v['name']) ?>" maxlength="<?= e($nameMax) ?>" required>
        </div>
    </div>

    <div class="card form-card wide section">
        <h2>Palette</h2>
        <p class="muted small">Pick a ready-made palette, then adjust any colour below if you like. Each palette has a light and a dark version.</p>
        <div class="palette-grid" role="radiogroup" aria-label="Palette">
            <?php foreach ($palettes as $key => $p): ?>
                <label class="palette<?= $selected === $key ? ' is-selected' : '' ?>">
                    <input type="radio" name="palette" value="<?= e($key) ?>"<?= $selected === $key ? ' checked' : '' ?>>
                    <?php foreach ($modes as $mode => $label): ?>
                        <span class="swatches" aria-hidden="true">
                            <?php foreach (array_keys($roles) as $role): ?>
                                <span data-swatch="<?= e($p[$mode][$role]) ?>"></span>
                            <?php endforeach; ?>
                        </span>
                    <?php endforeach; ?>
                    <span class="palette-name"><?= e($p['label']) ?></span>
                </label>
            <?php endforeach; ?>
            <label class="palette<?= $selected === 'custom' ? ' is-selected' : '' ?>">
                <input type="radio" name="palette" value="custom"<?= $selected === 'custom' ? ' checked' : '' ?>>
                <span class="palette-custom muted small">Colours you pick below that match none of the palettes.</span>
                <span class="palette-name">Custom</span>
            </label>
        </div>
    </div>

    <div class="card form-card wide section">
        <h2>Colours</h2>
        <p class="muted small">Hover colours, borders, muted text and tints are worked out from these five automatically.
            Text and the accent colour must stay easy to read (contrast of at least <?= e($minContrast) ?>:1), so a combination that is not will not save.</p>
        <div class="table-wrap">
            <table class="table colour-table">
                <thead><tr><th>Colour</th><?php foreach ($modes as $label): ?><th><?= e($label) ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                <?php foreach ($roles as $role => $roleLabel): ?>
                    <tr>
                        <td><?= e($roleLabel) ?></td>
                        <?php foreach ($modes as $mode => $label): ?>
                            <td>
                                <input type="color" name="<?= e($mode) ?>[<?= e($role) ?>]" value="<?= e($v['colors'][$mode][$role]) ?>"
                                       data-mode="<?= e($mode) ?>" data-role="<?= e($role) ?>" aria-label="<?= e($roleLabel . ', ' . strtolower($label)) ?>">
                                <code class="hex" data-hex="<?= e($mode) ?>-<?= e($role) ?>"><?= e($v['colors'][$mode][$role]) ?></code>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php foreach ($modes as $mode => $label): ?>
            <p class="contrast-note small" data-contrast="<?= e($mode) ?>" role="status"></p>
        <?php endforeach; ?>
    </div>

    <div class="card form-card wide section">
        <h2>Preview</h2>
        <div class="preview-grid" aria-hidden="true">
            <?php foreach ($modes as $mode => $label): ?>
                <div class="pv" data-pv="<?= e($mode) ?>">
                    <div class="pv-side">
                        <span class="pv-brand"><span class="pv-mark"></span><span data-pv-name><?= e($v['name']) ?></span></span>
                        <span class="pv-nav is-active">Assets</span>
                        <span class="pv-nav">Users</span>
                        <span class="pv-nav">Theme</span>
                    </div>
                    <div class="pv-main">
                        <div class="pv-card">
                            <strong>Asset A001</strong>
                            <p>Laptop in <span class="pv-link">Information Technology</span></p>
                            <p class="pv-muted">Added by Ada on 2026-10-13</p>
                            <p><span class="pv-btn">Edit</span> <span class="pv-badge"><?= e($label) ?></span></p>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary"<?= $ready ? '' : ' disabled' ?>>Save theme</button>
    </div>
</form>

<?php if ($ready): ?>
    <form method="post" action="<?= e(url('/theme/reset')) ?>" class="reset-form">
        <?= csrf_field() ?>
        <button type="submit" class="btn-link">Reset the colours to the default palette</button>
    </form>
<?php endif; ?>
</div>
<script src="<?= e(asset('js/theme.js')) ?>" defer></script>
