(function () {
    'use strict';

    var root = document.documentElement;

    // ----- Light/dark toggle -----
    document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            root.setAttribute('data-theme', next);
            try { localStorage.setItem('theme', next); } catch (e) { /* storage unavailable */ }
        });
    });

    // ----- Search boxes: clicking the browser's "x" (or pressing Esc) reloads the unfiltered list -----
    // Opt in with <input type="search" data-submit-on-clear> inside a GET form.
    document.querySelectorAll('input[data-submit-on-clear]').forEach(function (input) {
        // The "search" event fires on Enter, Esc and the clear button; defaultValue is what the server rendered.
        input.addEventListener('search', function () {
            if (input.value === '' && input.defaultValue !== '' && input.form) {
                input.form.requestSubmit();
            }
        });
    });

    // ----- Selects that reload their GET form when changed: <select data-autosubmit> -----
    document.querySelectorAll('select[data-autosubmit]').forEach(function (select) {
        select.addEventListener('change', function () {
            if (select.form) { select.form.requestSubmit(); }
        });
    });

    // ----- List selection + bulk action bar -----
    // <form data-bulk-form data-total="N"> with row checkboxes name="ids[]", a header checkbox
    // [data-select-all], a hidden input name="select_all", and a bar [data-bulk-bar] holding
    // [data-bulk-count] and (optionally) a [data-bulk-all] "select everything matching" button.
    document.querySelectorAll('form[data-bulk-form]').forEach(function (form) {
        var boxes = Array.prototype.slice.call(form.querySelectorAll('input[name="ids[]"]'));
        var master = form.querySelector('[data-select-all]');
        var bar = form.querySelector('[data-bulk-bar]');
        var count = form.querySelector('[data-bulk-count]');
        var allBtn = form.querySelector('[data-bulk-all]');
        var allField = form.querySelector('input[name="select_all"]');
        var total = parseInt(form.getAttribute('data-total'), 10) || boxes.length;
        if (!bar || !master) { return; }

        function plural(n) { return n.toLocaleString() + (n === 1 ? ' asset' : ' assets'); }

        function update() {
            var n = boxes.filter(function (b) { return b.checked; }).length;
            var everything = allField.value === '1';
            bar.hidden = n === 0;
            master.checked = n > 0 && n === boxes.length;
            master.indeterminate = n > 0 && n < boxes.length;
            if (n === 0) { allField.value = ''; everything = false; }
            count.textContent = everything ? 'All ' + plural(total) + ' selected' : plural(n) + ' selected';
            // Offer "select all N matching" once the whole page is ticked and there are more pages.
            allBtn.hidden = everything || !(master.checked && total > boxes.length);
        }

        master.addEventListener('change', function () {
            boxes.forEach(function (b) { b.checked = master.checked; });
            allField.value = '';
            update();
        });
        boxes.forEach(function (b) {
            b.addEventListener('change', function () { allField.value = ''; update(); });
        });
        allBtn.addEventListener('click', function () { allField.value = '1'; update(); });
        update();
    });

    // ----- File inputs with a size limit: <input type="file" data-max-bytes="N"> in a form with [data-upload-warning] -----
    document.querySelectorAll('input[type="file"][data-max-bytes]').forEach(function (input) {
        var max = parseInt(input.getAttribute('data-max-bytes'), 10) || 0;
        var form = input.form;
        var warning = form && form.querySelector('[data-upload-warning]');
        var submit = form && form.querySelector('button[type="submit"]');
        if (!max || !warning) { return; }
        input.addEventListener('change', function () {
            var tooBig = input.files.length > 0 && input.files[0].size > max;
            warning.hidden = !tooBig;
            if (submit) { submit.disabled = tooBig; }
        });
    });

    // ----- Mobile sidebar -----
    var body = document.body;
    document.querySelectorAll('[data-nav-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function () { body.classList.toggle('nav-open'); });
    });
    document.querySelectorAll('[data-nav-close]').forEach(function (el) {
        el.addEventListener('click', function () { body.classList.remove('nav-open'); });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { body.classList.remove('nav-open'); }
    });
})();
