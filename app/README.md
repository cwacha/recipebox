# Recipe Box

A small, modern recipe manager built with **PHP 8** (Composer, PSR-4 classes, inheritance, readonly classes),
**Bootstrap 5** and **jQuery**. Server-rendered pages, each with its own bookmarkable URL; jQuery adds live search,
the editor and the JSON API calls. Visitors can browse recipes as guests; adding, editing, importing and the
administration need an account with the right permissions (see *Sign-in and access control*).

## Run it

```bash
composer install
composer start          # php -S localhost:8000 -t public
```

Open <http://localhost:8000> and sign in with **`admin` / `admin`**. You are taken to the password page right away:
**change this default password before the application is reachable by anybody else.** Run the tests with `composer test`.

Requirements: PHP 8.2+ with `json`, `fileinfo` and `curl` (plus `pdo_sqlite` for SQLite storage, `intl` is used for slugs when present), Composer. Bootstrap, Bootstrap Icons, jQuery and the web fonts
load from CDNs, so the browser needs internet access.

On Apache, point the document root at `public/` (`.htaccess` is included) and allow `.htaccess` overrides
(`AllowOverride FileInfo AuthConfig Options`). Under nginx, route unknown paths to `public/index.php`.
`var/state/`, `var/sessions/`, `public/images/` and `public/uploads/` must be writable by the web server, and nothing
outside `public/` may be served (see *Server locations*).

### Server locations: base_dir and base_url

Two settings describe where the application lives (both in `config/app.php`, overridable by environment variables):

| Setting | Environment | Meaning |
|---|---|---|
| `base_dir` | `RECIPES_BASE_DIR` | filesystem root: `var/`, `defaults/`, `templates/`, `public/`. Default: the project folder. Set it when the code and the writable data live apart. |
| `base_url` | `RECIPES_BASE_URL` | URL prefix under which `public/` is served: `''` for a document root, `/~user/recipebox/public` for the example below. `null` (default) detects it from every request (`SCRIPT_NAME`), which covers the built-in server as well as Apache/nginx at the root or in a subdirectory. Set it explicitly behind a reverse proxy that strips the prefix. |

With the default `null` nothing has to be configured for any of these:

```text
http://localhost:8000/                          composer start (PHP built-in server, public/ as root)
http://localhost/recipebox/                     Apache virtual host with DocumentRoot …/public
http://localhost/~user/recipebox/public/        Apache user directory (see below)
```

All links, form targets, redirects, cookie paths and API calls derive from that prefix; nothing is hard-coded to `/`.

### Apache user directory example

For `http://localhost/~user/recipebox/public/` (Homebrew Apache with `UserDir Sites`):

1. **Only `public/` may be reached.** The project has a `.htaccess` in its root that denies everything; `public/.htaccess`
   grants access again for that folder and sends unknown paths to `index.php`. So
   `…/recipebox/composer.json`, `…/var/state/recipes.json` or `…/.git/` answer `403`, while `…/public/` works.
   This needs `AllowOverride FileInfo AuthConfig Options` (the Homebrew `extra/httpd-userdir.conf` allows it).
2. **The web server must be able to write.** Apache usually runs as `_www` while the files belong to you, so sessions,
   accounts/roles, uploads and imported pictures cannot be saved. Run once:

   ```bash
   bin/dev-permissions            # macOS: ACLs for _www and you; Linux: shared group, web user as argument
   ```

   It makes `var/`, `var/state/`, `var/sessions/`, `public/uploads/` and `public/images/` writable for both accounts;
   new files inherit this, so `bin/account` and the CLI keep working next to Apache.
3. Open `http://localhost/~user/recipebox/public/`.

The URL prefix is detected automatically. If you serve it behind a proxy under yet another path, set
`RECIPES_BASE_URL=/that/prefix` (and `RECIPES_BASE_DIR=/path/to/project` when needed).

### Why the web interface uses POST

Many Apache installations (`Require method GET POST OPTIONS`) block `PUT` and `DELETE`. The pages therefore create,
change and delete recipes with `POST /api/recipes`, `POST /api/recipes/{id}` and `POST /api/recipes/{id}/delete`.
The REST-style `PUT` and `DELETE` routes remain for API clients that can send them.

## Pages

