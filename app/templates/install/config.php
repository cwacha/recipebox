<?php
/**
 * Initial configuration. Only the settings that matter for a fresh installation are shown; all
 * other settings keep their shipped defaults and can be changed later in Administration -> Configuration.
 *
 * @var \Closure $e
 * @var \Closure $url
 * @var list<array{title: string, icon: string, keys: list<string>, fields: list<array{key: string, meta: array<string, mixed>, value: mixed, default: mixed, error: ?string}>}> $sections
 * @var array<string, string> $errors
 */
?>
<div class="row justify-content-center">
    <div class="col-lg-10 col-xl-9">
        <div class="mb-4">
            <h1 class="recipe-title h2"><i class="bi bi-sliders"></i> Initial configuration</h1>
            <p class="text-body-secondary mb-0">
                Confirm how this installation should start. Every setting can be changed later in
                <strong>Administration → Configuration</strong>.
            </p>
        </div>

        <?php if (isset($errors['form'])): ?>
            <div class="alert alert-danger" role="alert"><?= $e($errors['form']) ?></div>
        <?php endif; ?>

        <div class="alert alert-info d-flex gap-3 align-items-start" role="alert">
            <i class="bi bi-info-circle-fill fs-5"></i>
            <div>
                Recipe Box ships with the built-in administrator <code>admin</code> and the password
                <code>admin</code>. You will be asked to change it right after signing in.
                The sample recipes are added on the first start.
            </div>
        </div>

        <form method="post" action="<?= $e($url('/install/config')) ?>" class="vstack gap-4" autocomplete="off" novalidate>
            <?php foreach ($sections as $section): ?>
                <section class="card shadow-sm">
                    <div class="card-header bg-transparent fw-semibold">
                        <i class="bi <?= $e($section['icon']) ?>"></i> <?= $e($section['title']) ?>
                    </div>
                    <div class="card-body vstack gap-3">
                        <?php foreach ($section['fields'] as $field): ?>
                            <?php
                            $key = $field['key'];
                            $meta = $field['meta'];
                            $value = $field['value'];
                            $error = $field['error'];
                            $id = 'setup-' . preg_replace('/[^a-z0-9_-]/i', '-', $key);
                            $invalid = $error !== null ? ' is-invalid' : '';
                            ?>
                            <div data-config-field>
                                <label class="form-label mb-1" for="<?= $e($id) ?>"><?= $e($meta['label']) ?></label>
                                <?php if ($meta['description'] !== ''): ?>
                                    <div class="form-text mt-0 mb-2"><?= $e($meta['description']) ?></div>
                                <?php endif; ?>

                                <?php if ($meta['type'] === 'onoff'): ?>
                                    <input type="hidden" name="config[<?= $e($key) ?>]" value="0">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input<?= $invalid ?>" type="checkbox" role="switch" id="<?= $e($id) ?>" name="config[<?= $e($key) ?>]" value="1"<?= $value ? ' checked' : '' ?>>
                                        <label class="form-check-label" for="<?= $e($id) ?>"><?= $value ? 'On' : 'Off' ?></label>
                                    </div>
                                <?php elseif ($meta['type'] === 'multichoice'): ?>
                                    <select class="form-select<?= $invalid ?>" id="<?= $e($id) ?>" name="config[<?= $e($key) ?>]">
                                        <?php foreach ($meta['choices'] as $choice): ?>
                                            <option value="<?= $e($choice['key']) ?>"<?= $value === $choice['value'] ? ' selected' : '' ?>><?= $e($choice['label']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php elseif ($meta['type'] === 'int'): ?>
                                    <input class="form-control<?= $invalid ?>" type="number" step="1" id="<?= $e($id) ?>" name="config[<?= $e($key) ?>]" value="<?= $e((string) $value) ?>"<?= $meta['min'] !== null ? ' min="' . $meta['min'] . '"' : '' ?><?= $meta['max'] !== null ? ' max="' . $meta['max'] . '"' : '' ?>>
                                <?php elseif ($meta['type'] === 'password'): ?>
                                    <input class="form-control font-monospace<?= $invalid ?>" type="password" autocomplete="new-password" id="<?= $e($id) ?>" name="config[<?= $e($key) ?>]" value="" placeholder="<?= $e($value === '' ? 'No value stored' : 'A value is stored; leave empty to keep it') ?>">
                                <?php else: ?>
                                    <?php if ($meta['nullable']): ?>
                                        <input type="hidden" name="config[<?= $e($key) ?>__null]" value="0">
                                        <div class="form-check mb-2">
                                            <input class="form-check-input" type="checkbox" id="<?= $e($id) ?>-automatic" name="config[<?= $e($key) ?>__null]" value="1" data-config-null<?= $value === null ? ' checked' : '' ?>>
                                            <label class="form-check-label" for="<?= $e($id) ?>-automatic">Use the automatic value</label>
                                        </div>
                                    <?php endif; ?>
                                    <input class="form-control<?= $invalid ?>" type="text" id="<?= $e($id) ?>" name="config[<?= $e($key) ?>]" value="<?= $e($value === null ? '' : (string) $value) ?>"<?= $meta['placeholder'] !== '' ? ' placeholder="' . $e($meta['placeholder']) . '"' : '' ?><?= $meta['nullable'] ? ' data-config-text' . ($value === null ? ' disabled' : '') : '' ?>>
                                <?php endif; ?>

                                <?php if ($error !== null): ?>
                                    <div class="invalid-feedback d-block"><?= $e($error) ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <a class="btn btn-link text-body-secondary" href="<?= $e($url('/install')) ?>"><i class="bi bi-arrow-left"></i> Back to the checks</a>
                <button class="btn btn-primary btn-lg" type="submit"><i class="bi bi-check-lg"></i> Finish setup</button>
            </div>
        </form>
    </div>
</div>

<script>
    (function () {
        function sync(checkbox) {
            var field = checkbox.closest('[data-config-field]');
            var input = field ? field.querySelector('[data-config-text]') : null;
            if (!input) return;
            input.disabled = checkbox.checked;
            if (checkbox.checked) input.value = '';
        }
        document.querySelectorAll('[data-config-null]').forEach(sync);
        document.addEventListener('change', function (event) {
            if (event.target.matches('[data-config-null]')) sync(event.target);
        });
    })();
</script>
