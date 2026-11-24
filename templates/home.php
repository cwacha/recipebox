<?php
/** @var string $basePath */
/** @var int $servings */
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Recipe Box – your kitchen companion</title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Ctext y='.9em' font-size='90'%3E🍳%3C/text%3E%3C/svg%3E">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="<?= $e($basePath) ?>/assets/css/app.css" rel="stylesheet">
    <script>
        (function () {
            var t = localStorage.getItem('theme');
            if (t) document.documentElement.setAttribute('data-bs-theme', t);
        })();
        window.APP = {base: <?= json_encode($basePath, JSON_THROW_ON_ERROR) ?>, servings: <?= $servings ?>};
    </script>
</head>
<body>

<nav class="navbar navbar-expand-md app-navbar sticky-top">
    <div class="container">
        <a class="navbar-brand brand" href="<?= $e($basePath) ?>/"><i class="bi bi-egg-fried"></i> Recipe Box</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav" aria-label="Menu">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="nav">
            <div class="ms-auto d-flex flex-wrap gap-2 py-2 py-md-0">
                <button class="btn btn-primary" id="btnNew"><i class="bi bi-plus-lg"></i> New recipe</button>
                <button class="btn btn-outline-secondary" id="btnImport"><i class="bi bi-upload"></i> Import JSON</button>
                <a class="btn btn-outline-secondary" id="btnExport" href="<?= $e($basePath) ?>/api/recipes/export"><i class="bi bi-download"></i> Export all</a>
                <button class="btn btn-outline-secondary" id="btnTheme" title="Toggle dark mode" aria-label="Toggle dark mode"><i class="bi bi-moon-stars"></i></button>
            </div>
        </div>
    </div>
</nav>

<header class="hero">
    <div class="container text-center">
        <h1 class="display-5">What are we cooking today?</h1>
        <p class="lead mb-4">All recipes are for <?= $servings ?> people. Search by dish, ingredient or tag.</p>
        <div class="search-box mx-auto">
            <i class="bi bi-search"></i>
            <input type="search" id="search" class="form-control form-control-lg" placeholder="Try “pasta”, “vegetarian” or “tomato” …" autocomplete="off" autofocus>
            <button type="button" class="btn-clear d-none" id="searchClear" aria-label="Clear search"><i class="bi bi-x-circle-fill"></i></button>
        </div>
        <div class="chips mt-3" id="categories"></div>
    </div>
</header>

<main class="container pb-5">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h5 mb-0" id="resultInfo">Recipes</h2>
        <div class="spinner-border spinner-border-sm text-secondary d-none" id="loading" role="status"></div>
    </div>

    <div class="row g-4" id="grid"></div>

    <div class="empty text-center py-5 d-none" id="empty">
        <div class="display-1">🥣</div>
        <h3 class="h4 mt-3">No recipes found</h3>
        <p class="text-body-secondary">Try a different search or add a new recipe.</p>
        <button class="btn btn-primary" id="btnNewEmpty"><i class="bi bi-plus-lg"></i> New recipe</button>
    </div>
</main>

<footer class="py-4 text-center text-body-secondary small">
    Recipe Box · PHP <?= PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION ?> · Bootstrap · jQuery
</footer>

<!-- Recipe view -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-fullscreen-lg-down">
        <div class="modal-content" id="viewContent"></div>
    </div>
</div>