| URL | |
|---|---|
| `/` | recipe list with search; `/?q=tomato` and `/?category=Soup` are bookmarkable |
| `/recipes/{name}` | recipe: ingredients, cooking time, numbered steps, e.g. `/recipes/spaghetti-carbonara` |
| `/recipes/{name}/edit` | edit a recipe |
| `/recipes/new` | create a recipe |
| `/import` | import recipes from JSON |
| `/login`, `/logout` | sign in and out |
| `/account/password` | change the own password |
| `/admin` | administration overview |
| `/admin/accounts` | all accounts with their roles |
| `/admin/account?id=…` | one account with all details and its effective permissions |
| `/admin/account/edit[?id=…]` | edit an account and its roles, or create a new one (no `id`) |
| `/admin/account/password?id=…` | set the password of an account |
| `/admin/roles` | all roles with their permissions (views and actions) and members |
| `/admin/role/edit[?id=…]` | edit a role and its permissions, or create a new one (no `id`) |

Recipe URLs use a readable name (slug) generated from the title: umlauts become `ae`/`oe`/`ue`, duplicates get `-2`, `-3`, …
The slug stays the same when you rename a recipe, so bookmarks keep working. Old links that contain the internal id
(`/recipes/a1c0de000001`) redirect to the readable URL. Existing recipes get their slug automatically (JSON and SQLite).

## Sign-in and access control

The model follows the *authentication and authorization* specification: accounts, roles and permissions, handled by
three layers in `src/Auth/`:

| Class | Job |
|---|---|
| `SecurityController` | the facade the application talks to: authenticates, answers `hasPermission()`, combines an account with its roles and effective permissions |
| `AuthenticatorDefault` (`AuthenticatorInterface`) | accounts, credential checks (login form, HTTP Basic, persistent-login token, guest), password hashing |
| `AuthorizerDefault` (`AuthorizerInterface`) | roles, the access table (account → roles), effective permissions |

The namespace is `App\Auth` (the specification suggests `IAddressBook\Auth`; only the vendor part differs).

**Accounts** have an ID, a password hash, and an optional full name and email. The password `!` means "can never sign in"
(the shipped `guest` account). Passwords are only stored as hashes (`password_hash`, checked with `password_verify`, at most 72 bytes).

**Permissions** are plain strings that exist as soon as a role mentions them. *Views* start with a slash (`/admin/roles`) and
allow opening a page; *actions* are words (`recipe_delete`) and allow doing something. Every route declares what it needs
(see `Application::buildRouter()`). Anybody identified may always `show` (read) recipes, and sign out. `ALL` grants everything.
**Roles** are lists of permissions; an account may do what any of its roles allows. The shipped roles:

| Role | Allows |
|---|---|
| `all` | every permission, enumerated; maintained automatically (contains everything any role mentions), cannot be edited or deleted |
| `admin` | the whole administration (accounts, roles, passwords) |
| `editor` | create, edit, delete, import and export recipes, upload pictures, change the own password |
| `reader` | open the recipe list and recipes |

Shipped accounts: `admin` (role `all`, password `admin`) and `guest` (role `reader`, cannot sign in).

**Not allowed?** A signed-in account that opens something it has no permission for sees an error and lands on the recipe
list (the implicit `show`). Visitors who are not signed in are sent to the login and come back to where they wanted to go.
The API answers `401` (sign in) or `403`.

**Guests.** Visitors who are not signed in act as the account named in `auth.guest_account` (default `guest`, read only).
Set it to an empty string and everybody has to sign in. **Authentication off:** `RECIPES_AUTH=0` (or `auth.enabled`) lets everybody
do everything, including the administration; use this only on a trusted machine.

**Credentials.** Login form, HTTP Basic (for API clients; `curl -u editor:secret …/api/recipes`), and a *keep me signed in*
cookie. Several sources are accepted only if they name the same account; anything malformed or contradicting fails closed (`401`).

### Security measures

- **Sessions** are server-side (PHP sessions in `var/sessions/`); the session ID is renewed at sign-in, sign-out clears it, and a session ends
  when the account is deleted, disabled or its password changes.
- **Persistent login** uses a random token (only its SHA-256 is stored in `var/state/auth_tokens.json`), expires after 30 days, is replaced at every
  use, and is revoked at sign-out and password change. It never contains a password.
- **Cookies** are `HttpOnly`, `SameSite=Lax` and `Secure` when the request came over HTTPS (all configurable under `auth` in `config/app.php`).
  Behind a reverse proxy that terminates TLS, set `cookie_secure` to `true`.
- **CSRF**: every change made with cookies (forms and scripts) needs the session's token (`_csrf` field or `X-CSRF-Token` header). HTTP Basic requests
  carry their credentials each time and need no token.
