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
