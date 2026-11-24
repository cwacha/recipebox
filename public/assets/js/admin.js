/* Asks for confirmation before submitting forms marked with data-confirm. */
(function ($) {
    'use strict';

    const $modal = $(
        '<div class="modal fade" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">' +
        '<div class="modal-body text-center p-4"><div class="display-4 text-danger"><i class="bi bi-trash3"></i></div>' +
        '<p class="mt-2 mb-4 confirm-text"></p>' +
        '<button type="button" class="btn btn-outline-secondary me-2" data-bs-dismiss="modal">Cancel</button>' +
        '<button type="button" class="btn btn-danger confirm-yes">Delete</button></div></div></div></div>'
    ).appendTo('body');
    const modal = new bootstrap.Modal($modal[0]);
    let pending = null;

    $(document).on('submit', 'form[data-confirm]', function (e) {
        if (this.dataset.confirmed === '1') return;
        e.preventDefault();
        pending = this;
        $modal.find('.confirm-text').text($(this).attr('data-confirm'));
        modal.show();
    });

    $modal.on('click', '.confirm-yes', function () {
        if (!pending) return;
        pending.dataset.confirmed = '1';
        pending.submit();
    });
})(jQuery);
