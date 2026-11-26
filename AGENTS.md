# AGENTS.md

Recipe Box: a PHP 8.2+ / Bootstrap 5 / jQuery recipe manager. No build step, no framework, no JS bundler.

## Layout

- `app/` — the actual application. **Run all Composer, PHPUnit and `bin/` commands from here, not the repo root.**
- `pkg/` — release packaging/deploy scripts (`pkgmake.sh`, `sftp_upload.sh`). Artifacts are gitignored.
- `app/README.md` — the real documentation; read it for behavior, config and security details.

## Commands (from `app/`)

```bash
composer install
composer start                 # php -S localhost:8000 -t public
composer test                  # full PHPUnit suite
vendor/bin/phpunit --filter testSearchIsBookmarkable   # single test
vendor/bin/phpunit tests/PageTest.php                  # single file
php bin/account list|set-password <id>|create <id> <roles>
php bin/migrate json sqlite    # or sqlite json
bin/dev-permissions [_www]     # make writable dirs usable by web server + you
```

There is no linter, typechecker, CI or codegen. PHPUnit is the only verification (`failOnWarning`/`failOnRisky` are on). `pdo_sqlite` is optional; SQLite tests skip without it.

## Gotchas

- **Auth tests touch real state.** `tests/Auth/AuthTestCase` writes to `app/var/state/*.json` and restores it in `tearDown`. Never run the suite in parallel or against a live installation; a hard crash can leave accounts changed.
- **`app/config/config.php` is local and gitignored**, and may contain a live LLM API key. Never commit, print or copy it. Configuration is layered: `defaults/config.php` → `config/config.php` → constructor overrides. Edit only the local file; never edit `defaults/config.php` (shipped, replaced on upgrade).
- Settings are flat with prefixes (`auth_enabled`, `llm_endpoint`). A new default setting automatically appears in `/admin/config`; only add to `Config\ConfigSchema` for a special control/description. The admin UI saves differences only.
- **Data is defaults + local diffs.** `defaults/` is shipped and must not be edited for local data. Recipes, account/role changes and tokens live in `app/var/state/` (gitignored). Deleting those files resets to seeded data and `admin`/`admin`.
- **No CDNs.** Bootstrap, icons, jQuery and fonts are vendored under `public/assets/vendor/`; `PageTest` asserts no external asset hosts. Keep it that way.
- Web root is `public/`; the project-root `.htaccess` denies everything. Don't move files out of `public/` or add tracked runtime data.
- Web UI mutations intentionally use `POST /api/recipes/{id}` and `POST /api/recipes/{id}/delete`; `PUT`/`DELETE` exist for API clients. Don't replace POST with REST verbs.
- Cookie-based changes need CSRF (`_csrf` or `X-CSRF-Token`); HTTP Basic requests don't.
- `APP_DEBUG=1` is the only application env var. `bin/account set-password` also accepts `ACCOUNT_PASSWORD` for non-interactive use.

## Architecture notes

- Entrypoint: `public/index.php` → `App\Application`. Routes and their required permission are declared in `Application::buildRouter()`: page permissions start with `/`, actions are bare words, `null` is public. `ALL` grants everything; the `all` role is auto-maintained.
- Permission labels shown in admin come from `Auth\PermissionCatalog` (informational only).
- Layers: `Controller/` → `Repository/RecipeRepositoryInterface` (JSON and SQLite backends share `AbstractRecipeRepository`; both run the same contract test), `Service/` for imports/images/LLM, `Auth/` for accounts/roles/permissions, `Config/` for layered settings.
- Image and LLM imports share `RemoteFetcher` safety rules (http(s), ports 80/443, no credentials, private/loopback refused unless `import_allow_private_hosts`). URL import uses a page-download token + NDJSON extract endpoint so progress/cancel works; keep that two-request split.
- Recipes are seeded only when the configured storage file does not exist; deleting it re-seeds. Seed images are copied, never overwritten.
- When adding a storage backend: extend `AbstractRecipeRepository`, register in `RepositoryFactory`, add a `RecipeRepositoryContract` subclass.

## Packaging

`pkg/pkgmake.sh all` (from `pkg/`) copies `app/`, strips `tests/`, `phpunit.xml`, config, state, uploads and images, then writes `.tar.gz`/`.zip`. `pkg/pkgmake.sh deploy` uploads via `sftp_upload.sh` using `pkg/.deploy-urls`. Do not add release files to git.
