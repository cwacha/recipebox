(function ($, R) {
    'use strict';

    const $form = $('#editForm');
    const id = $form.attr('data-id');

    function ingredientRow() {
        return $('<div class="row-item">' +
            '<input class="form-control qty-input ing-qty" placeholder="Amount" maxlength="40">' +
            '<input class="form-control ing-name" placeholder="Ingredient" maxlength="150">' +
            '<button type="button" class="btn btn-outline-secondary btn-sm mt-1 btn-remove" title="Remove" aria-label="Remove"><i class="bi bi-x-lg"></i></button></div>');
    }

    function stepRow() {
        return $('<div class="row-item">' +
            '<span class="num"></span>' +
            '<textarea class="form-control step-text-input" rows="2" placeholder="Describe this step…"></textarea>' +
            '<div class="btn-group-vertical btn-group-sm">' +
            '<button type="button" class="btn btn-outline-secondary btn-up" title="Move up" aria-label="Move up"><i class="bi bi-chevron-up"></i></button>' +
            '<button type="button" class="btn btn-outline-secondary btn-down" title="Move down" aria-label="Move down"><i class="bi bi-chevron-down"></i></button></div>' +
            '<button type="button" class="btn btn-outline-secondary btn-sm mt-1 btn-remove" title="Remove" aria-label="Remove"><i class="bi bi-x-lg"></i></button></div>');
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
        const $btn = $('#btnSave').prop('disabled', true);

        R.api(id ? 'PUT' : 'POST', id ? '/api/recipes/' + encodeURIComponent(id) : '/api/recipes', collect())
            .done(function (r) {
                saved = true;
                R.flash(id ? 'Recipe updated.' : 'Recipe created.');
                location.href = R.base + '/recipes/' + encodeURIComponent(r.id);
            })
            .fail(function (xhr) {
                showFormErrors(xhr);
                $btn.prop('disabled', false);
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
    $('#stepRows').on('click', '.btn-up', function () {
        const $row = $(this).closest('.row-item');
        $row.prev().before($row);
        renumber();
    });
    $('#stepRows').on('click', '.btn-down', function () {
        const $row = $(this).closest('.row-item');
        $row.next().after($row);
        renumber();
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
