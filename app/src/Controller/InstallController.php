<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config\ConfigForm;
use App\Config\ConfigSchema;
use App\Config\ConfigStore;
use App\Http\Request;
use App\Http\Response;
use App\Install\Requirements;
use App\View\View;
use RuntimeException;

/**
 * First-run setup: show the server requirements and, once they are all met, save the initial
 * configuration. The application treats a missing config/config.php as "not installed yet" and
 * sends every request here until the configuration has been written.
 */
final class InstallController extends AbstractController
{
    /** The settings offered during setup. Everything else keeps its shipped default. */
    private const SECTIONS = [
        [
            'title' => 'Storage',
            'icon' => 'bi-hdd',
            'keys' => ['storage'],
        ],
        [
            'title' => 'Address',
            'icon' => 'bi-link-45deg',
            'keys' => ['base_url'],
        ],
        [
            'title' => 'Access',
            'icon' => 'bi-shield-lock',
            'keys' => ['auth_enabled', 'auth_guest_account', 'auth_min_password_length'],
        ],
        [
            'title' => 'Imports',
            'icon' => 'bi-cloud-download',
            'keys' => ['import_allow_private_hosts'],
        ],
        [
            'title' => 'Import from a URL (AI, optional)',
            'icon' => 'bi-stars',
            'keys' => ['llm_endpoint', 'llm_model', 'llm_api_key'],
        ],
    ];

    public function __construct(
        private readonly string $templateDir,
        private readonly ConfigStore $configStore,
        private readonly Requirements $requirements = new Requirements(),
    ) {
    }

    public function check(Request $request): Response
    {
        return $this->page($request, 'install/check', [
            'report' => $this->requirements->run(),
            'step' => 'check',
        ], 'Setup – Recipe Box');
    }

    public function config(Request $request): Response
    {
        $report = $this->requirements->run();
        if (!$report['ok']) {
            return $this->redirect($request->basePath . '/install', 302);
        }

        return $this->form(
            $request,
            $this->configStore->current(),
            $this->configStore->defaults(),
            [],
        );
    }

    public function save(Request $request): Response
    {
        $report = $this->requirements->run();
        if (!$report['ok']) {
            return $this->redirect($request->basePath . '/install', 302);
        }

        $defaults = $this->configStore->defaults();
        $current = $this->configStore->current();
        $posted = $request->post['config'] ?? [];
        $result = (new ConfigForm())->parse($defaults, $current, is_array($posted) ? $posted : []);

        if (($result['values']['storage'] ?? 'json') === 'sqlite' && !extension_loaded('pdo_sqlite')) {
            $result['errors']['storage'] = 'SQLite storage needs the pdo_sqlite PHP extension. Choose JSON storage.';
        }

        if ($result['errors'] === []) {
            try {
                // Saving the local file is what turns this installation into a configured application.
                $this->configStore->save($result['values']);

                return $this->redirect($request->basePath . '/', 303);
            } catch (RuntimeException $e) {
                $result['errors']['form'] = 'The configuration could not be saved: ' . $e->getMessage();
            }
        }

        return $this->form($request, $result['values'], $defaults, $result['errors'], 422);
    }

    /**
     * @param array<string, mixed>  $values
     * @param array<string, mixed>  $defaults
     * @param array<string, string> $errors
     */
    private function form(Request $request, array $values, array $defaults, array $errors, int $status = 200): Response
    {
        $sections = [];
        foreach (self::SECTIONS as $section) {
            $fields = [];
            foreach ($section['keys'] as $key) {
                if (!array_key_exists($key, $defaults)) {
                    continue;
                }

                $default = $defaults[$key];
                $meta = ConfigSchema::describe($key, $default);
                if ($key === 'storage' && !extension_loaded('pdo_sqlite')) {
                    $meta['choices'] = array_values(array_filter(
                        $meta['choices'],
                        static fn (array $choice): bool => $choice['value'] !== 'sqlite',
                    ));
                    if (($values[$key] ?? null) === 'sqlite') {
                        $values[$key] = 'json';
                    }
                }

                $fields[] = [
                    'key' => $key,
                    'meta' => $meta,
                    'value' => $values[$key] ?? $default,
                    'default' => $default,
                    'error' => $errors[$key] ?? null,
                ];
            }

            if ($fields !== []) {
                $sections[] = $section + ['fields' => $fields];
            }
        }

        return $this->page($request, 'install/config', [
            'sections' => $sections,
            'errors' => $errors,
            'step' => 'config',
        ], 'Initial configuration – Recipe Box', $status);
    }

    /** @param array<string, mixed> $vars */
    private function page(Request $request, string $template, array $vars, string $title, int $status = 200): Response
    {
        $view = View::forRequest($this->templateDir, $request);
        $html = $view->render('install/layout', [
            'title' => $title,
            'content' => $view->render($template, $vars),
            'step' => $vars['step'] ?? 'check',
        ]);

        return $this->html($html, $status);
    }
}
