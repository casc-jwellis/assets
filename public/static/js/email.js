/*
 * Email page: a button that fills in Gmail's server settings, and the port hint that follows the connection type.
 */
(function () {
    'use strict';

    var page = document.querySelector('[data-email-page]');
    if (!page) { return; }

    var security = page.querySelector('#security');
    var port = page.querySelector('#port');
    var host = page.querySelector('#host');
    var usual = { starttls: '587', implicit: '465', none: '25' };

    function hint() { port.placeholder = usual[security.value] || '587'; }
    security.addEventListener('change', hint);
    hint();

    var gmail = page.querySelector('[data-preset-gmail]');
    if (gmail) {
        gmail.addEventListener('click', function () {
            host.value = 'smtp.gmail.com';
            security.value = 'starttls';
            port.value = '';
            hint();
        });
    }
}());
