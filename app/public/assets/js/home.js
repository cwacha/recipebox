/* Live search: the page works as a plain GET form; with JS the results are swapped in as you type. */
(function ($) {
    'use strict';

    const $form = $('#searchForm');
    const $input = $('#search');
    const $clear = $('#searchClear');
    let timer = null;
    let request = null;

    function refresh() {
        const params = $form.serializeArray().filter(function (p) { return p.value.trim() !== ''; });
        const url = location.pathname + (params.length ? '?' + $.param(params) : '');
        if (request) request.abort();

        request = $.ajax({url: url, dataType: 'html'}).done(function (html) {
            const $new = $($.parseHTML(html)).filter('#results');
            if ($new.length) {
                $('#results').replaceWith($new);
                history.replaceState(null, '', url);
            }
        });
    }

    $form.on('submit', function (e) {
        e.preventDefault();
        clearTimeout(timer);
        refresh();
    });

    $input.on('input', function () {
        $clear.toggleClass('d-none', $input.val() === '');
        clearTimeout(timer);
        timer = setTimeout(refresh, 220);
    });

    $clear.on('click', function () {
        $input.val('').trigger('input').trigger('focus');
    });
})(jQuery);
