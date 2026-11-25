(function ($, R) {
    'use strict';

    const SPINNER = '<span class="spinner-border spinner-border-sm"></span> ';
    const CSRF = $('meta[name="csrf-token"]').attr('content') || '';
    let pendingRecipe = null;

    // progress of a URL import
    let running = false;
    let controller = null;
    let clock = null;
    let startedAt = 0;
    let stepStartedAt = 0;
    let $runningStep = null;

    function readFile(file) {
        if (!file) return;
        const reader = new FileReader();
        reader.onload = function () { $('#importText').val(reader.result); };
        reader.readAsText(file);
    }

    function formatMinutes(min) {
        if (min < 60) return min + ' min';
        return Math.floor(min / 60) + ' h' + (min % 60 !== 0 ? ' ' + (min % 60) + ' min' : '');
    }

    function formatSeconds(ms) {
        return (ms / 1000).toFixed(1) + ' s';
    }

    function metaPill(text, icon) {
        const $pill = $('<span class="meta-pill">');
        if (icon) {
            $pill.append($('<i>').addClass('bi ' + icon), document.createTextNode(' '));
        }
        return $pill.append(document.createTextNode(text));
    }

    function renderPreview(recipe, sourceUrl) {
        $('#previewTitle').text(recipe.title || 'Untitled recipe');

        const description = String(recipe.description || '').trim();
        $('#previewDescription').text(description).toggleClass('d-none', description === '');

        const $meta = $('#previewMeta').empty();
        const minutes = parseInt(recipe.cooking_time, 10);
        if (minutes > 0) {
            $meta.append(metaPill(formatMinutes(minutes), 'bi-clock'));
        }
        if (recipe.category) {
            $meta.append(metaPill(String(recipe.category), 'bi-bookmark'));
        }

        // tags always get their own line below the time and category
        const $tags = $('#previewTags').empty();
        (Array.isArray(recipe.tags) ? recipe.tags : []).forEach(function (tag) {
            $tags.append($('<span class="tag">').text(String(tag)));
        });
        $tags.toggleClass('d-none', $tags.children().length === 0);

        const $ingredients = $('#previewIngredients').empty();
        (Array.isArray(recipe.ingredients) ? recipe.ingredients : []).forEach(function (ingredient) {
            const item = typeof ingredient === 'string' ? {name: ingredient} : (ingredient || {});
            const $li = $('<li>');
            const quantity = String(item.quantity || '').trim();
            const name = String(item.name || '').trim();
            if (quantity) {
                $li.append($('<strong>').text(quantity), document.createTextNode(' '));
            }
            $li.append(document.createTextNode(name));
            $ingredients.append($li);
        });

        const $steps = $('#previewSteps').empty();
        (Array.isArray(recipe.steps) ? recipe.steps : []).forEach(function (step) {
            const text = typeof step === 'string' ? step : String((step && step.text) || '');
            $steps.append($('<li>').text(text));
        });

        const image = typeof recipe.image === 'string' ? recipe.image.trim() : '';
        const safeImage = /^https?:\/\//i.test(image) ? image : '';
        if (safeImage) {
            $('#previewImage').attr('src', safeImage);
        } else {
            $('#previewImage').removeAttr('src');
        }
        $('#previewImageWrap').toggleClass('d-none', safeImage === '');

        $('#previewSource').text(sourceUrl).attr('href', sourceUrl);
        $('#urlImportPreview').removeClass('d-none');
        const preview = $('#urlImportPreview')[0];
        if (preview && preview.scrollIntoView) {
            preview.scrollIntoView({behavior: 'smooth', block: 'start'});
        }
    }

    function afterImport(res) {
        let message = res.imported + (res.imported === 1 ? ' recipe' : ' recipes') + ' imported.';
        const warnings = res.warnings || [];
        if (warnings.length) {
            const more = warnings.length > 3 ? ' … and ' + (warnings.length - 3) + ' more.' : '';
            R.flash(message + ' ' + warnings.slice(0, 3).join(' · ') + more, 'warning');
        } else {
            R.flash(message);
        }
        location.href = res.imported === 1 ? R.base + '/recipes/' + encodeURIComponent(res.recipes[0].slug) : R.base + '/';
    }

    /* ---- progress of the URL import ------------------------------------------------ */

    function setStatus(state) {
        const $status = $('#urlImportStatus');
        if (state === 'done') {
            $status.html('<i class="bi bi-check-circle-fill"></i> Finished');
        } else if (state === 'failed') {
            $status.html('<i class="bi bi-x-circle-fill"></i> Failed');
        } else if (state === 'cancelled') {
            $status.html('<i class="bi bi-dash-circle"></i> Cancelled');
        } else {
            $status.html('<span class="spinner-border spinner-border-sm"></span> Working…');
        }
    }

    /** The button starts an import and turns into the cancel button while one is running. */
    function setFetchButton(active) {
        const $btn = $('#btnFetchRecipe');
        if (active) {
            $btn.removeClass('btn-primary').addClass('btn-outline-secondary').html('<i class="bi bi-x-lg"></i> Cancel');
        } else {
            $btn.removeClass('btn-outline-secondary').addClass('btn-primary').html('<i class="bi bi-stars"></i> Fetch recipe');
        }
    }

    function beginProgress() {
        running = true;
        startedAt = Date.now();
        stepStartedAt = startedAt;
        $runningStep = null;
        $('#urlImportSteps li')
            .removeClass('running done failed')
            .find('i').attr('class', 'bi bi-circle')
            .end().find('.step-time').text('');
        setStatus('working');
        $('#urlImportTimer').text('0.0 s');
        $('#urlImportProgress').removeClass('d-none');
        if (clock !== null) clearInterval(clock);
        clock = setInterval(function () {
            $('#urlImportTimer').text(formatSeconds(Date.now() - startedAt));
        }, 100);
    }

    function finishRunningStep(state) {
        if (!$runningStep) return;
        const icon = state === 'failed' ? 'bi-x-circle-fill' : (state === 'cancelled' ? 'bi-dash-circle' : 'bi-check-circle-fill');
        $runningStep
            .removeClass('running')
            .addClass(state)
            .find('i').attr('class', 'bi ' + icon);
        $runningStep.find('.step-time').text(formatSeconds(Date.now() - stepStartedAt));
        $runningStep = null;
    }

    function showStep(key) {
        finishRunningStep('done');
        $runningStep = $('#urlImportSteps li[data-step="' + key + '"]');
        if ($runningStep.length === 0) {
            $runningStep = null;
            return;
        }
        $runningStep.addClass('running').find('i').attr('class', 'bi bi-arrow-repeat');
        stepStartedAt = Date.now();
    }

    function endProgress(state) {
        finishRunningStep(state === 'done' ? 'done' : state);
        if (clock !== null) {
            clearInterval(clock);
            clock = null;
        }
        $('#urlImportTimer').text(formatSeconds(Date.now() - startedAt));
        setStatus(state);
        running = false;
    }

    function failImport(messages) {
        endProgress('failed');
        R.showAlert($('#urlImportError'), messages);
    }

    /** Stops the running request; the server notices the closed connection and aborts the model request too. */
    function cancelImport() {
        if (!running) return;
        if (controller !== null) {
            controller.abort();
        }
        endProgress('cancelled');
        R.toast('Import cancelled.');
    }

    function handleEvent(event, url) {
        if (event.event === 'step') {
            showStep(event.step);
            return false;
        }
        if (event.event === 'done' && event.recipe) {
            pendingRecipe = event.recipe;
            endProgress('done');
            renderPreview(event.recipe, event.url || url);
            return true;
        }
        if (event.event === 'error') {
            const errors = event.errors && typeof event.errors === 'object' ? Object.keys(event.errors).map(function (key) {
                return String(event.errors[key]);
            }) : null;
            failImport(errors && errors.length ? errors : [String(event.message || 'The import failed.')]);
            return true;
        }
        return false;
    }

    /** Reads the newline delimited JSON events of the server as they arrive. */
    function readEvents(response, url) {
        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';
        let finished = false;

        function consume(line) {
            line = line.trim();
            if (line === '') return;
            let event;
            try {
                event = JSON.parse(line);
            } catch (e) {
                return; // a broken line cannot be used, the following ones still can
            }
            if (handleEvent(event, url)) {
                finished = true;
            }
        }

        function pump() {
            return reader.read().then(function (chunk) {
                if (chunk.done) {
                    consume(buffer);
                    if (!finished) {
                        throw {messages: ['The connection closed before the import finished. Please try again.']};
                    }
                    return;
                }
                buffer += decoder.decode(chunk.value, {stream: true});
                const lines = buffer.split('\n');
                buffer = lines.pop();
                lines.forEach(consume);
                return pump();
            });
        }

        return pump();
    }

    /* ---- page setup ---------------------------------------------------------------- */

    $('#importFile').on('change', function () { readFile(this.files[0]); });

    $('#importUrl').on('input', function () {
        pendingRecipe = null;
        $('#urlImportPreview').addClass('d-none');
    });

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

        const $btn = $(this).prop('disabled', true).html(SPINNER + 'Importing…');
        R.api('POST', '/api/recipes/import', payload)
            .done(afterImport)
            .fail(function (xhr) {
                R.showAlert($err, R.errorMessages(xhr));
                $btn.prop('disabled', false).html('<i class="bi bi-upload"></i> Import');
            });
    });

    $('#urlImportForm').on('submit', function (e) {
        e.preventDefault();
        if (running) {
            cancelImport();
            return;
        }

        const $err = $('#urlImportError').addClass('d-none');
        pendingRecipe = null;
        $('#urlImportPreview').addClass('d-none');

        const url = $.trim(String($('#importUrl').val() || ''));
        if (!/^https?:\/\/\S+$/i.test(url)) {
            R.showAlert($err, ['Enter an http(s) URL, for example https://example.com/recipe.']);
            return;
        }

        beginProgress();
        setFetchButton(true);
        const request = new AbortController();
        controller = request;

        fetch(R.base + '/api/recipes/import/url?stream=1', {
            method: 'POST',
            credentials: 'same-origin',
            signal: request.signal,
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/x-ndjson',
                'X-CSRF-Token': CSRF,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({url: url})
        }).then(function (response) {
            if (!response.ok) {
                return response.json().catch(function () { return {}; }).then(function (json) {
                    throw {messages: R.errorMessages({status: response.status, responseJSON: json}), step: 'check'};
                });
            }
            return readEvents(response, url);
        }).catch(function (problem) {
            if (problem && problem.name === 'AbortError') {
                return; // the user cancelled, cancelImport() has already reported that
            }
            if (problem && problem.step) {
                showStep(problem.step); // e.g. the server refused the address
            }
            const messages = problem && Array.isArray(problem.messages) && problem.messages.length
                ? problem.messages
                : ['No answer from the server. It may still be busy – please try again.'];
            failImport(messages);
        }).finally(function () {
            if (controller === request) {
                controller = null;
                setFetchButton(false);
            }
        });
    });

    $('#btnDiscardPreview').on('click', function () {
        pendingRecipe = null;
        $('#urlImportPreview').addClass('d-none');
        $('#urlImportError').addClass('d-none');
        $('#importUrl').trigger('focus');
    });

    $('#btnImportPreview').on('click', function () {
        if (!pendingRecipe) return;

        const $btn = $(this).prop('disabled', true).html(SPINNER + 'Importing…');
        R.api('POST', '/api/recipes/import', pendingRecipe)
            .done(afterImport)
            .fail(function (xhr) {
                R.showAlert($('#urlImportError'), R.errorMessages(xhr));
                $btn.prop('disabled', false).html('<i class="bi bi-upload"></i> Import recipe');
            });
    });
})(jQuery, window.Recipes);
