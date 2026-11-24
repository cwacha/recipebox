<?php
/**
 * @var \Closure $e
 * @var \Closure $url
 * @var array<string, mixed> $example
 */
?>
<main class="container py-4" style="max-width: 820px">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= $e($url('/')) ?>">All recipes</a></li>
            <li class="breadcrumb-item active" aria-current="page">Import JSON</li>
        </ol>
    </nav>

    <h1 class="h2 recipe-title mb-4">Import recipes from JSON</h1>

    <div class="alert alert-danger d-none" id="importError"></div>
    <div class="dropzone" id="dropzone">
        <i class="bi bi-file-earmark-code"></i>
        <p class="mb-1">Drop a <code>.json</code> file here or</p>
        <label class="btn btn-outline-primary btn-sm mb-0" for="importFile">Choose file</label>
        <input type="file" id="importFile" accept=".json,application/json" hidden>
    </div>

    <label class="form-label mt-3" for="importText">…or paste JSON</label>
    <textarea class="form-control font-monospace small" id="importText" rows="12" spellcheck="false" placeholder='{"title": "…"}'></textarea>

    <details class="mt-3">
        <summary class="small text-body-secondary">Expected format</summary>
        <pre class="code-sample mt-2"><?= $e(json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
        <p class="small text-body-secondary mb-0">
            You can upload one recipe object, a list of recipes or <code>{"recipes": [ … ]}</code>.
            Ingredients may also be plain strings. <code>image</code> is optional (URL).
        </p>
    </details>

    <div class="d-flex justify-content-end gap-2 mt-4 pt-3 border-top">
        <a class="btn btn-link text-body-secondary" href="<?= $e($url('/')) ?>">Cancel</a>
        <button type="button" class="btn btn-primary" id="btnDoImport"><i class="bi bi-upload"></i> Import</button>
    </div>
</main>
