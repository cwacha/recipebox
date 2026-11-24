(function ($) {
    'use strict';

    const BASE = window.APP.base;
    const SERVINGS = window.APP.servings;
    const EXAMPLE = {
        title: 'Tomato Bruschetta',
        description: 'Crisp toasted bread with marinated tomatoes.',
        category: 'Starter',
        image: 'https://example.com/bruschetta.jpg',
        cooking_time: 15,
        ingredients: [
            {quantity: '8 slices', name: 'ciabatta'},
            {quantity: '500 g', name: 'ripe tomatoes, diced'},
            {quantity: '2 cloves', name: 'garlic'}
        ],
        steps: ['Toast the bread.', 'Mix tomatoes, oil, salt and basil.', 'Rub the toast with garlic and top with the tomatoes.'],
        tags: ['italian', 'vegetarian']
    };

    const state = {q: '', category: '', recipes: [], categories: [], request: 0, current: null, deleteId: null};

    const modals = {};
    ['view', 'edit', 'import', 'delete'].forEach(function (name) {
        modals[name] = new bootstrap.Modal(document.getElementById(name + 'Modal'));
    });

    /* ---------- helpers ---------- */

    const ESC = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'};
    const esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ESC[c]; }); };
    const reEsc = function (s) { return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); };

    function imgUrl(path) {
        if (!path) return null;
        return /^https?:\/\//i.test(path) ? path : BASE + '/' + path;
    }

    function fmtTime(min) {
        if (min < 60) return min + ' min';
        const h = Math.floor(min / 60), m = min % 60;
        return h + ' h' + (m ? ' ' + m + ' min' : '');
    }

    function searchTerms() {
        return state.q.toLowerCase().split(/\s+/).filter(Boolean);
    }

    function highlight(text) {
        const terms = searchTerms();
        if (!terms.length) return esc(text);
        const re = new RegExp('(' + terms.map(reEsc).join('|') + ')', 'gi');
        return String(text).split(re).map(function (part, i) {
            return i % 2 ? '<mark>' + esc(part) + '</mark>' : esc(part);
        }).join('');
    }

    function api(method, url, data) {
        const opts = {url: BASE + url, method: method, dataType: 'json'};
        if (data !== undefined) {
            opts.contentType = 'application/json';
            opts.data = JSON.stringify(data);
        }
        return $.ajax(opts);
    }

    function errorMessage(xhr) {
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
        new bootstrap.Toast($t[0], {delay: 3500}).show();
    }

    function showAlert($el, messages) {
        $el.html(messages.map(esc).join('<br>')).removeClass('d-none');
    }

    /* ---------- list ---------- */

    function load() {
        const seq = ++state.request;
        $('#loading').removeClass('d-none');

        return api('GET', '/api/recipes?' + $.param({q: state.q, category: state.category}))
            .done(function (res) {
                if (seq !== state.request) return;
                state.recipes = res.recipes;
                state.categories = res.categories;
                renderCategories();
                renderGrid();
            })
            .fail(function (xhr) {
                if (seq === state.request) toast(errorMessage(xhr).join(' '), 'danger');
            })
            .always(function () {
                if (seq === state.request) $('#loading').addClass('d-none');
            });
    }

    function renderCategories() {
        const chips = [{label: 'All', value: ''}].concat(state.categories.map(function (c) { return {label: c, value: c}; }));
        $('#categories').html(chips.map(function (c) {
            const active = c.value.toLowerCase() === state.category.toLowerCase();
            return '<button type="button" class="chip' + (active ? ' active' : '') + '" data-category="' + esc(c.value) + '">' + esc(c.label) + '</button>';
        }).join(''));
        $('#categoryList').html(state.categories.map(function (c) { return '<option value="' + esc(c) + '">'; }).join(''));
    }

    function renderGrid() {
        const n = state.recipes.length;
        let info = n + (n === 1 ? ' recipe' : ' recipes');
        if (state.q) info = n + (n === 1 ? ' result' : ' results') + ' for “' + state.q + '”';
        if (state.category) info += ' in ' + state.category;
        $('#resultInfo').text(info);
        $('#empty').toggleClass('d-none', n > 0);

        $('#grid').html(state.recipes.map(function (r, i) {
            const src = imgUrl(r.image);
            const thumb = src
                ? '<img src="' + esc(src) + '" alt="' + esc(r.title) + '" loading="lazy">'
                : '';
            const placeholder = '<div class="placeholder-icon"><i class="bi bi-egg-fried"></i></div>';
            return '<div class="col-12 col-md-6 col-xl-4 fade-in" style="animation-delay:' + Math.min(i, 8) * 40 + 'ms">' +
                '<article class="card recipe-card" tabindex="0" role="button" data-id="' + esc(r.id) + '">' +
                '<div class="thumb">' + placeholder + thumb +
                '<span class="time-badge"><i class="bi bi-clock"></i> ' + fmtTime(r.cooking_time) + '</span></div>' +
                '<div class="card-body">' +
                (r.category ? '<div class="card-category">' + highlight(r.category) + '</div>' : '') +
                '<h3 class="card-title-serif">' + highlight(r.title) + '</h3>' +
                '<p class="card-text">' + highlight(r.description) + '</p>' +
                '<div>' + r.tags.slice(0, 4).map(function (t) { return '<span class="tag">' + highlight(t) + '</span>'; }).join('') + '</div>' +
                '</div></article></div>';
        }).join(''));

        $('#grid img').on('error', function () { $(this).remove(); });
    }

    /* ---------- view ---------- */

    function openRecipe(id) {
        api('GET', '/api/recipes/' + encodeURIComponent(id)).done(function (r) {
            state.current = r;
            renderView(r);
            if (location.hash !== '#recipe/' + id) history.replaceState(null, '', '#recipe/' + id);
            modals.view.show();
        }).fail(function (xhr) {
            toast(errorMessage(xhr).join(' '), 'danger');
            history.replaceState(null, '', location.pathname);
        });
    }

    function renderView(r) {
        const src = imgUrl(r.image);
        const hero = '<div class="view-hero">' +
            (src ? '<img src="' + esc(src) + '" alt="' + esc(r.title) + '">' : '') +
            '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>' +
            '<div class="caption">' +
            (r.category ? '<div class="card-category text-white-50">' + esc(r.category) + '</div>' : '') +
            '<h2 class="recipe-title">' + esc(r.title) + '</h2></div></div>';

        const ingredients = r.ingredients.map(function (i) {
            return '<li><span class="qty">' + esc(i.quantity) + '</span><span>' + esc(i.name) + '</span></li>';
        }).join('');

        const steps = r.steps.map(function (s) {
            return '<li><span class="step-text">' + esc(s) + '</span></li>';
        }).join('');

        const body = '<div class="modal-body p-0">' + hero +
            '<div class="p-3 p-md-4">' +
            '<div class="mb-3">' +
            '<span class="meta-pill"><i class="bi bi-clock"></i> ' + fmtTime(r.cooking_time) + '</span>' +
            '<span class="meta-pill"><i class="bi bi-people"></i> ' + SERVINGS + ' servings</span>' +
            '<span class="meta-pill"><i class="bi bi-list-ol"></i> ' + r.steps.length + ' steps</span></div>' +
            (r.description ? '<p class="lead">' + esc(r.description) + '</p>' : '') +
            '<div class="row g-4 mt-1">' +
            '<div class="col-lg-4"><div class="ingredients-box">' +
            '<h3 class="section-label h6">Ingredients <small class="text-body-secondary fw-normal">for ' + SERVINGS + '</small></h3>' +
            '<ul class="ingredient-list">' + ingredients + '</ul>' +
            '<div class="small text-body-secondary mt-2 no-print">Tap an ingredient to tick it off.</div></div></div>' +
            '<div class="col-lg-8"><h3 class="section-label h6">Preparation</h3>' +
            '<ol class="step-list">' + steps + '</ol></div></div>' +
            '<div class="mt-3">' + r.tags.map(function (t) { return '<span class="tag">#' + esc(t) + '</span>'; }).join('') + '</div>' +
            '</div></div>';

        const footer = '<div class="modal-footer justify-content-between no-print">' +
            '<button type="button" class="btn btn-outline-danger" id="btnDelete"><i class="bi bi-trash3"></i> Delete</button>' +
            '<div class="d-flex gap-2 flex-wrap justify-content-end">' +
            '<button type="button" class="btn btn-outline-secondary" id="btnPrint"><i class="bi bi-printer"></i> Print</button>' +
            '<a class="btn btn-outline-secondary" href="' + BASE + '/api/recipes/' + encodeURIComponent(r.id) + '/export"><i class="bi bi-filetype-json"></i> JSON</a>' +
            '<button type="button" class="btn btn-primary" id="btnEdit"><i class="bi bi-pencil"></i> Edit</button></div></div>';

        $('#viewContent').html(body + footer);
    }

    /* ---------- editor ---------- */

    function ingredientRow(i) {
        i = i || {};
        return $('<div class="row-item">' +
            '<input class="form-control qty-input ing-qty" placeholder="Amount" maxlength="40" value="' + esc(i.quantity) + '">' +
            '<input class="form-control ing-name" placeholder="Ingredient" maxlength="150" value="' + esc(i.name) + '">' +
            '<button type="button" class="btn btn-outline-secondary btn-sm mt-1 btn-remove" title="Remove" aria-label="Remove"><i class="bi bi-x-lg"></i></button></div>');
    }

    function stepRow(text) {
        return $('<div class="row-item">' +
            '<span class="num"></span>' +
            '<textarea class="form-control step-text-input" rows="2" placeholder="Describe this step…">' + esc(text) + '</textarea>' +
            '<div class="btn-group-vertical btn-group-sm">' +
            '<button type="button" class="btn btn-outline-secondary btn-up" title="Move up" aria-label="Move up"><i class="bi bi-chevron-up"></i></button>' +
            '<button type="button" class="btn btn-outline-secondary btn-down" title="Move down" aria-label="Move down"><i class="bi bi-chevron-down"></i></button></div>' +
            '<button type="button" class="btn btn-outline-secondary btn-sm mt-1 btn-remove" title="Remove" aria-label="Remove"><i class="bi bi-x-lg"></i></button></div>');
    }

    function renumberSteps() {
        $('#stepRows .row-item').each(function (i) { $(this).find('.num').text(i + 1); });
    }

    function updatePreview() {
        const src = imgUrl($('#f-image').val().trim());
        $('#imagePreviewWrap').prop('hidden', !src);
        if (src) $('#imagePreview').attr('src', src);
    }

    function clearErrors() {
        $('#editError').addClass('d-none');
        $('#editForm .is-invalid').removeClass('is-invalid');
        $('#editForm .invalid-feedback').text('');
    }

    function openEditor(r) {
        clearErrors();
        $('#editTitle').text(r ? 'Edit recipe' : 'New recipe');
        $('#f-id').val(r ? r.id : '');
        $('#f-title').val(r ? r.title : '');
        $('#f-time').val(r ? r.cooking_time : '');
        $('#f-category').val(r ? r.category : '');
        $('#f-description').val(r ? r.description : '');
        $('#f-image').val(r && r.image ? r.image : '');
        $('#f-tags').val(r ? r.tags.join(', ') : '');
        $('#f-file').val('');

        const ingredients = r && r.ingredients.length ? r.ingredients : [{}, {}, {}];
        const steps = r && r.steps.length ? r.steps : ['', ''];
        $('#ingredientRows').empty().append(ingredients.map(ingredientRow));
        $('#stepRows').empty().append(steps.map(stepRow));
        renumberSteps();
        updatePreview();

        modals.edit.show();
        $('#editModal').one('shown.bs.modal', function () { $('#f-title').trigger('focus'); });
    }

    function collectForm() {
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
        const errors = (xhr.responseJSON && xhr.responseJSON.errors) || null;
        if (!errors) {
            showAlert($('#editError'), errorMessage(xhr));
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
        $('#editModal .modal-body').scrollTop(0);
        showAlert($('#editError'), ['Please fix the highlighted fields.']);
    }

    function saveRecipe(e) {
        e.preventDefault();
        clearErrors();
        const id = $('#f-id').val();
        const $btn = $('#btnSave').prop('disabled', true);

        api(id ? 'PUT' : 'POST', id ? '/api/recipes/' + encodeURIComponent(id) : '/api/recipes', collectForm())
            .done(function (r) {
                $('#editModal').one('hidden.bs.modal', function () { openRecipe(r.id); });
                modals.edit.hide();
                toast(id ? 'Recipe updated.' : 'Recipe created.');
                load();
            })
            .fail(showFormErrors)
            .always(function () { $btn.prop('disabled', false); });
    }

    function uploadImage(file) {
        const data = new FormData();
        data.append('image', file);
        $('#f-image-error').text('');
        $('#f-image').prop('disabled', true);

        $.ajax({url: BASE + '/api/uploads', method: 'POST', data: data, processData: false, contentType: false, dataType: 'json'})
            .done(function (res) {
                $('#f-image').val(res.path).removeClass('is-invalid');
                updatePreview();
            })
            .fail(function (xhr) { $('#f-image-error').text(errorMessage(xhr).join(' ')); })
            .always(function () { $('#f-image').prop('disabled', false); $('#f-file').val(''); });
    }

    /* ---------- import ---------- */

    function openImport() {
        $('#importError').addClass('d-none');
        $('#importText').val('');
        $('#jsonExample').text(JSON.stringify(EXAMPLE, null, 2));
        modals.import.show();
    }

    function readImportFile(file) {
        if (!file) return;
        const reader = new FileReader();
        reader.onload = function () { $('#importText').val(reader.result); };
        reader.readAsText(file);
    }

    function doImport() {
        const $err = $('#importError').addClass('d-none');
        let payload;
        try {
            payload = JSON.parse($('#importText').val());
        } catch (e) {
            showAlert($err, ['This is not valid JSON: ' + e.message]);
            return;
        }

        const $btn = $('#btnDoImport').prop('disabled', true);
        api('POST', '/api/recipes/import', payload)
            .done(function (res) {
                modals.import.hide();
                toast(res.imported + (res.imported === 1 ? ' recipe' : ' recipes') + ' imported.');
                state.q = '';
                state.category = '';
                $('#search').val('');
                $('#searchClear').addClass('d-none');
                load();
            })
            .fail(function (xhr) { showAlert($err, errorMessage(xhr)); })
            .always(function () { $btn.prop('disabled', false); });
    }

    /* ---------- delete ---------- */

    function confirmDelete() {
        state.deleteId = state.current.id;
        $('#deleteName').text(state.current.title);
        $('#viewModal').one('hidden.bs.modal', function () { modals.delete.show(); });
        modals.view.hide();
    }

    function doDelete() {
        const id = state.deleteId;
        api('DELETE', '/api/recipes/' + encodeURIComponent(id))
            .done(function () {
                modals.delete.hide();
                toast('Recipe deleted.');
                load();
            })
            .fail(function (xhr) { toast(errorMessage(xhr).join(' '), 'danger'); });
    }

    /* ---------- theme ---------- */

    function applyThemeIcon() {
        const dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
        $('#btnTheme i').attr('class', 'bi ' + (dark ? 'bi-sun' : 'bi-moon-stars'));
    }

    /* ---------- events ---------- */

    let searchTimer = null;
    $('#search').on('input', function () {
        const value = $(this).val();
        $('#searchClear').toggleClass('d-none', value === '');
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () {
            state.q = value.trim();
            load();
        }, 220);
    });

    $('#searchClear').on('click', function () {
        $('#search').val('').trigger('input').trigger('focus');
    });

    $('#categories').on('click', '.chip', function () {
        state.category = $(this).attr('data-category');
        load();
    });

    $('#grid').on('click', '.recipe-card', function () { openRecipe($(this).attr('data-id')); });
    $('#grid').on('keydown', '.recipe-card', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            openRecipe($(this).attr('data-id'));
        }
    });

    $('#btnNew, #btnNewEmpty').on('click', function () { openEditor(null); });
    $('#btnImport').on('click', openImport);
    $('#btnTheme').on('click', function () {
        const next = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-bs-theme', next);
        localStorage.setItem('theme', next);
        applyThemeIcon();
    });

    $('#viewContent').on('click', '.ingredient-list li, .step-list li', function () { $(this).toggleClass('done'); });
    $('#viewContent').on('click', '#btnEdit', function () {
        const r = state.current;
        $('#viewModal').one('hidden.bs.modal', function () { openEditor(r); });
        modals.view.hide();
    });
    $('#viewContent').on('click', '#btnDelete', confirmDelete);
    $('#viewContent').on('click', '#btnPrint', function () { window.print(); });
    $('#viewModal').on('hidden.bs.modal', function () {
        if (location.hash.indexOf('#recipe/') === 0) history.replaceState(null, '', location.pathname + location.search);
    });

    $('#editForm').on('submit', saveRecipe);
    $('#addIngredient').on('click', function () {
        const $row = ingredientRow().appendTo('#ingredientRows');
        $row.find('.ing-qty').trigger('focus');
    });
    $('#addStep').on('click', function () {
        const $row = stepRow('').appendTo('#stepRows');
        renumberSteps();
        $row.find('textarea').trigger('focus');
    });
    $('#editForm').on('click', '.btn-remove', function () {
        $(this).closest('.row-item').remove();
        renumberSteps();
    });
    $('#stepRows').on('click', '.btn-up', function () {
        const $row = $(this).closest('.row-item');
        $row.prev().before($row);
        renumberSteps();
    });
    $('#stepRows').on('click', '.btn-down', function () {
        const $row = $(this).closest('.row-item');
        $row.next().after($row);
        renumberSteps();
    });
    $('#ingredientRows').on('keydown', '.ing-name', function (e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        const $row = $(this).closest('.row-item');
        if (!$row.next().length) ingredientRow().insertAfter($row).find('.ing-qty').trigger('focus');
        else $row.next().find('.ing-qty').trigger('focus');
    });
    $('#f-image').on('input change', updatePreview);
    $('#imagePreview').on('error', function () { $('#imagePreviewWrap').prop('hidden', true); });
    $('#f-file').on('change', function () { if (this.files[0]) uploadImage(this.files[0]); });

    $('#importFile').on('change', function () { readImportFile(this.files[0]); });
    $('#dropzone')
        .on('dragover dragenter', function (e) { e.preventDefault(); $(this).addClass('dragover'); })
        .on('dragleave drop', function (e) { e.preventDefault(); $(this).removeClass('dragover'); })
        .on('drop', function (e) { readImportFile(e.originalEvent.dataTransfer.files[0]); });
    $('#btnDoImport').on('click', doImport);

    $('#btnConfirmDelete').on('click', doDelete);

    $(window).on('hashchange', function () {
        const m = location.hash.match(/^#recipe\/([\w-]+)$/);
        if (m && !(state.current && state.current.id === m[1] && $('#viewModal').hasClass('show'))) openRecipe(m[1]);
    });

    /* ---------- start ---------- */

    applyThemeIcon();
    load().done(function () { $(window).trigger('hashchange'); });
})(jQuery);
