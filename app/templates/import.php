<?php
/**
 * @var \Closure $e
 * @var \Closure $url
 * @var array<string, mixed> $example
 * @var bool $llmImportEnabled
 */
?>
<main class="container py-4" style="max-width: 820px">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= $e($url('/')) ?>">All recipes</a></li>
            <li class="breadcrumb-item active" aria-current="page">Import</li>
        </ol>
    </nav>

    <h1 class="h2 recipe-title mb-4">Import recipes</h1>

<?php if ($llmImportEnabled): ?>
    <section class="card shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h4 mb-1"><i class="bi bi-stars"></i> From a web page</h2>
            <p class="small text-body-secondary mb-3">
                Paste the address of a recipe page. An AI service reads it and prepares a preview; nothing is saved
                until you confirm it.
            </p>

            <div class="alert alert-danger d-none" id="urlImportError"></div>
            <div class="alert alert-warning d-none" id="urlImportWarning"></div>

            <form class="d-flex flex-column flex-sm-row gap-2" id="urlImportForm" novalidate>
                <label class="visually-hidden" for="importUrl">Recipe URL</label>
                <input type="url" class="form-control" id="importUrl" placeholder="https://example.com/recipe" autocomplete="off" required>
                <button class="btn btn-primary text-nowrap" type="submit" id="btnFetchRecipe"><i class="bi bi-stars"></i> Fetch recipe</button>
            </form>

            <div class="import-progress d-none" id="urlImportProgress" aria-live="polite">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="import-status" id="urlImportStatus"><span class="spinner-border spinner-border-sm"></span> Working…</span>
                    <span class="import-timer" id="urlImportTimer" title="Elapsed time">0.0 s</span>
                </div>
                <ol class="import-steps" id="urlImportSteps">
                    <li data-step="check"><i class="bi bi-circle" aria-hidden="true"></i><span class="step-label">Checking URL</span><span class="step-time"></span></li>
                    <li data-step="download"><i class="bi bi-circle" aria-hidden="true"></i><span class="step-label">Downloading website</span><span class="step-time"></span></li>
                    <li data-step="ask"><i class="bi bi-circle" aria-hidden="true"></i><span class="step-label">Bribing the hamster</span><span class="step-time"></span></li>
                    <li data-step="parse"><i class="bi bi-circle" aria-hidden="true"></i><span class="step-label">Parsing result</span><span class="step-time"></span></li>
                </ol>
            </div>

            <div class="card mt-3 d-none" id="urlImportPreview" aria-live="polite">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <span><i class="bi bi-eye"></i> Preview <span class="badge text-bg-secondary">not saved yet</span></span>
                    <a class="small text-body-secondary text-truncate" id="previewSource" href="#" target="_blank" rel="noopener noreferrer" style="max-width: 55%"></a>
                </div>
                <div class="card-body">
                    <h3 class="h4 recipe-title mb-1" id="previewTitle"></h3>
                    <p class="text-body-secondary d-none" id="previewDescription"></p>
                    <div class="my-3">
                        <div class="d-flex flex-wrap align-items-center gap-2" id="previewMeta"></div>
                        <div class="preview-tags d-flex flex-wrap gap-2 mt-2 d-none" id="previewTags"></div>
                    </div>

                    <div class="row g-4">
                        <div class="col-md-5">
                            <h4 class="section-label h6">Ingredients</h4>
                            <ul class="mb-0" id="previewIngredients"></ul>
                        </div>
                        <div class="col-md-7">
                            <h4 class="section-label h6">Steps</h4>
                            <ol class="mb-0" id="previewSteps"></ol>
                        </div>
                    </div>

                    <div class="mt-3 d-none" id="previewImageWrap">
                        <img class="img-fluid rounded" id="previewImage" alt="Recipe picture preview" loading="lazy" referrerpolicy="no-referrer" style="max-height: 220px">
                    </div>
                </div>
                <div class="card-footer d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-link text-body-secondary" id="btnDiscardPreview">Discard</button>
                    <button type="button" class="btn btn-primary" id="btnImportPreview"><i class="bi bi-upload"></i> Import recipe</button>
                </div>
            </div>
        </div>
    </section>
<?php endif; ?>

    <section class="card shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h4 mb-1"><i class="bi bi-filetype-json"></i> From JSON</h2>
            <p class="small text-body-secondary mb-3">
                Upload a recipe file or paste its JSON below.
            </p>

            <div class="alert alert-danger d-none" id="importError"></div>

            <div class="dropzone" id="dropzone">
                <i class="bi bi-file-earmark-code"></i>
                <p class="mb-1">Drop a <code>.json</code> file here or</p>
                <label class="btn btn-outline-primary btn-sm mb-0" for="importFile">Choose file</label>
                <input type="file" id="importFile" accept=".json,application/json" hidden>
            </div>

            <label class="form-label mt-3" for="importText">Or paste JSON</label>
            <textarea class="form-control font-monospace small" id="importText" rows="8" spellcheck="false" placeholder='{"title": "…"}'></textarea>

            <details class="mt-3">
                <summary class="small text-body-secondary">Expected format</summary>
                <pre class="code-sample mt-2"><?= $e(json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
                <p class="small text-body-secondary mb-0">
                    You can upload one recipe object, a list of recipes or <code>{"recipes": [ … ]}</code>.
                    Ingredients may also be plain strings. <code>image</code> is optional (URL).
                </p>
            </details>
        </div>
        <div class="card-footer d-flex justify-content-end gap-2">
            <a class="btn btn-link text-body-secondary" href="<?= $e($url('/')) ?>">Cancel</a>
            <button type="button" class="btn btn-primary" id="btnDoImport"><i class="bi bi-upload"></i> Import</button>
        </div>
    </section>
</main>
