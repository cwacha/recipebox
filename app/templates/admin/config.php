<?php
/**
 * The configuration form is generated from defaults/config.php. ConfigSchema decides which control
 * each setting uses; settings without an entry there are rendered from the type of their default.
 *
 * @var \Closure $e
 * @var \Closure $url
 * @var \Closure $csrf
 * @var \Closure $can
 * @var array<string, mixed>  $values   defaults with the local configuration applied
 * @var array<string, mixed>  $defaults the shipped defaults
 * @var array<string, string> $errors
 * @var list<array{prefix: string, label: string, icon: string, keys: list<string>}> $groups
 */
$formatDefault = static function (mixed $value): string {
    return match (true) {
        $value === null => 'automatic',
        is_bool($value) => $value ? 'true' : 'false',
        is_array($value) => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        default => (string) $value === '' ? 'empty' : (string) $value,
    };
};
$short = static fn (string $text, int $limit = 160): string => strlen($text) > $limit ? substr($text, 0, $limit - 1) . '…' : $text;

$changedCount = 0;
foreach ($defaults as $key => $default) {
    if (($values[$key] ?? null) !== $default) {
        $changedCount++;
    }
}
$canEdit = $can('config_edit');
?>
<main class="container py-4" style="max-width: 980px">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= $e($url('/admin')) ?>">Administration</a></li>
            <li class="breadcrumb-item active" aria-current="page">Configuration</li>
        </ol>
    </nav>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="recipe-title h2 mb-0"><i class="bi bi-sliders"></i> Configuration</h1>
        <?php if ($changedCount > 0): ?>
            <span class="badge text-bg-warning align-self-center"><?= $changedCount ?> changed</span>
        <?php endif; ?>
    </div>
    <?= $this->render('admin/_nav', ['section' => 'config']) ?>

    <?php if (isset($errors['form'])): ?>
        <div class="alert alert-danger" role="alert"><?= $e($errors['form']) ?></div>
    <?php endif; ?>

    <div class="alert alert-info d-flex gap-3 align-items-start" role="alert">
        <i class="bi bi-info-circle-fill fs-5"></i>
        <div>
            Highlighted settings differ from the shipped defaults. Saving writes <strong>only those differences</strong> to
            <code>config/config.php</code>; every other value keeps coming from <code>defaults/config.php</code>.
            Resetting a field to its default removes it from the local file again.
        </div>
    </div>

    <?php if (!$canEdit): ?>
        <div class="alert alert-secondary" role="alert">
            <i class="bi bi-eye"></i> You can view the configuration, but you do not have permission to save changes.
        </div>
    <?php endif; ?>

    <form method="post" action="<?= $e($url('/admin/config')) ?>" class="vstack gap-4" autocomplete="off" novalidate>
        <input type="hidden" name="_csrf" value="<?= $e($csrf()) ?>">

        <fieldset class="vstack gap-4 border-0 p-0 m-0"<?= $canEdit ? '' : ' disabled' ?>>
        <?php foreach ($groups as $group): ?>
            <section class="card shadow-sm">
                <div class="card-header bg-transparent fw-semibold">
                    <i class="bi <?= $e($group['icon']) ?>"></i> <?= $e($group['label']) ?>
                </div>
                <div class="card-body vstack gap-3">
                    <?php foreach ($group['keys'] as $key): ?>
                        <?php
                        $key = (string) $key;
                        $default = $defaults[$key] ?? null;
                        $value = $values[$key] ?? $default;
                        $meta = \App\Config\ConfigSchema::describe($key, $default);
                        $changed = $value !== $default;
                        $id = 'config-' . preg_replace('/[^a-z0-9_-]/i', '-', $key);
                        $invalid = isset($errors[$key]) ? ' is-invalid' : '';
                        $defaultText = match (true) {
                            $meta['type'] === 'password' => '',
                            $meta['type'] === 'textarea' => 'the shipped default text',
                            default => $short($formatDefault($default)),
                        };
                        ?>
                        <div class="config-field<?= $changed ? ' is-changed' : '' ?>" data-config-field>
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                                <label class="form-label mb-0" for="<?= $e($id) ?>">
                                    <?= $e($meta['label']) ?>
                                    <code class="config-key ms-2"><?= $e($key) ?></code>
                                </label>
                                <?php if ($changed): ?>
                                    <span class="badge text-bg-warning config-changed-badge">changed</span>
                                <?php endif; ?>
                            </div>

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
                            <?php elseif ($meta['type'] === 'textarea'): ?>
                                <textarea class="form-control font-monospace<?= $invalid ?>" id="<?= $e($id) ?>" name="config[<?= $e($key) ?>]" rows="<?= $key === 'llm_prompt' ? 8 : 4 ?>"><?= $e((string) $value) ?></textarea>
                            <?php elseif ($meta['type'] === 'int'): ?>
                                <input class="form-control<?= $invalid ?>" type="number" step="1" id="<?= $e($id) ?>" name="config[<?= $e($key) ?>]" value="<?= $e((string) $value) ?>"<?= $meta['min'] !== null ? ' min="' . $meta['min'] . '"' : '' ?><?= $meta['max'] !== null ? ' max="' . $meta['max'] . '"' : '' ?>>
                            <?php elseif ($meta['type'] === 'password'): ?>
                                <input class="form-control font-monospace<?= $invalid ?>" type="password" autocomplete="new-password" id="<?= $e($id) ?>" name="config[<?= $e($key) ?>]" value="" placeholder="<?= $e($value === '' ? 'No value stored' : 'A value is stored; leave empty to keep it') ?>">
                                <input type="hidden" name="config[<?= $e($key) ?>__clear]" value="0">
                                <?php if ($value !== ''): ?>
                                    <div class="form-check mt-2">
                                        <input class="form-check-input" type="checkbox" id="<?= $e($id) ?>-clear" name="config[<?= $e($key) ?>__clear]" value="1">
                                        <label class="form-check-label" for="<?= $e($id) ?>-clear">Remove the stored value</label>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php if ($meta['nullable']): ?>
                                    <input type="hidden" name="config[<?= $e($key) ?>__null]" value="0">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" id="<?= $e($id) ?>-automatic" name="config[<?= $e($key) ?>__null]" value="1" data-config-null<?= $value === null ? ' checked' : '' ?>>
                                        <label class="form-check-label" for="<?= $e($id) ?>-automatic">Use the automatic value</label>
                                    </div>
                                <?php endif; ?>
                                <input class="form-control<?= $invalid ?>" type="text" id="<?= $e($id) ?>" name="config[<?= $e($key) ?>]" value="<?= $e($value === null ? '' : (string) $value) ?>"<?= $meta['nullable'] ? ' data-config-text' . ($value === null ? ' disabled' : '') : '' ?>>
                            <?php endif; ?>

                            <?php if (isset($errors[$key])): ?>
                                <div class="invalid-feedback d-block"><?= $e($errors[$key]) ?></div>
                            <?php endif; ?>
                            <?php if ($changed && $defaultText !== ''): ?>
                                <div class="form-text config-default">Default: <code><?= $e($defaultText) ?></code></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
        </fieldset>

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span class="text-body-secondary small">
                <?= $changedCount === 0 ? 'Everything uses the shipped defaults.' : $changedCount . ' setting' . ($changedCount === 1 ? '' : 's') . ' differ' . ($changedCount === 1 ? 's' : '') . ' from the defaults.' ?>
            </span>
            <div class="d-flex gap-2">
                <a class="btn btn-link text-body-secondary" href="<?= $e($url('/admin')) ?>">Cancel</a>
                <?php if ($canEdit): ?>
                    <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg"></i> Save configuration</button>
                <?php endif; ?>
            </div>
        </div>
    </form>
</main>