- **Guessing**: 5 failed sign-ins per account and address lock that combination for 15 minutes (and 20 per address). Unknown accounts take as
  long to refuse as known ones, and the error message is the same.
- **Safe changes**: nobody can delete themselves, and no change is saved that would leave no account able to manage accounts and roles.
- Failures are written to the PHP error log without passwords or tokens.

### Where the data lives

Like the recipes, accounts, roles and the access table ship as **defaults** (`defaults/accounts.json`, `roles.json`, `access.json`) with
**local changes** on top, stored in `var/state/` as *differences only* (changed records, and `null` for built-in records you removed).
The defaults are never written, so an upgrade can ship new built-in roles without touching your changes. Write errors are reported, not ignored.
Role and access lists are comma separated in the files, as in the specification.

### Forgot the admin password?

```bash
php bin/account list
php bin/account set-password admin        # asks for the new password
php bin/account create anna editor,reader
```

(Deleting `var/state/accounts.json`, `access.json` and `roles.json` resets everything to the shipped defaults, including `admin` / `admin`.)

### Swapping providers

`SecurityController` only knows the two interfaces. Choose providers by name in `config/app.php` (`auth.authenticator`, `auth.authorizer`);
register your own with `'authenticators' => ['ldap' => fn (array $auth) => new LdapAuthenticator(...)]`. Names come from this registry only, never from a request.

## Features

- Search (title, category, tags, ingredients, description, steps) with ranking and highlighting; category filter chips
- Every recipe has its own page and URL that can be bookmarked or shared
- Every recipe shows ingredients for **4 people**, the cooking time and numbered steps; tap ingredients and steps to tick them off while cooking
- Create / edit recipes in a form, with picture upload or image URL (downloaded and saved with the recipe), reorderable steps
- **Import JSON** (file, drag & drop or paste) – a single recipe, a list, or `{"recipes": [...]}`; all-or-nothing validation. Pictures that are links to other servers are downloaded into `public/images/`, so imported recipes are self-contained (see below)
- Export one recipe or all recipes as JSON (same format the import accepts)
- Print view, dark mode, responsive layout

### Image import

When a recipe is imported, its `image` is handled like this:

| `image` value | Result |
|---|---|
| `images/carbonara.jpg` (a file of this installation) | kept as is |
| `https://…` (any other server) | downloaded into `public/images/<recipe-slug>.<ext>` (e.g. `images/spaghetti-carbonara.jpg`), the recipe then points to the local copy |
| missing file, failed download, not a JPEG/PNG/WebP/GIF | the recipe is imported without a picture and a warning is shown |

Every recipe gets its own file, even if two recipes use the same link (it is downloaded once). A file is never
overwritten: if `images/pasta.jpg` already exists, the new one becomes `images/pasta-2.jpg`.

When a recipe is deleted (or its picture is replaced), its picture is deleted too, but only if it is named after that
recipe's slug (`<slug>.<ext>` or `<slug>-2.<ext>`), not used by another recipe and not one of the default images in
`defaults/images/`. Files with any other name are never touched.

The same applies in the editor: a web address pasted into the *Picture* field is downloaded when you save, and the
recipe keeps the local copy. If the download fails (or the file is not an image) the form shows the error at the field
and nothing is saved, so the previous picture stays. A value that has not been changed is never downloaded again, so
an old recipe that still contains a link can be edited even if that site is gone.

Because anonymous users can trigger downloads, the server only fetches from the public internet: http(s) on ports
80/443, no credentials in the URL, private/loopback/link-local addresses (e.g. `localhost`, `192.168.x.x`,
`169.254.169.254`) are refused for the first request and every redirect, files are limited to 8 MB / 15 s, and the content
must really be an image. To import from a server in your own network, set `RECIPES_IMPORT_ALLOW_PRIVATE_HOSTS=1`
(or `import_allow_private_hosts` in `config/app.php`). Requires the `curl` PHP extension.

### JSON format

```json
{
  "title": "Tomato Bruschetta",
  "description": "Crisp toasted bread with marinated tomatoes.",
  "category": "Starter",
  "image": "https://example.com/bruschetta.jpg",
  "cooking_time": 15,
  "ingredients": [
    {"quantity": "8 slices", "name": "ciabatta"},
    {"quantity": "500 g", "name": "ripe tomatoes, diced"}
  ],
  "steps": ["Toast the bread.", "Mix the tomatoes with oil and salt.", "Top the toast."],
  "tags": ["italian", "vegetarian"]
}
```

