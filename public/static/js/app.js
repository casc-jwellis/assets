(function () {
    'use strict';

    var root = document.documentElement;

    // ----- Tab icon: the light or dark version of the theme's accent colour (see ThemeModule::favicon) -----
    function setFavicon() {
        var link = document.getElementById('favicon');
        var href = link && link.getAttribute('data-' + (root.getAttribute('data-theme') === 'dark' ? 'dark' : 'light'));
        if (href && link.getAttribute('href') !== href) { link.setAttribute('href', href); }
    }
    setFavicon();

    // ----- Light/dark toggle -----
    document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            root.setAttribute('data-theme', next);
            setFavicon();
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

    // ----- Live search and filters: <input type="search" data-live-search> inside a GET form -----
    // Fetches the results a moment after typing stops and swaps in the [data-live-region] elements of the new page,
    // so the box keeps focus. Controls with data-live-filter (they may sit outside the form and name it with form="...")
    // refresh the list the same way when they change. Anything unexpected falls back to an ordinary form submit.
    document.querySelectorAll('input[data-live-search]').forEach(function (input) {
        var form = input.form;
        if (!form || !window.fetch || !window.DOMParser) { return; }
        var timer = null;
        var controller = null;

        // What the form would send, without the empty boxes, so the address stays short.
        function serialize() {
            var params = new URLSearchParams(new FormData(form));
            params.set('q', input.value.trim());
            Array.from(params.keys()).forEach(function (key) {
                if (params.get(key) === '') { params.delete(key); }
            });
            return params.toString();
        }
        var last = serialize();

        function fallback() { form.requestSubmit(); }

        function run() {
            var query = serialize();
            if (query === last) { return; }
            last = query;
            var url = form.getAttribute('action') + (query ? '?' + query : '');
            if (controller) { controller.abort(); }
            controller = new AbortController();
            var mine = controller;
            fetch(url, { credentials: 'same-origin', signal: mine.signal, headers: { 'X-Requested-With': 'fetch' } })
                .then(function (res) {
                    if (!res.ok || res.redirected) { throw new Error('unexpected response'); }
                    return res.text();
                })
                .then(function (html) {
                    var doc = new DOMParser().parseFromString(html, 'text/html');
                    var fresh = doc.querySelectorAll('[data-live-region]');
                    var stale = document.querySelectorAll('[data-live-region]');
                    if (fresh.length === 0 || fresh.length !== stale.length) { throw new Error('no results area'); }
                    stale.forEach(function (el, i) { el.replaceWith(document.importNode(fresh[i], true)); });
                    initBulk(document);
                    try { history.replaceState(null, '', url); } catch (e) { /* not essential */ }
                })
                .catch(function (err) {
                    if (err && err.name === 'AbortError') { return; } // a newer search replaced this one
                    fallback();
                });
        }

        function schedule() {
            clearTimeout(timer);
            timer = setTimeout(run, 250);
        }

        function isMine(el) {
            return el === input || (el.form === form && el.hasAttribute && el.hasAttribute('data-live-filter'));
        }
        document.addEventListener('input', function (ev) { if (isMine(ev.target)) { schedule(); } });
        document.addEventListener('change', function (ev) { if (isMine(ev.target)) { schedule(); } });
        form.addEventListener('live-refresh', schedule);
        // A normal submit (Enter, or the Show menu) does the same thing straight away, minus the empty boxes.
        form.addEventListener('submit', function () { clearTimeout(timer); if (controller) { controller.abort(); } });
        form.addEventListener('formdata', function (ev) {
            Array.from(ev.formData.keys()).forEach(function (key) {
                if (ev.formData.get(key) === '') { ev.formData.delete(key); }
            });
        });
    });

    // ----- Show or hide a panel: <button data-toggle="panel-id"> -----
    document.querySelectorAll('[data-toggle]').forEach(function (btn) {
        var panel = document.getElementById(btn.getAttribute('data-toggle'));
        if (!panel) { return; }
        btn.addEventListener('click', function () {
            panel.hidden = !panel.hidden;
            btn.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
        });
    });

    // ----- Pick-from-a-list filters: <details class="pick"> with checkboxes; the summary says how many are ticked -----
    function pickText(details) {
        var n = details.querySelectorAll('input:checked').length;
        var text = details.querySelector('[data-pick-text]');
        if (text) { text.textContent = n === 0 ? 'Any' : n + ' selected'; }
    }
    document.querySelectorAll('details.pick').forEach(function (details) {
        details.addEventListener('change', function () { pickText(details); });
    });

    // ----- "Clear filters": empties every [data-live-filter] control in the panel, then refreshes the list -----
    document.querySelectorAll('[data-filter-clear]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var panel = btn.closest('section');
            if (!panel) { return; }
            var form = null;
            panel.querySelectorAll('[data-live-filter]').forEach(function (el) {
                form = el.form || form;
                if (el.type === 'checkbox') { el.checked = false; } else if (el.tagName === 'SELECT') { el.selectedIndex = 0; } else { el.value = ''; }
            });
            panel.querySelectorAll('details.pick').forEach(pickText);
            if (form) { form.dispatchEvent(new Event('live-refresh')); }
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
    // Run again on the new list after a live search swaps it in.
    function initBulk(scope) {
        scope.querySelectorAll('form[data-bulk-form]').forEach(function (form) {
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
    }
    initBulk(document);

    // ----- File inputs with a size limit: <input type="file" data-max-bytes="N"> in a form with [data-upload-warning] -----
    document.querySelectorAll('input[type="file"][data-max-bytes]').forEach(function (input) {
        var max = parseInt(input.getAttribute('data-max-bytes'), 10) || 0;
        var form = input.form;
        var warning = form && form.querySelector('[data-upload-warning]');
        var submit = form && form.querySelector('button[type="submit"]');
        if (!max || !warning) { return; }
        // Optional extras for multi-file inputs: data-max-total (all files together) and data-max-count.
        var maxTotal = parseInt(input.getAttribute('data-max-total'), 10) || 0;
        var maxCount = parseInt(input.getAttribute('data-max-count'), 10) || 0;
        input.addEventListener('change', function () {
            var total = 0;
            var tooBig = false;
            Array.prototype.forEach.call(input.files, function (f) {
                total += f.size;
                if (f.size > max) { tooBig = true; }
            });
            if ((maxTotal && total > maxTotal) || (maxCount && input.files.length > maxCount)) { tooBig = true; }
            warning.hidden = !tooBig;
            if (submit) { submit.disabled = tooBig; }
        });
    });

    // ----- Drag-and-drop onto a file input: <div data-dropzone> around an <input type="file"> -----
    // Plain choosing still works; dropped files are added to whatever is already selected. The names are
    // listed in [data-file-list] (as text, never HTML).
    document.querySelectorAll('[data-dropzone]').forEach(function (zone) {
        var input = zone.querySelector('input[type="file"]');
        var list = zone.querySelector('[data-file-list]');
        var text = zone.querySelector('[data-dropzone-text]');
        if (!input) { return; }
        var canDrop = typeof DataTransfer === 'function';
        if (canDrop && text) { text.textContent = 'Drag and drop files here, or choose them'; }

        function size(bytes) {
            if (bytes < 1024) { return bytes + ' bytes'; }
            if (bytes < 1048576) { return (bytes / 1024).toFixed(0) + ' KB'; }
            return (bytes / 1048576).toFixed(1) + ' MB';
        }
        function show() {
            if (!list) { return; }
            list.textContent = '';
            Array.prototype.forEach.call(input.files, function (f) {
                var li = document.createElement('li');
                li.textContent = f.name + ' (' + size(f.size) + ')';
                list.appendChild(li);
            });
            list.hidden = input.files.length === 0;
        }
        input.addEventListener('change', show);

        if (!canDrop) { return; }
        ['dragenter', 'dragover'].forEach(function (type) {
            zone.addEventListener(type, function (ev) {
                ev.preventDefault();
                zone.classList.add('is-over');
            });
        });
        ['dragleave', 'dragend'].forEach(function (type) {
            zone.addEventListener(type, function (ev) {
                if (!ev.relatedTarget || !zone.contains(ev.relatedTarget)) { zone.classList.remove('is-over'); }
            });
        });
        zone.addEventListener('drop', function (ev) {
            ev.preventDefault();
            zone.classList.remove('is-over');
            var dropped = ev.dataTransfer && ev.dataTransfer.files;
            if (!dropped || dropped.length === 0) { return; }
            var merged = new DataTransfer();
            var seen = {};
            function add(f) {
                var key = f.name + '|' + f.size + '|' + f.lastModified; // the same file dropped twice is added once
                if (!seen[key]) { seen[key] = true; merged.items.add(f); }
            }
            Array.prototype.forEach.call(input.files, add);
            Array.prototype.forEach.call(dropped, add);
            input.files = merged.files;
            input.dispatchEvent(new Event('change', { bubbles: true })); // size checks and the list update
        });
    });

    // ----- Department access: an "All departments" row that sets None / Read / Read & write for every row -----
    // <table data-perm-table> holding radios named perm[...] and a hidden <tr data-perm-all> whose unnamed radios
    // carry data-perm-all-level="" | "r" | "rw". The row stays hidden (the form still works) without this script.
    document.querySelectorAll('[data-perm-table]').forEach(function (table) {
        var allRow = table.querySelector('[data-perm-all]');
        if (!allRow) { return; }
        var masters = allRow.querySelectorAll('input[data-perm-all-level]');
        var rows = function () { return table.querySelectorAll('input[type="radio"][name^="perm["]:not(:disabled)'); };

        // The master radio is ticked only while every department has the same level.
        function sync() {
            var levels = {};
            rows().forEach(function (r) { if (r.checked) { levels[r.value] = true; } });
            var keys = Object.keys(levels);
            masters.forEach(function (m) { m.checked = keys.length === 1 && m.getAttribute('data-perm-all-level') === keys[0]; });
        }

        masters.forEach(function (m) {
            m.addEventListener('change', function () {
                var level = m.getAttribute('data-perm-all-level');
                rows().forEach(function (r) { r.checked = r.value === level; });
                sync();
            });
        });
        table.addEventListener('change', function (ev) {
            if (ev.target.name && ev.target.name.indexOf('perm[') === 0) { sync(); }
        });
        allRow.hidden = false;
        sync();
    });

    // ----- Toasts: messages from the previous request (and window.toast) that fade away on their own -----
    // The server leaves them as JSON in <script type="application/json" id="flash-data"> (templates/partials-flashes.php),
    // shown inside <div id="toasts">. Text is always set with textContent, never as HTML. The bar along the bottom is the
    // time left; hovering or focusing a toast pauses it, as does the tab being in the background.
    (function () {
        var box = document.getElementById('toasts');
        if (!box) { return; }
        var ICON = { success: '✓', info: 'i', warning: '!', danger: '×' };
        var LABEL = { success: 'Success', info: 'Information', warning: 'Warning', danger: 'Error' };
        var MS = { success: 5000, info: 6000, warning: 8000, danger: 8000 };
        var MAX = 5; // never more than this many on screen at once; the oldest goes first

        function toast(type, message) {
            if (!ICON[type]) { type = 'info'; }
            var el = document.createElement('div');
            el.className = 'toast toast-' + type;
            el.setAttribute('role', type === 'danger' ? 'alert' : 'status');

            var icon = document.createElement('span');
            icon.className = 'toast-icon';
            icon.setAttribute('aria-hidden', 'true');
            icon.textContent = ICON[type];

            var msg = document.createElement('div');
            msg.className = 'toast-msg';
            var kind = document.createElement('span');
            kind.className = 'sr-only';
            kind.textContent = LABEL[type] + ': ';
            msg.appendChild(kind);
            msg.appendChild(document.createTextNode(String(message)));

            var close = document.createElement('button');
            close.type = 'button';
            close.className = 'toast-close';
            close.setAttribute('aria-label', 'Dismiss');
            close.textContent = '×';

            var bar = document.createElement('div');
            bar.className = 'toast-bar';
            bar.style.animationDuration = MS[type] + 'ms';

            el.appendChild(icon);
            el.appendChild(msg);
            el.appendChild(close);
            el.appendChild(bar);

            var left = MS[type], started = 0, timer = null, leaving = false;
            function leave() {
                if (leaving) { return; }
                leaving = true;
                clearTimeout(timer);
                el.classList.add('is-leaving');
                setTimeout(function () { if (el.parentNode) { el.parentNode.removeChild(el); } }, 220);
            }
            function run() {
                if (leaving || timer !== null) { return; }
                started = Date.now();
                timer = setTimeout(leave, left);
                bar.style.animationPlayState = 'running';
            }
            function pause() {
                if (timer === null) { return; }
                clearTimeout(timer);
                timer = null;
                left = Math.max(600, left - (Date.now() - started));
                bar.style.animationPlayState = 'paused';
            }
            var holding = false;
            function hold(on) { holding = on; if (on || document.hidden) { pause(); } else { run(); } }
            el.addEventListener('mouseenter', function () { hold(true); });
            el.addEventListener('mouseleave', function () { hold(false); });
            el.addEventListener('focusin', function () { hold(true); });
            el.addEventListener('focusout', function () { hold(false); });
            document.addEventListener('visibilitychange', function () { if (document.hidden) { pause(); } else if (!holding) { run(); } });
            close.addEventListener('click', leave);

            box.appendChild(el);
            while (box.children.length > MAX) { box.removeChild(box.firstChild); }
            if (!document.hidden) { run(); } else { bar.style.animationPlayState = 'paused'; }
        }
        window.toast = toast;

        var data = document.getElementById('flash-data');
        if (data) {
            try {
                JSON.parse(data.textContent).forEach(function (f) { toast(f.type, f.message); });
            } catch (e) { /* unreadable: the <noscript> copy does not show with scripting on, so say it plainly */
                toast('info', 'A message for you could not be shown.');
            }
        }
    })();

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
