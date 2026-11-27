<?php

declare(strict_types=1);

namespace App\Install;

/**
 * Checks whether this server can run Recipe Box. The installer shows the result before it offers the
 * initial configuration; nothing here writes to the installation (write tests create and remove a
 * temporary file only).
 *
 * Required checks must all pass. Optional checks are shown as hints and never block the setup.
 */
final class Requirements
{
    public const MIN_PHP_VERSION = '8.2.0';

    /**
     * @param string $baseDir     application root (contains vendor/, defaults/, templates/, public/)
     * @param string $defaultsDir shipped defaults and seed data
     * @param string $publicDir   web root
     * @param string $configDir   local configuration directory
     * @param string $stateDir    recipes, accounts, roles and tokens
     * @param string $sessionsDir PHP session files
     * @param string $imageDir    recipe images
     * @param string $uploadDir   uploaded pictures
     */
    public function __construct(
        private readonly string $baseDir = BASEDIR,
        private readonly string $defaultsDir = DEFAULTSDIR,
        private readonly string $publicDir = BASEDIR . '/public',
        private readonly string $configDir = CONFDIR,
        private readonly string $stateDir = STATEDIR,
        private readonly string $sessionsDir = SESSIONSDIR,
        private readonly string $imageDir = IMAGE_DIR,
        private readonly string $uploadDir = UPLOAD_DIR,
    ) {
    }

    /**
     * @return array{
     *     ok: bool,
     *     php: string,
     *     groups: list<array{
     *         title: string,
     *         icon: string,
     *         checks: list<array{label: string, detail: string, help: string, ok: bool, required: bool}>
     *     }>
     * }
     */
    public function run(): array
    {
        $groups = [
            [
                'title' => 'PHP runtime',
                'icon' => 'bi-filetype-php',
                'checks' => [
                    $this->phpVersion(),
                    $this->fileUploads(),
                ],
            ],
            [
                'title' => 'PHP extensions',
                'icon' => 'bi-puzzle',
                'checks' => [
                    $this->extension('json', 'JSON'),
                    $this->extension('curl', 'cURL'),
                    $this->extension('fileinfo', 'File information'),
                    $this->extension('zip', 'ZIP archives'),
                    $this->extension('mbstring', 'Multibyte strings'),
                    $this->extension('session', 'Sessions'),
                    $this->extension('pcre', 'Regular expressions (PCRE)'),
                    $this->extension(
                        'pdo_sqlite',
                        'SQLite (PDO)',
                        required: false,
                        help: 'Optional. Without pdo_sqlite the recipes are stored in a JSON file instead of SQLite.',
                    ),
                    $this->extension(
                        'intl',
                        'Internationalization (intl)',
                        required: false,
                        help: 'Optional. Improves the transliteration of umlauts and other characters in recipe URLs.',
                    ),
                    $this->extension(
                        'iconv',
                        'Character conversion (iconv)',
                        required: false,
                        help: 'Optional. Used as a fallback when intl is not available.',
                    ),
                ],
            ],
            [
                'title' => 'Directories',
                'icon' => 'bi-folder',
                'checks' => [
                    $this->directory('config/', $this->configDir, 'Make the config directory writable by the web server.'),
                    $this->directory('var/state/', $this->stateDir, 'Make the state directory writable by the web server.'),
                    $this->directory('var/sessions/', $this->sessionsDir, 'Make the sessions directory writable by the web server.'),
                    $this->directory('public/images/', $this->imageDir, 'Make the images directory writable by the web server.'),
                    $this->directory('public/uploads/', $this->uploadDir, 'Make the uploads directory writable by the web server.'),
                ],
            ],
            [
                'title' => 'Application files',
                'icon' => 'bi-box-seam',
                'checks' => [
                    $this->file('vendor/autoload.php', $this->baseDir . '/vendor/autoload.php', 'Run "composer install" in the application directory.'),
                    $this->file('defaults/config.php', $this->defaultsDir . '/config.php', 'The shipped default configuration is missing. Reinstall the application package.'),
                    $this->file('defaults/accounts.json', $this->defaultsDir . '/accounts.json', 'The shipped accounts are missing. Reinstall the application package.'),
                    $this->file('defaults/roles.json', $this->defaultsDir . '/roles.json', 'The shipped roles are missing. Reinstall the application package.'),
                    $this->file('defaults/access.json', $this->defaultsDir . '/access.json', 'The shipped access table is missing. Reinstall the application package.'),
                    $this->file(
                        'defaults/seed/recipes.json',
                        $this->defaultsDir . '/seed/recipes.json',
                        'Optional. Without the seed data the installation starts with an empty recipe list.',
                        required: false,
                    ),
                    $this->templates(),
                    $this->assets(),
                ],
            ],
        ];

        $ok = true;
        foreach ($groups as $group) {
            foreach ($group['checks'] as $check) {
                if ($check['required'] && !$check['ok']) {
                    $ok = false;
                }
            }
        }

        return ['ok' => $ok, 'php' => PHP_VERSION, 'groups' => $groups];
    }

