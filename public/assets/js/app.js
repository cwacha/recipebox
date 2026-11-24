window.Recipes = (function ($) {
    'use strict';

    const BASE = window.APP.base;
    const csrf = $('meta[name="csrf-token"]').attr('content');
    if (csrf) {
        $.ajaxSetup({headers: {'X-CSRF-Token': csrf}});
    }
    const ESC = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'};

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ESC[c]; });
    }

    function api(method, url, data) {
        const opts = {url: BASE + url, method: method, dataType: 'json'};
        if (data !== undefined) {
            opts.contentType = 'application/json';
            opts.data = JSON.stringify(data);
        }
        return $.ajax(opts);
    }

    function errorMessages(xhr) {
        const json = xhr.responseJSON;
        if (json && json.errors) {
            return Object.keys(json.errors).map(function (k) { return json.errors[k]; });
        }
        if (json && json.error) return [json.error];
        return [xhr.status ? 'Request failed (' + xhr.status + ').' : 'Server not reachable.'];
    }

    function toast(message, type) {
        const $t = $(
            '<div class="toast align-items-center text-bg-' + (type || 'success') + ' border-0" role="status">' +
            '<div class="d-flex"><div class="toast-body">' + esc(message) + '</div>' +
            '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div></div>'
        );
        $('#toasts').append($t);
        $t.on('hidden.bs.toast', function () { $t.remove(); });
        new bootstrap.Toast($t[0], {delay: type === 'warning' || type === 'danger' ? 12000 : 3500}).show();
    }

    /** Shows a message on the next page, e.g. after a redirect. */
    function flash(message, type) {
        sessionStorage.setItem('flash', JSON.stringify({message: message, type: type || 'success'}));
    }

    function showAlert($el, messages) {
        $el.html(messages.map(esc).join('<br>')).removeClass('d-none');
    }

    function applyThemeIcon() {
        const dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
        $('#btnTheme i').attr('class', 'bi ' + (dark ? 'bi-sun' : 'bi-moon-stars'));
    }

    $('#btnTheme').on('click', function () {
        const next = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-bs-theme', next);
        localStorage.setItem('theme', next);
        applyThemeIcon();
    });

    applyThemeIcon();

    // messages the server queued for this visit (after signing in, a denied page, saving in the admin, ...)
    (window.APP.flash || []).forEach(function (f) { toast(f.message, f.type); });

    const pending = sessionStorage.getItem('flash');
    if (pending) {
        sessionStorage.removeItem('flash');
        try {
            const f = JSON.parse(pending);
            toast(f.message, f.type);
        } catch (e) { /* ignore */ }
    }

    return {base: BASE, esc: esc, api: api, errorMessages: errorMessages, toast: toast, flash: flash, showAlert: showAlert};
})(jQuery);
