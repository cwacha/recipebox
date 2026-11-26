(function ($, R) {
    'use strict';

    const $form = $('#editForm');
    const id = $form.attr('data-id');
    let draggedRow = null;
    let draggedMidOffset = 0;
    let lastDragY = 0;
    let $dragGhost = null;
    let dragGhostTop = 0;
    let dragGhostStartY = 0;

    // A preloaded transparent image: it hides the browser's own drag image, which cannot be
    // constrained to vertical movement.
    const dragImage = $('<img alt="" width="1" height="1">')
        .attr('src', 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7')
        .css({position: 'fixed', top: 0, left: 0, width: '1px', height: '1px', pointerEvents: 'none'})
        .appendTo(document.body)[0];

    function ingredientRow() {
        return $('<div class="row-item">' +
            '<input class="form-control qty-input ing-qty" placeholder="Amount" maxlength="40">' +
            '<input class="form-control ing-name" placeholder="Ingredient" maxlength="150">' +
            '<button type="button" class="btn btn-outline-secondary btn-sm row-drag" draggable="true" title="Drag to reorder" aria-label="Drag to reorder"><i class="bi bi-list"></i></button>' +
            '<button type="button" class="btn btn-outline-secondary btn-sm btn-remove" title="Remove" aria-label="Remove"><i class="bi bi-x-lg"></i></button></div>');
    }

    function stepRow() {
        return $('<div class="row-item">' +
            '<span class="num"></span>' +
            '<textarea class="form-control step-text-input" rows="2" placeholder="Describe this step…"></textarea>' +
            '<button type="button" class="btn btn-outline-secondary btn-sm row-drag" draggable="true" title="Drag to reorder" aria-label="Drag to reorder"><i class="bi bi-list"></i></button>' +
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

    // Drag a step or ingredient by its handle. Reordering happens while dragging.
    $form
        .on('dragstart', '.row-drag', function (e) {
            const $row = $(this).closest('.row-item');
            const rect = $row[0].getBoundingClientRect();
            const grabY = e.originalEvent.clientY - rect.top;
            draggedMidOffset = rect.height / 2 - grabY;
            lastDragY = e.originalEvent.clientY;
            draggedRow = $row.addClass('dragging');

            // Native drag images always follow the cursor, so hide it and show a fixed clone that
            // only moves vertically.
            try {
                $dragGhost = $row.clone()
                    .removeClass('dragging')
                    .addClass('row-drag-ghost')
                    .css({left: rect.left + 'px', top: rect.top + 'px', width: rect.width + 'px'})
                    .appendTo(document.body);
                $dragGhost.find('input').each(function (i) {
                    $(this).val($row.find('input').eq(i).val());
                });
                $dragGhost.find('textarea').val($row.find('textarea').val());
                dragGhostTop = rect.top;
                dragGhostStartY = e.originalEvent.clientY;
            } catch (err) {
                $dragGhost = null;
            }

            const data = e.originalEvent.dataTransfer;
            if (data) {
                data.effectAllowed = 'move';
                try {
                    data.setData('text/plain', ''); // Firefox needs data before it starts a drag
                    if (data.setDragImage) data.setDragImage(dragImage, 0, 0);
                } catch (err) {
                    // Some browsers refuse custom drag images; reordering still works without one.
                }
            }
        })
        .on('dragend', '.row-drag', function () {
            if (draggedRow) draggedRow.removeClass('dragging');
            if ($dragGhost) $dragGhost.remove();
            draggedRow = null;
            $dragGhost = null;
        });

    // Listen on the document: the custom ghost must never intercept dragover events.
    $(document)
        .on('dragover', function (e) {
            if (!draggedRow) return;
            e.preventDefault();
            const data = e.originalEvent.dataTransfer;
            if (data) data.dropEffect = 'move';

            // Follow the midpoint of the drag image, not the cursor, so the swap happens when the
            // dragged item reaches the edge of its neighbour.
            const y = e.originalEvent.clientY;
            const mid = y + draggedMidOffset;

            if (y < lastDragY) {
                // Moving up: the item above moves down once the dragged midpoint passes its bottom.
                let $prev = draggedRow.prev('.row-item');
                while ($prev.length && mid < $prev[0].getBoundingClientRect().bottom) {
                    $prev.before(draggedRow);
                    renumber();
                    $prev = draggedRow.prev('.row-item');
                }
            } else if (y > lastDragY) {
                // Moving down: the item below moves up once the dragged midpoint passes its top.
                let $next = draggedRow.next('.row-item');
                while ($next.length && mid > $next[0].getBoundingClientRect().top) {
                    $next.after(draggedRow);
                    renumber();
                    $next = draggedRow.next('.row-item');
                }
            }

            lastDragY = y;
        })
        .on('drop', function (e) {
            if (draggedRow) e.preventDefault();
        })
        .on('drag', function (e) {
            if ($dragGhost) $dragGhost.css('top', dragGhostTop + (e.originalEvent.clientY - dragGhostStartY));
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