    /**
     * @return array{label: string, detail: string, help: string, ok: bool, required: bool}
     */
    private function phpVersion(): array
    {
        $ok = version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '>=');

        return $this->check(
            'PHP version',
            $ok,
            PHP_VERSION,
            $ok ? '' : 'Recipe Box needs PHP ' . self::MIN_PHP_VERSION . ' or newer.',
        );
    }

    /**
     * @return array{label: string, detail: string, help: string, ok: bool, required: bool}
     */
    private function fileUploads(): array
    {
        $ok = (bool) ini_get('file_uploads');

        return $this->check(
            'File uploads',
            $ok,
            $ok ? 'enabled' : 'disabled',
            $ok ? '' : 'Optional. Set file_uploads = On in php.ini to allow uploading recipe pictures.',
            required: false,
        );
    }

    /**
     * @return array{label: string, detail: string, help: string, ok: bool, required: bool}
     */
    private function extension(string $name, string $label, bool $required = true, string $help = ''): array
    {
        $loaded = extension_loaded($name);

        return $this->check(
            $label,
            $loaded,
            $loaded ? 'loaded' : 'missing',
            $loaded ? '' : ($help !== '' ? $help : 'Install the PHP extension "' . $name . '".'),
            $required,
        );
    }

    /**
     * @return array{label: string, detail: string, help: string, ok: bool, required: bool}
     */
    private function directory(string $label, string $path, string $help): array
    {
        if (is_dir($path)) {
            $writable = $this->canWrite($path);

            return $this->check(
                $label,
                $writable,
                $writable ? 'writable' : 'not writable',
                $writable ? '' : $help . ' (' . $path . ')',
            );
        }

        $parent = dirname($path);
        $creatable = is_dir($parent) && $this->canWrite($parent);

        return $this->check(
            $label,
            $creatable,
            $creatable ? 'missing, will be created' : 'missing',
            $creatable ? '' : 'Create ' . $path . ' and make it writable by the web server.',
        );
    }

    /**
     * @return array{label: string, detail: string, help: string, ok: bool, required: bool}
     */
    private function file(string $label, string $path, string $help, bool $required = true): array
    {
        $ok = is_file($path) && is_readable($path);

        return $this->check(
            $label,
            $ok,
            $ok ? 'readable' : 'missing',
            $ok ? '' : $help,
            $required,
        );
    }

    /**
     * @return array{label: string, detail: string, help: string, ok: bool, required: bool}
     */
    private function templates(): array
    {
        $required = ['layout.php', 'home.php', 'recipe.php', 'form.php', 'import.php', 'login.php'];
        $missing = array_values(array_filter($required, fn (string $file): bool => !is_file($this->baseDir . '/templates/' . $file)));

        return $this->check(
            'templates/',
            $missing === [],
            $missing === [] ? 'complete' : 'missing ' . implode(', ', $missing),
            $missing === [] ? '' : 'The application templates are missing. Reinstall the application package.',
        );
    }

    /**
     * @return array{label: string, detail: string, help: string, ok: bool, required: bool}
     */
    private function assets(): array
    {
        $files = [
            'assets/vendor/bootstrap/css/bootstrap.min.css',
            'assets/vendor/bootstrap/js/bootstrap.bundle.min.js',
            'assets/vendor/bootstrap-icons/bootstrap-icons.min.css',
            'assets/vendor/jquery/jquery.min.js',
            'assets/css/app.css',
        ];
        $missing = array_values(array_filter($files, fn (string $file): bool => !is_file($this->publicDir . '/' . $file)));

        return $this->check(
            'Bundled front-end assets',
            $missing === [],
            $missing === [] ? 'available' : 'missing ' . count($missing) . ' file(s)',
            $missing === [] ? '' : 'The bundled Bootstrap, icons and jQuery files are missing. Reinstall the application package.',
        );
    }

    /**
     * @return array{label: string, detail: string, help: string, ok: bool, required: bool}
     */
    private function check(string $label, bool $ok, string $detail, string $help = '', bool $required = true): array
    {
        return [
            'label' => $label,
            'detail' => $detail,
            'help' => $help,
            'ok' => $ok,
            'required' => $required,
        ];
    }

    /** Checks writing for real, because is_writable() alone is unreliable with some ACL setups. */
    private function canWrite(string $dir): bool
    {
        if (!is_dir($dir) || !is_writable($dir)) {
            return false;
        }

        $file = $dir . '/.install-check-' . bin2hex(random_bytes(6));
        $handle = @fopen($file, 'xb');
        if ($handle === false) {
            return false;
        }
        fclose($handle);

        return @unlink($file);
    }
}
