(function ($, R) {
    'use strict';

    const id = $('#recipe').attr('data-id');

    $('.ingredient-list li, .step-list li').on('click', function () { $(this).toggleClass('done'); });
    $('#btnPrint').on('click', function () { window.print(); });

    const modal = new bootstrap.Modal(document.getElementById('deleteModal'));
    $('#btnDelete').on('click', function () { modal.show(); });

    $('#btnConfirmDelete').on('click', function () {
        const $btn = $(this).prop('disabled', true);
        R.api('DELETE', '/api/recipes/' + encodeURIComponent(id))
            .done(function () {
                R.flash('Recipe deleted.');
                location.href = R.base + '/';
            })
            .fail(function (xhr) {
                modal.hide();
                R.toast(R.errorMessages(xhr).join(' '), 'danger');
                $btn.prop('disabled', false);
            });
    });
})(jQuery, window.Recipes);
