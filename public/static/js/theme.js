/*
 * Theme page: picking a palette fills the colour pickers, and the preview and readability notes follow every change.
 *
 * The colour maths (derive, mix, luminance, contrast) mirrors modules/Theme/ThemeColors.php, which is what the
 * server uses for the real stylesheet and for refusing unreadable combinations. Change them together.
 * Colours are applied through the DOM (element.style), because the content security policy allows no inline styles.
 */
(function () {
    'use strict';

    var page = document.querySelector('[data-theme-page]');
    if (!page) { return; }

    var palettes = {};
    try { palettes = JSON.parse(page.getAttribute('data-palettes') || '{}'); } catch (e) { palettes = {}; }
    var MIN = parseFloat(page.getAttribute('data-min-contrast')) || 4.5;
    var ROLES = ['accent', 'bg', 'surface', 'sidebar', 'text'];
    var MODES = ['light', 'dark'];

    // ----- colour maths -----
    function rgb(h) { return [parseInt(h.slice(1, 3), 16), parseInt(h.slice(3, 5), 16), parseInt(h.slice(5, 7), 16)]; }
    function hex(c) {
        return '#' + c.map(function (v) {
            v = Math.max(0, Math.min(255, Math.round(v)));
            return (v < 16 ? '0' : '') + v.toString(16);
        }).join('');
    }
    function mix(a, b, t) {
        var x = rgb(a), y = rgb(b);
        return hex([0, 1, 2].map(function (i) { return x[i] * (1 - t) + y[i] * t; }));
    }
    function lum(h) {
        var c = rgb(h).map(function (v) {
            v /= 255;
            return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
        });
        return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
    }
    function contrast(a, b) {
        var l1 = lum(a), l2 = lum(b);
        return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
    }
    function derive(c, dark) {
        var surface2 = mix(c.surface, c.text, dark ? 0.04 : 0.06);
        var muted = c.text;
        for (var i = 10; i <= 20; i++) {
            var m = mix(c.bg, c.text, i / 20);
            if (contrast(m, c.bg) >= MIN && contrast(m, c.surface) >= MIN && contrast(m, surface2) >= MIN) { muted = m; break; }
        }
        return {
            'bg': c.bg,
            'surface': c.surface,
            'surface-2': surface2,
            'border': mix(c.surface, c.text, dark ? 0.11 : 0.12),
            'text': c.text,
            'muted': muted,
            'accent': c.accent,
            'accent-hover': dark ? mix(c.accent, '#ffffff', 0.22) : mix(c.accent, '#000000', 0.18),
            'accent-contrast': contrast(c.accent, '#ffffff') >= contrast(c.accent, '#0d1117') ? '#ffffff' : '#0d1117',
            'accent-soft': mix(c.surface, c.accent, dark ? 0.14 : 0.09),
            'sidebar-bg': c.sidebar
        };
    }
    function checks(c, dark) {
        var d = derive(c, dark);
        return [
            ['Text on the page background', c.text, c.bg],
            ['Text on cards', c.text, c.surface],
            ['Text on the sidebar', c.text, c.sidebar],
            ['Accent on the page background', c.accent, c.bg],
            ['Accent on cards', c.accent, c.surface],
            ['Accent on the sidebar', c.accent, c.sidebar],
            ['Accent on the highlighted menu item', c.accent, d['accent-soft']]
        ].map(function (p) { var r = contrast(p[1], p[2]); return { label: p[0], ratio: r, ok: r >= MIN }; });
    }

    // ----- the page -----
    var inputs = {};
    MODES.forEach(function (mode) {
        inputs[mode] = {};
        ROLES.forEach(function (role) {
            inputs[mode][role] = page.querySelector('input[type="color"][data-mode="' + mode + '"][data-role="' + role + '"]');
        });
    });
    var radios = Array.prototype.slice.call(page.querySelectorAll('input[name="palette"]'));

    page.querySelectorAll('[data-swatch]').forEach(function (s) { s.style.backgroundColor = s.getAttribute('data-swatch'); });

    function read(mode) {
        var c = {};
        ROLES.forEach(function (role) { c[role] = inputs[mode][role].value.toLowerCase(); });
        return c;
    }

    function markSelected() {
        radios.forEach(function (r) { r.parentNode.classList.toggle('is-selected', r.checked); });
    }

    function update() {
        MODES.forEach(function (mode) {
            var c = read(mode), dark = mode === 'dark';
            var pv = page.querySelector('[data-pv="' + mode + '"]');
            var vars = derive(c, dark);
            Object.keys(vars).forEach(function (k) { pv.style.setProperty('--' + k, vars[k]); });
            ROLES.forEach(function (role) {
                var code = page.querySelector('[data-hex="' + mode + '-' + role + '"]');
                if (code) { code.textContent = c[role]; }
            });
            var bad = checks(c, dark).filter(function (x) { return !x.ok; });
            var note = page.querySelector('[data-contrast="' + mode + '"]');
            note.classList.toggle('is-bad', bad.length > 0);
            note.textContent = (mode === 'dark' ? 'Dark' : 'Light') + ' mode: ' + (bad.length === 0
                ? 'all text and accent colours are easy to read.'
                : bad.map(function (x) { return x.label + ' is hard to read (' + x.ratio.toFixed(1) + ':1, needs ' + MIN + ':1)'; }).join('; ') + '.');
        });
        var name = page.querySelector('#site_name');
        page.querySelectorAll('[data-pv-name]').forEach(function (n) { n.textContent = name.value; });
    }

    function usePalette(key) {
        var p = palettes[key];
        if (!p) { return; }
        MODES.forEach(function (mode) {
            ROLES.forEach(function (role) { inputs[mode][role].value = p[mode][role]; });
        });
        update();
    }

    radios.forEach(function (r) {
        r.addEventListener('change', function () {
            if (r.checked && r.value !== 'custom') { usePalette(r.value); }
            markSelected();
        });
    });
    // Editing any colour by hand makes it a custom palette.
    page.querySelectorAll('input[type="color"]').forEach(function (input) {
        input.addEventListener('input', function () {
            radios.forEach(function (r) { r.checked = r.value === 'custom'; });
            markSelected();
            update();
        });
    });
    page.querySelector('#site_name').addEventListener('input', update);

    update();
}());
