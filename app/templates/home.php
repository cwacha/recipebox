<?php
/**
 * @var \Closure $e
 * @var \Closure $url
 * @var \Closure $highlight
 * @var \Closure $img
 * @var \Closure $minutes
 * @var int      $servings
 * @var string   $q
 * @var string   $category
 * @var list<string> $terms
 * @var list<\App\Model\Recipe> $recipes
 * @var list<string> $categories
 */
$count = count($recipes);
$info = $q !== ''
    ? $count . ($count === 1 ? ' result' : ' results') . ' for “' . $q . '”'
    : $count . ($count === 1 ? ' recipe' : ' recipes');
if ($category !== '') {
    $info .= ' in ' . $category;
}
$chipUrl = static fn (string $cat): string => $url('/') . ($q !== '' || $cat !== '' ? '?' . http_build_query(array_filter(['q' => $q, 'category' => $cat], static fn (string $v): bool => $v !== '')) : '');
?>
<header class="hero">
    <div class="container text-center">
        <h1 class="display-5">What are we cooking today?</h1>
        <p class="lead mb-4">All recipes are for <?= $servings ?> people. Search by dish, ingredient or tag.</p>
        <form class="search-box mx-auto" id="searchForm" action="<?= $e($url('/')) ?>" method="get" role="search">
            <i class="bi bi-search"></i>
            <input type="search" id="search" name="q" value="<?= $e($q) ?>" class="form-control form-control-lg"
                   placeholder="Try “pasta”, “vegetarian” or “tomato” …" autocomplete="off" autofocus>
            <?php if ($category !== ''): ?><input type="hidden" name="category" value="<?= $e($category) ?>"><?php endif; ?>
            <button type="button" class="btn-clear<?= $q === '' ? ' d-none' : '' ?>" id="searchClear" aria-label="Clear search"><i class="bi bi-x-circle-fill"></i></button>
        </form>
    </div>
</header>

<main class="container pb-5" id="results">
    <div class="chips mb-4">
        <a class="chip<?= $category === '' ? ' active' : '' ?>" href="<?= $e($chipUrl('')) ?>">All</a>
        <?php foreach ($categories as $c): ?>
            <a class="chip<?= mb_strtolower($c) === mb_strtolower($category) ? ' active' : '' ?>" href="<?= $e($chipUrl($c)) ?>"><?= $e($c) ?></a>
        <?php endforeach; ?>
    </div>

    <h2 class="h5 mb-3" id="resultInfo"><?= $e($info) ?></h2>

    <?php if ($count === 0): ?>
        <div class="empty text-center py-5">
            <div class="display-1">🥣</div>
            <h3 class="h4 mt-3">No recipes found</h3>
            <p class="text-body-secondary">Try a different search<?= $can('/recipes/new') ? ' or add a new recipe' : '' ?>.</p>
            <?php if ($can('/recipes/new')): ?>
                <a class="btn btn-primary" href="<?= $e($url('/recipes/new')) ?>"><i class="bi bi-plus-lg"></i> New recipe</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <?php foreach ($recipes as $i => $r): ?>
                <div class="col-12 col-md-6 col-xl-4 fade-in" style="animation-delay: <?= min($i, 8) * 40 ?>ms">
                    <article class="card recipe-card">
                        <a class="stretched-link-card" href="<?= $e($url('/recipes/' . $r->slug)) ?>" aria-label="<?= $e($r->title) ?>"></a>
                        <div class="thumb">
                            <div class="placeholder-icon"><i class="bi bi-egg-fried"></i></div>
                            <?php if ($src = $img($r->image)): ?>
                                <img src="<?= $e($src) ?>" alt="<?= $e($r->title) ?>" loading="lazy" onerror="this.remove()">
                            <?php endif; ?>
                            <span class="time-badge"><i class="bi bi-clock"></i> <?= $e($minutes($r->cookingTime)) ?></span>
                        </div>
                        <div class="card-body">
                            <?php if ($r->category !== ''): ?>
                                <div class="card-category"><?= $highlight($r->category, $terms) ?></div>
                            <?php endif; ?>
                            <h3 class="card-title-serif"><?= $highlight($r->title, $terms) ?></h3>
                            <p class="card-text"><?= $highlight($r->description, $terms) ?></p>
                            <div>
                                <?php foreach (array_slice($r->tags, 0, 4) as $tag): ?>
                                    <span class="tag"><?= $highlight($tag, $terms) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </article>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>
