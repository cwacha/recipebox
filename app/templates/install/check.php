<?php
/**
 * @var \Closure $e
 * @var \Closure $url
 * @var array{ok: bool, php: string, groups: list<array{title: string, icon: string, checks: list<array{label: string, detail: string, help: string, ok: bool, required: bool}>}>} $report
 */
?>
<div class="row justify-content-center">
    <div class="col-lg-10 col-xl-9">
        <div class="text-center mb-4">
            <div class="display-5">🍳</div>
            <h1 class="recipe-title h2 mt-2">Welcome to Recipe Box</h1>
            <p class="text-body-secondary mb-0">
                This installation is not configured yet. Let's check that this server can run the application.
            </p>
        </div>

        <?php if ($report['ok']): ?>
            <div class="alert alert-success d-flex gap-3 align-items-start" role="alert">
                <i class="bi bi-check-circle-fill fs-5"></i>
                <div>
                    <strong>Everything is ready.</strong>
                    All required checks passed. Continue to confirm the initial configuration.
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-danger d-flex gap-3 align-items-start" role="alert">
                <i class="bi bi-x-circle-fill fs-5"></i>
                <div>
                    <strong>Some requirements are not met.</strong>
                    Fix the items marked in red below, then reload this page.
                </div>
            </div>
        <?php endif; ?>

        <?php foreach ($report['groups'] as $group): ?>
            <section class="card shadow-sm mb-3">
                <div class="card-header bg-transparent fw-semibold">
                    <i class="bi <?= $e($group['icon']) ?>"></i> <?= $e($group['title']) ?>
                </div>
                <ul class="list-group list-group-flush">
                    <?php foreach ($group['checks'] as $check): ?>
                        <?php
                        $badge = $check['ok'] ? 'text-bg-success' : ($check['required'] ? 'text-bg-danger' : 'text-bg-warning');
                        $icon = $check['ok'] ? 'bi-check-circle-fill text-success' : ($check['required'] ? 'bi-x-circle-fill text-danger' : 'bi-exclamation-triangle-fill text-warning');
                        ?>
                        <li class="list-group-item d-flex gap-3 align-items-start">
                            <i class="bi <?= $icon ?> fs-5"></i>
                            <div class="flex-grow-1">
                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                                    <span class="fw-semibold"><?= $e($check['label']) ?></span>
                                    <span>
                                        <span class="badge <?= $badge ?>"><?= $e($check['detail']) ?></span>
                                        <?php if (!$check['required']): ?>
                                            <span class="badge text-bg-light border">optional</span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <?php if ($check['help'] !== ''): ?>
                                    <div class="form-text mt-1"><?= $e($check['help']) ?></div>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endforeach; ?>

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-4">
            <a class="btn btn-outline-secondary" href="<?= $e($url('/install')) ?>"><i class="bi bi-arrow-clockwise"></i> Check again</a>
            <?php if ($report['ok']): ?>
                <a class="btn btn-primary btn-lg" href="<?= $e($url('/install/config')) ?>">Continue <i class="bi bi-arrow-right"></i></a>
            <?php else: ?>
                <button class="btn btn-primary btn-lg" type="button" disabled>Continue <i class="bi bi-arrow-right"></i></button>
            <?php endif; ?>
        </div>
    </div>
</div>
