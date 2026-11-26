<?php

/**
 * @var \Closure $e
 * @var \Closure $url
 * @var \Closure $img
 * @var int      $servings
 * @var \App\Model\Recipe|null $recipe
 * @var list<string> $categories
 */
$ingredients = $recipe && $recipe->ingredients !== [] ? $recipe->ingredients : [null, null, null];
$steps = $recipe && $recipe->steps !== [] ? $recipe->steps : ['', ''];
$preview = $img($recipe?->image);
$cancel = $recipe ? $url('/recipes/' . $recipe->slug) : $url('/');
?>
<main class="container py-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= $e($url('/')) ?>">All recipes</a></li>
            <?php if ($recipe): ?>
                <li class="breadcrumb-item"><a href="<?= $e($url('/recipes/' . $recipe->slug)) ?>"><?= $e($recipe->title) ?></a></li>
            <?php endif; ?>
            <li class="breadcrumb-item active" aria-current="page"><?= $recipe ? 'Edit' : 'New recipe' ?></li>
        </ol>
    </nav>

    <h1 class="h2 recipe-title mb-4"><?= $recipe ? 'Edit recipe' : 'New recipe' ?></h1>

    <form id="editForm" novalidate data-id="<?= $e($recipe?->id) ?>" data-cancel="<?= $e($cancel) ?>">
        <div class="alert alert-danger d-none" id="editError"></div>
        <div class="row g-3">
            <div class="col-lg-8">
                <label class="form-label" for="f-title">Title *</label>
                <input class="form-control" id="f-title" name="title" maxlength="150" required value="<?= $e($recipe?->title) ?>" autofocus>
                <div class="invalid-feedback"></div>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label" for="f-time">Minutes *</label>
                <input class="form-control" type="number" min="1" max="10080" id="f-time" name="cooking_time" required value="<?= $e($recipe ? (string) $recipe->cookingTime : '') ?>">
                <div class="invalid-feedback"></div>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label" for="f-category">Category</label>
                <input class="form-control" id="f-category" name="category" list="categoryList" maxlength="50" value="<?= $e($recipe?->category) ?>">
                <datalist id="categoryList">
                    <?php foreach ($categories as $c): ?><option value="<?= $e($c) ?>"><?php endforeach; ?>
                </datalist>
            </div>
            <div class="col-12">
                <label class="form-label" for="f-description">Short description</label>
                <textarea class="form-control" id="f-description" name="description" rows="5"><?= $e($recipe?->description) ?></textarea>
            </div>
            <div class="col-lg-8">
                <label class="form-label" for="f-image">Picture</label>
                <div class="input-group">
                    <input class="form-control" id="f-image" name="image" placeholder="https://… or upload a file" value="<?= $e($recipe?->image) ?>">
                    <label class="btn btn-outline-secondary mb-0" for="f-file"><i class="bi bi-image"></i> Upload</label>
                    <input type="file" id="f-file" accept="image/jpeg,image/png,image/webp,image/gif" hidden>
                </div>
                <div class="invalid-feedback d-block" id="f-image-error"></div>
                <div class="form-text">A web address is downloaded and saved with the recipe.</div>
            </div>
            <div class="col-lg-4">
                <label class="form-label" for="f-tags">Tags</label>
                <input class="form-control" id="f-tags" name="tags" placeholder="vegetarian, quick, italian" value="<?= $e($recipe ? implode(', ', $recipe->tags) : '') ?>">
            </div>
            <div class="col-12" id="imagePreviewWrap" <?= $preview ? '' : ' hidden' ?>>
                <img id="imagePreview" class="img-preview" alt="Preview" src="<?= $e($preview) ?>">
            </div>

            <div class="col-lg-5">
                <h2 class="h6 section-label">Ingredients for <?= $servings ?> people *</h2>
                <div id="ingredientRows" class="vstack gap-2">
                    <?php foreach ($ingredients as $ingredient): ?>
                        <div class="row-item">
                            <input class="form-control qty-input ing-qty" placeholder="Amount" maxlength="40" value="<?= $e($ingredient?->quantity) ?>">
                            <input class="form-control ing-name" placeholder="Ingredient" maxlength="150" value="<?= $e($ingredient?->name) ?>">
                            <button type="button" class="btn btn-outline-secondary btn-sm mt-1 btn-remove" title="Remove" aria-label="Remove"><i class="bi bi-x-lg"></i></button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="invalid-feedback d-block" id="f-ingredients-error"></div>
                <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="addIngredient"><i class="bi bi-plus"></i> Add ingredient</button>
            </div>
            <div class="col-lg-7">
                <h2 class="h6 section-label">Preparation steps *</h2>
                <div id="stepRows" class="vstack gap-2">
                    <?php foreach ($steps as $step): ?>
                        <div class="row-item">
                            <span class="num"></span>
                            <textarea class="form-control step-text-input" rows="5" placeholder="Describe this step…"><?= $e($step) ?></textarea>
                            <div class="btn-group-vertical btn-group-sm">
                                <button type="button" class="btn btn-outline-secondary btn-up" title="Move up" aria-label="Move up"><i class="bi bi-chevron-up"></i></button>
                                <button type="button" class="btn btn-outline-secondary btn-down" title="Move down" aria-label="Move down"><i class="bi bi-chevron-down"></i></button>
                            </div>
                            <button type="button" class="btn btn-outline-secondary btn-sm mt-1 btn-remove" title="Remove" aria-label="Remove"><i class="bi bi-x-lg"></i></button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="invalid-feedback d-block" id="f-steps-error"></div>
                <div class="text-end">
                    <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="addStep"><i class="bi bi-plus"></i> Add step</button>
                </div>
            </div>

            <div class="d-flex justify-content-end gap-2 mt-4 pt-3 border-top">
                <a class="btn btn-link text-body-secondary" href="<?= $e($cancel) ?>">Cancel</a>
                <button type="submit" class="btn btn-primary" id="btnSave"><i class="bi bi-check-lg"></i> Save recipe</button>
            </div>
    </form>
</main>