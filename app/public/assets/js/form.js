(function ($, R) {
    'use strict';

    const $form = $('#editForm');
    const id = $form.attr('data-id');
    let drag = null;

    function ingredientRow() {
        return $('<div class="row-item">' +
            '<input class="form-control qty-input ing-qty" placeholder="Amount" maxlength="40">' +
            '<input class="form-control ing-name" placeholder="Ingredient" maxlength="150">' +
            '<button type="button" class="btn btn-outline-secondary btn-sm row-drag" title="Drag to reorder" aria-label="Drag to reorder"><i class="bi bi-list"></i></button>' +
            '<button type="button" class="btn btn-outline-secondary btn-sm btn-remove" title="Remove" aria-label="Remove"><i class="bi bi-x-lg"></i></button></div>');
    }

    function stepRow() {
        return $('<div class="row-item">' +
            '<span class="num"></span>' +
            '<textarea class="form-control step-text-input" rows="2" placeholder="Describe this step…"></textarea>' +
            '<button type="button" class="btn btn-outline-secondary btn-sm row-drag" title="Drag to reorder" aria-label="Drag to reorder"><i class="bi bi-list"></i></button>' +
            '<button type="button" class="btn btn-outline-secondary btn-sm btn-remove" title="Remove" aria-label="Remove"><i class="bi bi-x-lg"></i></button></div>');
    }

    function renumber() {
        $('#stepRows .row-item').each(function (i) { $(this).find('.num').text(i + 1); });
    }

    function imgUrl(path) {
        if (!path) return null;
        return /^https?:\/\//i.test(path) ? path : R.base + '/' + path;
    }

    function updatePreview() {
        const src = imgUrl($('#f-image').val().trim());
        $('#imagePreviewWrap').prop('hidden', !src);
        if (src) $('#imagePreview').attr('src', src);
    }

    function clearErrors() {
        $('#editError').addClass('d-none');
        $form.find('.is-invalid').removeClass('is-invalid');
        $form.find('.invalid-feedback').text('');
    }

    function collect() {
        return {
            title: $('#f-title').val(),
            cooking_time: $('#f-time').val(),
            category: $('#f-category').val(),
            description: $('#f-description').val(),
            image: $('#f-image').val(),
            tags: $('#f-tags').val(),
            ingredients: $('#ingredientRows .row-item').map(function () {
                return {quantity: $(this).find('.ing-qty').val(), name: $(this).find('.ing-name').val()};
            }).get(),
            steps: $('#stepRows .step-text-input').map(function () { return $(this).val(); }).get()
        };
    }

    function showFormErrors(xhr) {
        const errors = xhr.responseJSON && xhr.responseJSON.errors;
        if (!errors) {
            R.showAlert($('#editError'), R.errorMessages(xhr));
            return;
        }
        const fields = {title: '#f-title', cooking_time: '#f-time'};
        const blocks = {image: '#f-image-error', ingredients: '#f-ingredients-error', steps: '#f-steps-error'};
        Object.keys(errors).forEach(function (key) {
            if (fields[key]) {
                $(fields[key]).addClass('is-invalid').siblings('.invalid-feedback').text(errors[key]);
            } else if (blocks[key]) {
                $(blocks[key]).text(errors[key]);
                if (key === 'image') $('#f-image').addClass('is-invalid');
            }
        });
        R.showAlert($('#editError'), ['Please fix the highlighted fields.']);
        window.scrollTo({top: 0, behavior: 'smooth'});
    }

    $form.on('submit', function (e) {
        e.preventDefault();
        clearErrors();
        const remote = /^https?:\/\//i.test($('#f-image').val().trim());
        const $btn = $('#btnSave').prop('disabled', true);
        if (remote) $btn.html('<span class="spinner-border spinner-border-sm"></span> Downloading picture…');

        R.api('POST', id ? '/api/recipes/' + encodeURIComponent(id) : '/api/recipes', collect())
            .done(function (r) {
                saved = true;
                const base = id ? 'Recipe updated.' : 'Recipe created.';
                if (r.warnings && r.warnings.length) R.flash(base + ' ' + r.warnings.join(' '), 'warning');
                else R.flash(base);
                location.href = R.base + '/recipes/' + encodeURIComponent(r.slug);
            })
            .fail(function (xhr) {
                showFormErrors(xhr);
                $btn.prop('disabled', false).html('<i class="bi bi-check-lg"></i> Save recipe');
            });
    });

    $('#addIngredient').on('click', function () {
        ingredientRow().appendTo('#ingredientRows').find('.ing-qty').trigger('focus');
    });
    $('#addStep').on('click', function () {
        const $row = stepRow().appendTo('#stepRows');
        renumber();
        $row.find('textarea').trigger('focus');
    });
    $form.on('click', '.btn-remove', function () {
        $(this).closest('.row-item').remove();
        renumber();
    });

    // Drag a step or ingredient by its handle. Pointer events cover mouse, touch and pen.
    const DRAG_THRESHOLD = 4;

    function dragGhostFor($row) {
        const rect = $row[0].getBoundingClientRect();
        const $ghost = $row.clone()
            .removeClass('dragging')
            .addClass('row-drag-ghost')
            .css({left: rect.left + 'px', top: rect.top + 'px', width: rect.width + 'px'})
            .appendTo(document.body);
        $ghost.find('input').each(function (i) {
            $(this).val($row.find('input').eq(i).val());
        });
        $ghost.find('textarea').val($row.find('textarea').val());

        return $ghost;
    }

    function endDrag() {
        if (!drag) return;
        drag.$row.removeClass('dragging');
        if (drag.ghost) drag.ghost.remove();
        drag = null;
    }

    $form.on('pointerdown', '.row-drag', function (e) {
        if (e.button !== undefined && e.button !== 0) return;
        if (drag) endDrag();

        const $row = $(this).closest('.row-item');
        const rect = $row[0].getBoundingClientRect();
        drag = {
            pointerId: e.pointerId,
            $row: $row,
            startX: e.clientX,
            startY: e.clientY,
            lastY: e.clientY,
            midOffset: rect.height / 2 - (e.clientY - rect.top),
            ghost: null,
            ghostTop: rect.top,
            ghostStartY: e.clientY,
            active: false,
        };

        e.preventDefault();
    });

    $(document).on('pointermove', function (e) {
        if (!drag || e.pointerId !== drag.pointerId) return;

        if (!drag.active) {
            if (Math.abs(e.clientY - drag.startY) < DRAG_THRESHOLD
                && Math.abs(e.clientX - drag.startX) < DRAG_THRESHOLD) {
                return;
            }

            const rect = drag.$row[0].getBoundingClientRect();
            drag.active = true;
            drag.ghostTop = rect.top;
            drag.ghostStartY = e.clientY;
            drag.lastY = e.clientY;
            drag.midOffset = rect.height / 2 - (e.clientY - rect.top);
            drag.$row.addClass('dragging');
            try {
                drag.ghost = dragGhostFor(drag.$row);
            } catch (err) {
                drag.ghost = null;
            }
        }

        e.preventDefault();
        const y = e.clientY;
        if (drag.ghost) drag.ghost.css('top', drag.ghostTop + (y - drag.ghostStartY));

        // Follow the midpoint of the drag image, not the cursor, so the swap happens when the
        // dragged item reaches the edge of its neighbour.
        const mid = y + drag.midOffset;

        if (y < drag.lastY) {
            let $prev = drag.$row.prev('.row-item');
            while ($prev.length && mid < $prev[0].getBoundingClientRect().bottom) {
                $prev.before(drag.$row);
                renumber();
                $prev = drag.$row.prev('.row-item');
            }
        } else if (y > drag.lastY) {
            let $next = drag.$row.next('.row-item');
            while ($next.length && mid > $next[0].getBoundingClientRect().top) {
                $next.after(drag.$row);
                renumber();
                $next = drag.$row.next('.row-item');
            }
        }

        drag.lastY = y;
    });

    $(document).on('pointerup pointercancel', function (e) {
        if (drag && e.pointerId === drag.pointerId) endDrag();
    });
    $('#ingredientRows').on('keydown', '.ing-name', function (e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        const $row = $(this).closest('.row-item');
        if (!$row.next().length) ingredientRow().insertAfter($row);
        $row.next().find('.ing-qty').trigger('focus');
    });

    $('#f-image').on('input change', updatePreview);
    $('#imagePreview').on('error', function () { $('#imagePreviewWrap').prop('hidden', true); });
    $('#f-file').on('change', function () {
        const file = this.files[0];
        if (!file) return;
        const data = new FormData();
        data.append('image', file);
        $('#f-image-error').text('');
        $('#f-image').prop('disabled', true);

        $.ajax({url: R.base + '/api/uploads', method: 'POST', data: data, processData: false, contentType: false, dataType: 'json'})
            .done(function (res) {
                $('#f-image').val(res.path).removeClass('is-invalid');
                updatePreview();
            })
            .fail(function (xhr) { $('#f-image-error').text(R.errorMessages(xhr).join(' ')); })
            .always(function () { $('#f-image').prop('disabled', false); $('#f-file').val(''); });
    });

    let saved = false;
    const initial = JSON.stringify(collect());
    window.addEventListener('beforeunload', function (e) {
        if (!saved && JSON.stringify(collect()) !== initial) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    renumber();
})(jQuery, window.Recipes);