<!-- Recipe editor -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-fullscreen-lg-down">
        <form class="modal-content" id="editForm" novalidate>
            <div class="modal-header">
                <h2 class="modal-title h5" id="editTitle">New recipe</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger d-none" id="editError"></div>
                <input type="hidden" id="f-id">
                <div class="row g-3">
                    <div class="col-lg-8">
                        <label class="form-label" for="f-title">Title *</label>
                        <input class="form-control" id="f-title" name="title" maxlength="150" required>
                        <div class="invalid-feedback"></div>
                    </div>
                    <div class="col-6 col-lg-2">
                        <label class="form-label" for="f-time">Minutes *</label>
                        <input class="form-control" type="number" min="1" max="10080" id="f-time" name="cooking_time" required>
                        <div class="invalid-feedback"></div>
                    </div>
                    <div class="col-6 col-lg-2">
                        <label class="form-label" for="f-category">Category</label>
                        <input class="form-control" id="f-category" name="category" list="categoryList" maxlength="50">
                        <datalist id="categoryList"></datalist>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="f-description">Short description</label>
                        <textarea class="form-control" id="f-description" name="description" rows="2"></textarea>
                    </div>
                    <div class="col-lg-8">
                        <label class="form-label" for="f-image">Picture</label>
                        <div class="input-group">
                            <input class="form-control" id="f-image" name="image" placeholder="https://… or upload a file">
                            <label class="btn btn-outline-secondary mb-0" for="f-file"><i class="bi bi-image"></i> Upload</label>
                            <input type="file" id="f-file" accept="image/jpeg,image/png,image/webp,image/gif" hidden>
                        </div>
                        <div class="invalid-feedback d-block" id="f-image-error"></div>
                    </div>
                    <div class="col-lg-4">
                        <label class="form-label" for="f-tags">Tags</label>
                        <input class="form-control" id="f-tags" name="tags" placeholder="vegetarian, quick, italian">
                    </div>
                    <div class="col-12" id="imagePreviewWrap" hidden>
                        <img id="imagePreview" class="img-preview" alt="Preview">
                    </div>

                    <div class="col-lg-5">
                        <h3 class="h6 section-label">Ingredients for <?= $servings ?> people *</h3>
                        <div id="ingredientRows" class="vstack gap-2"></div>
                        <div class="invalid-feedback d-block" id="f-ingredients-error"></div>
                        <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="addIngredient"><i class="bi bi-plus"></i> Add ingredient</button>
                    </div>
                    <div class="col-lg-7">
                        <h3 class="h6 section-label">Preparation steps *</h3>
                        <div id="stepRows" class="vstack gap-2"></div>
                        <div class="invalid-feedback d-block" id="f-steps-error"></div>
                        <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="addStep"><i class="bi bi-plus"></i> Add step</button>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-link text-body-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btnSave"><i class="bi bi-check-lg"></i> Save recipe</button>
            </div>
        </form>
    </div>
</div>

<!-- Import -->
<div class="modal fade" id="importModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5">Import recipes from JSON</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger d-none" id="importError"></div>
                <div class="dropzone" id="dropzone">
                    <i class="bi bi-file-earmark-code"></i>
                    <p class="mb-1">Drop a <code>.json</code> file here or</p>
                    <label class="btn btn-outline-primary btn-sm mb-0" for="importFile">Choose file</label>
                    <input type="file" id="importFile" accept=".json,application/json" hidden>
                </div>
                <label class="form-label mt-3" for="importText">…or paste JSON</label>
                <textarea class="form-control font-monospace small" id="importText" rows="9" spellcheck="false" placeholder='{"title": "…"}'></textarea>
                <details class="mt-3">
                    <summary class="small text-body-secondary">Expected format</summary>
                    <pre class="code-sample mt-2" id="jsonExample"></pre>
                    <p class="small text-body-secondary mb-0">
                        You can upload one recipe object, a list of recipes or <code>{"recipes": [ … ]}</code>.
                        Ingredients may also be plain strings. <code>image</code> is optional (URL).
                    </p>
                </details>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-link text-body-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="btnDoImport"><i class="bi bi-upload"></i> Import</button>
            </div>
        </div>
    </div>
</div>

<!-- Delete confirmation -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-body text-center p-4">
                <div class="display-4 text-danger"><i class="bi bi-trash3"></i></div>
                <h2 class="h5 mt-2">Delete this recipe?</h2>
                <p class="text-body-secondary mb-4"><strong id="deleteName"></strong> will be removed permanently.</p>
                <button type="button" class="btn btn-outline-secondary me-2" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="btnConfirmDelete">Delete</button>
            </div>
        </div>
    </div>
</div>

<div class="toast-container position-fixed bottom-0 end-0 p-3" id="toasts"></div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= $e($basePath) ?>/assets/js/app.js"></script>
</body>
</html>
