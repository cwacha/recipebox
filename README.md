# Recipe Box

A small, modern recipe manager built with **PHP 8** (Composer, PSR-4 classes, inheritance, readonly classes),
**Bootstrap 5** and **jQuery**. Server-rendered pages, each with its own bookmarkable URL; jQuery adds live search,
the editor and the JSON API calls. No login: anyone can browse, add, edit, delete, import and export recipes.

## Run it

```bash
composer install
composer start          # php -S localhost:8000 -t public
```

Open <http://localhost:8000>. Run the tests with `composer test`.

Requirements: PHP 8.2+ with `json`, `fileinfo` and `curl` (plus `pdo_sqlite` for SQLite storage, `intl` is used for slugs when present), Composer. Bootstrap, Bootstrap Icons, jQuery and the web fonts
load from CDNs, so the browser needs internet access.

On Apache, point the document root at `public/` (`.htaccess` is included). Under nginx, route unknown paths to `public/index.php`.
`var/state/`, `public/images/` and `public/uploads/` must be writable by the web server.

## Pages

| URL | |
|---|---|
| `/` | recipe list with search; `/?q=tomato` and `/?category=Soup` are bookmarkable |
| `/recipes/{name}` | recipe: ingredients, cooking time, numbered steps, e.g. `/recipes/spaghetti-carbonara` |
| `/recipes/{name}/edit` | edit a recipe |
| `/recipes/new` | create a recipe |
| `/import` | import recipes from JSON |

Recipe URLs use a readable name (slug) generated from the title: umlauts become `ae`/`oe`/`ue`, duplicates get `-2`, `-3`, …
The slug stays the same when you rename a recipe, so bookmarks keep working. Old links that contain the internal id
(`/recipes/a1c0de000001`) redirect to the readable URL. Existing recipes get their slug automatically (JSON and SQLite).

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
var/state/         persistence: recipes.json or recipes.sqlite (created on first run, not versioned)
config/app.php     choose the storage backend and file locations
bin/migrate        copies recipes between storage backends
defaults/          seed data for a fresh install: recipes.json and images/
src/
  Application.php  wiring, routes, error handling
  Http/            Request, Response, JsonResponse (extends Response), Router
  Controller/      AbstractController -> RecipeController, UploadController, PageController
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

| Method | Path | |
|---|---|---|
| GET | `/api/recipes?q=&category=` | list / search |
| POST | `/api/recipes` | create |
| GET / PUT / DELETE | `/api/recipes/{id}` | read / update / delete (the API uses the internal id) |
| POST | `/api/recipes/import` | bulk import; response includes `warnings` |
| GET | `/api/recipes/export`, `/api/recipes/{id}/export` | JSON download |
| POST | `/api/uploads` | multipart image upload (`image`) → `{"path": "uploads/…"}` |
