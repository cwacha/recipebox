<?php
/**
 * @var \Closure $e
 * @var \Closure $url
 * @var \Closure $img
 * @var \Closure $minutes
 * @var \Closure $can
 * @var int      $servings
 * @var \App\Model\Recipe $recipe
 */
$src = $img($recipe->image);
?>
<div class="view-hero">
    <?php if ($src): ?>
        <img src="<?= $e($src) ?>" alt="<?= $e($recipe->title) ?>" onerror="this.remove()">
    <?php endif; ?>
    <div class="container caption">
        <?php if ($recipe->category !== ''): ?>
            <a class="card-category text-white-50 text-decoration-none" href="<?= $e($url('/?category=' . rawurlencode($recipe->category))) ?>"><?= $e($recipe->category) ?></a>
        <?php endif; ?>
        <h1 class="recipe-title"><?= $e($recipe->title) ?></h1>
    </div>
</div>

<main class="container py-4" id="recipe" data-id="<?= $e($recipe->id) ?>" data-title="<?= $e($recipe->title) ?>">
    <nav aria-label="breadcrumb" class="no-print">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= $e($url('/')) ?>">All recipes</a></li>
            <li class="breadcrumb-item active" aria-current="page"><?= $e($recipe->title) ?></li>
        </ol>
    </nav>

    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-start mb-3">
        <div>
            <span class="meta-pill"><i class="bi bi-clock"></i> <?= $e($minutes($recipe->cookingTime)) ?></span>
            <span class="meta-pill"><i class="bi bi-people"></i> <?= $servings ?> servings</span>
            <span class="meta-pill"><i class="bi bi-list-ol"></i> <?= count($recipe->steps) ?> steps</span>
        </div>
        <div class="d-flex gap-2 flex-wrap no-print">
            <button type="button" class="btn btn-outline-secondary" id="btnPrint"><i class="bi bi-printer"></i> Print</button>
            <?php if ($can('recipe_export')): ?>
                <a class="btn btn-outline-secondary" href="<?= $e($url('/api/recipes/' . $recipe->id . '/export')) ?>"><i class="bi bi-filetype-json"></i> JSON</a>
            <?php endif; ?>
            <?php if ($can('/recipes/edit')): ?>
                <a class="btn btn-primary" href="<?= $e($url('/recipes/' . $recipe->slug . '/edit')) ?>"><i class="bi bi-pencil"></i> Edit</a>
            <?php endif; ?>
            <?php if ($can('recipe_delete')): ?>
                <button type="button" class="btn btn-outline-danger" id="btnDelete"><i class="bi bi-trash3"></i> Delete</button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($recipe->description !== ''): ?>
        <p class="lead"><?= $e($recipe->description) ?></p>
    <?php endif; ?>

    <div class="row g-4 mt-1">
        <div class="col-lg-4">
            <section class="ingredients-box">
                <h2 class="section-label h6">Ingredients <small class="text-body-secondary fw-normal">for <?= $servings ?></small></h2>
                <ul class="ingredient-list">
                    <?php foreach ($recipe->ingredients as $ingredient): ?>
                        <li><span class="qty"><?= $e($ingredient->quantity) ?></span><span><?= $e($ingredient->name) ?></span></li>
                    <?php endforeach; ?>
                </ul>
                <div class="small text-body-secondary mt-2 no-print">Tap an ingredient to tick it off.</div>
            </section>
        </div>
        <div class="col-lg-8">
            <section>
                <h2 class="section-label h6">Preparation</h2>
                <ol class="step-list">
                    <?php foreach ($recipe->steps as $step): ?>
                        <li><span class="step-text"><?= $e($step) ?></span></li>
                    <?php endforeach; ?>
                </ol>
            </section>
        </div>
    </div>

    <?php if ($recipe->tags !== []): ?>
        <div class="mt-3">
            <?php foreach ($recipe->tags as $tag): ?>
                <a class="tag text-decoration-none" href="<?= $e($url('/?q=' . rawurlencode($tag))) ?>">#<?= $e($tag) ?></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

<?php if ($can('recipe_delete')): ?>
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-body text-center p-4">
                <div class="display-4 text-danger"><i class="bi bi-trash3"></i></div>
                <h2 class="h5 mt-2">Delete this recipe?</h2>
                <p class="text-body-secondary mb-4"><strong><?= $e($recipe->title) ?></strong> will be removed permanently.</p>
                <button type="button" class="btn btn-outline-secondary me-2" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="btnConfirmDelete">Delete</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
