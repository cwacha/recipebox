# Recipe Box

A small, modern recipe manager built with **PHP 8** (Composer, PSR-4 classes, inheritance, readonly classes),
**Bootstrap 5** and **jQuery**. No login: anyone can browse, add, edit, delete, import and export recipes.

## Run it

```bash
composer install
composer start          # php -S localhost:8000 -t public
```

Open <http://localhost:8000>. Run the tests with `composer test`.

Requirements: PHP 8.2+ with `json` and `fileinfo`, Composer. Bootstrap, Bootstrap Icons, jQuery and the web fonts
load from CDNs, so the browser needs internet access.

On Apache, point the document root at `public/` (`.htaccess` is included). Under nginx, route unknown paths to `public/index.php`.
`data/` and `public/uploads/` must be writable by the web server.

## Features

- Search (title, category, tags, ingredients, description, steps) with ranking and highlighting; category filter chips
- Every recipe shows ingredients for **4 people**, the cooking time and numbered steps; tap ingredients and steps to tick them off while cooking
- Create / edit recipes in a form, with picture upload or image URL, reorderable steps
- **Import JSON** (file, drag & drop or paste) – a single recipe, a list, or `{"recipes": [...]}`; all-or-nothing validation
- Export one recipe or all recipes as JSON (same format the import accepts)
- Print view, dark mode, responsive layout

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
public/            web root: index.php (front controller), assets, images, uploads
src/
  Application.php  wiring, routes, error handling
  Http/            Request, Response, JsonResponse (extends Response), Router
  Controller/      AbstractController -> RecipeController, UploadController, PageController
  Model/           Recipe, Ingredient (readonly value classes)
  Repository/      RecipeRepositoryInterface -> AbstractRecipeRepository (search, ranking)
                   -> JsonFileRecipeRepository (storage in data/recipes.json)
  Validation/      RecipeValidator
  Service/         ImageStorage
templates/         home.php
tests/             PHPUnit
```

Persistence is hidden behind `RecipeRepositoryInterface`. To use a database, extend `AbstractRecipeRepository`
(implement `all`, `saveMany`, `delete`) and pass it to `Application`.
The JSON file repository uses file locking and atomic writes. Set `RECIPES_DATA_FILE` to move the file.

### REST API

| Method | Path | |
|---|---|---|
| GET | `/api/recipes?q=&category=` | list / search |
| POST | `/api/recipes` | create |
| GET / PUT / DELETE | `/api/recipes/{id}` | read / update / delete |
| POST | `/api/recipes/import` | bulk import |
| GET | `/api/recipes/export`, `/api/recipes/{id}/export` | JSON download |
| POST | `/api/uploads` | multipart image upload (`image`) → `{"path": "uploads/…"}` |
