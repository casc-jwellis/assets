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
