(function ($, R) {
    'use strict';

    const id = $('#recipe').attr('data-id');

    $('.ingredient-list li, .step-list li').on('click', function () { $(this).toggleClass('done'); });
    $('#btnPrint').on('click', function () { window.print(); });

    // Keep the screen on while cooking. The button stays hidden when the browser has no Screen
    // Wake Lock API (it also requires a secure context).
    const $wakeLockButton = $('#btnWakeLock');
    let wakeLock = null;
    let wakeLockRequest = null;
    let wakeLockWanted = false;

    function showWakeLockState() {
        const on = wakeLock !== null;
        $wakeLockButton
            .toggleClass('active', on)
            .attr('aria-pressed', on ? 'true' : 'false')
            .html('<i class="bi ' + (on ? 'bi-lightbulb-fill' : 'bi-lightbulb') + '"></i> ' + (on ? 'Screen stays on' : 'Keep screen on'));
    }

    async function requestWakeLock() {
        if (!wakeLockWanted || wakeLock !== null || wakeLockRequest !== null) return;

        try {
            wakeLockRequest = navigator.wakeLock.request('screen');
            const sentinel = await wakeLockRequest;
            wakeLockRequest = null;

            if (!wakeLockWanted) {
                sentinel.release().catch(function () {});
                return;
            }

            wakeLock = sentinel;
            sentinel.addEventListener('release', function () {
                wakeLock = null;
                showWakeLockState();
                // A lock is released automatically when the page is hidden; acquire it again
                // when the recipe comes back into view.
                if (wakeLockWanted && document.visibilityState === 'visible') requestWakeLock();
            });
            showWakeLockState();
        } catch (err) {
            wakeLockRequest = null;
            wakeLock = null;
            wakeLockWanted = false;
            showWakeLockState();
            R.toast('Could not keep the screen on: ' + (err && err.message ? err.message : 'not allowed'), 'danger');
        }
    }

    function releaseWakeLock() {
        wakeLockWanted = false;
        if (wakeLock) {
            wakeLock.release().catch(function () {});
            wakeLock = null;
        }
        showWakeLockState();
    }

    if ('wakeLock' in navigator) {
        $wakeLockButton.removeClass('d-none').on('click', function () {
            wakeLockWanted = !wakeLockWanted;
            if (wakeLockWanted) requestWakeLock();
            else releaseWakeLock();
        });

        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') requestWakeLock();
        });
    }

    const deleteModal = document.getElementById('deleteModal');
    const modal = deleteModal ? new bootstrap.Modal(deleteModal) : null;
    $('.js-delete-recipe').on('click', function () { if (modal) modal.show(); });

    $('#btnConfirmDelete').on('click', function () {
        const $btn = $(this).prop('disabled', true);
        R.api('POST', '/api/recipes/' + encodeURIComponent(id) + '/delete')
            .done(function () {
                R.flash('Recipe deleted.');
                location.href = R.base + '/';
            })
            .fail(function (xhr) {
                if (modal) modal.hide();
                R.toast(R.errorMessages(xhr).join(' '), 'danger');
                $btn.prop('disabled', false);
            });
    });
})(jQuery, window.Recipes);