Required: `title`, `cooking_time` (minutes), `ingredients`, `steps`. Ingredients may also be plain strings.

## Architecture

```
public/            web root: index.php (front controller), assets (app.js shared, one script per page),
                   images (recipe photos), uploads (user uploads)
                   assets/vendor/: Bootstrap, Bootstrap Icons, jQuery and the web fonts (no CDN needed)
var/state/         persistence: recipes.json or recipes.sqlite, local account/role changes, tokens (not versioned)
var/sessions/      PHP sessions (not versioned)
config/app.php     storage backend, base_dir/base_url, authentication settings
bin/migrate        copies recipes between storage backends
bin/account        list accounts, set a password, create an account (recovery)
bin/dev-permissions  lets the web server write var/, var/sessions/, uploads and images
defaults/          seed data for a fresh install: recipes.json, images/, and the built-in accounts, roles and access
src/
  Application.php  wiring, routes, permission per route, base_dir/base_url
  Auth/            SecurityController, AuthenticatorDefault, AuthorizerDefault (+ Contract/), WebAuth, Csrf, LayeredStore
  Http/            Request, Response, JsonResponse, RedirectResponse, Router, Session, Cookie
  Controller/      AbstractController -> RecipeController, UploadController, PageController, AuthController, AdminController
  Model/           Recipe, Ingredient (readonly value classes)
  Repository/      RecipeRepositoryInterface -> AbstractRecipeRepository (search, ranking)
                   -> JsonFileRecipeRepository   (var/state/recipes.json)
                   -> SqliteRecipeRepository     (var/state/recipes.sqlite)
                   RepositoryFactory (picks the backend from config/app.php)
  View/            View (renders templates inside the layout, escaping helpers)
  Validation/      RecipeValidator
  Service/         ImageStorage, ImageLocalizer + CurlImageFetcher (image import), SlugGenerator, Seeder
templates/         layout, home, recipe, form, import, error
tests/             PHPUnit
```

## Storage

Persistence is hidden behind `RecipeRepositoryInterface`. Two backends are included and share the same search and
ranking logic (`AbstractRecipeRepository`); one test suite runs against both.

| Backend | File | Notes |
|---|---|---|
| `json` (default) | `var/state/recipes.json` | single human-readable file, file locking and atomic writes |
| `sqlite` | `var/state/recipes.sqlite` | needs `pdo_sqlite`; WAL mode, transactional writes, schema is created automatically |

Select the backend in `config/app.php` (`'storage' => 'sqlite'`) or with an environment variable, e.g.
`RECIPES_STORAGE=sqlite composer start`. The file locations can be changed with `RECIPES_STATE_FILE` (JSON) and
`RECIPES_SQLITE_FILE` (SQLite).

**Switching an existing installation** – copy your recipes first, then change the setting:

```bash
php bin/migrate json sqlite     # or: php bin/migrate sqlite json
```

The copy is non-destructive: recipes with the same id in the target are overwritten, all others are kept, and the source
is left untouched. Without migrating, a new, empty storage is seeded with the defaults.

To add another backend, extend `AbstractRecipeRepository` (implement `all`, `saveMany`, `delete`; override `find` if
a lookup by id can be done faster), register it in `RepositoryFactory`, and add a subclass of
`tests/RecipeRepositoryContract.php`.

### Seeding

On the first request, if the configured storage file does not exist yet, the `Seeder` saves `defaults/recipes.json` into it
(JSON or SQLite alike) and copies `defaults/images/*` into `public/images/` (existing files are never overwritten).
To reset to the sample recipes, delete the storage file. Your own recipes and uploads are not part of the defaults.

### REST API

All calls need the permission of their action (`show` for reads, `recipe_create`, `recipe_edit`, `recipe_delete`, `recipe_import`, `recipe_export`, `recipe_export_all`, `image_upload`).
Use HTTP Basic, or a session with the `X-CSRF-Token` header.

| Method | Path | |
|---|---|---|
| GET | `/api/recipes?q=&category=` | list / search |
| POST | `/api/recipes` | create |
| GET / PUT / DELETE | `/api/recipes/{id}` | read / update / delete (the API uses the internal id) |
| POST | `/api/recipes/import` | bulk import; response includes `warnings` |
| GET | `/api/recipes/export`, `/api/recipes/{id}/export` | JSON download |
| POST | `/api/uploads` | multipart image upload (`image`) → `{"path": "uploads/…"}` |
