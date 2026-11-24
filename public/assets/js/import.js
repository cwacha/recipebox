(function ($, R) {
    'use strict';

    function readFile(file) {
        if (!file) return;
        const reader = new FileReader();
        reader.onload = function () { $('#importText').val(reader.result); };
        reader.readAsText(file);
    }

    $('#importFile').on('change', function () { readFile(this.files[0]); });

    $('#dropzone')
        .on('dragover dragenter', function (e) { e.preventDefault(); $(this).addClass('dragover'); })
        .on('dragleave drop', function (e) { e.preventDefault(); $(this).removeClass('dragover'); })
        .on('drop', function (e) { readFile(e.originalEvent.dataTransfer.files[0]); });

    $('#btnDoImport').on('click', function () {
        const $err = $('#importError').addClass('d-none');
        let payload;
        try {
            payload = JSON.parse($('#importText').val());
        } catch (e) {
            R.showAlert($err, ['This is not valid JSON: ' + e.message]);
            return;
        }

        const $btn = $(this).prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Importing…');
        R.api('POST', '/api/recipes/import', payload)
            .done(function (res) {
                let message = res.imported + (res.imported === 1 ? ' recipe' : ' recipes') + ' imported.';
                const warnings = res.warnings || [];
                if (warnings.length) {
                    const more = warnings.length > 3 ? ' … and ' + (warnings.length - 3) + ' more.' : '';
                    R.flash(message + ' ' + warnings.slice(0, 3).join(' · ') + more, 'warning');
                } else {
                    R.flash(message);
                }
                location.href = res.imported === 1 ? R.base + '/recipes/' + encodeURIComponent(res.recipes[0].slug) : R.base + '/';
            })
            .fail(function (xhr) {
                R.showAlert($err, R.errorMessages(xhr));
                $btn.prop('disabled', false).html('<i class="bi bi-upload"></i> Import');
            });
    });
})(jQuery, window.Recipes);
