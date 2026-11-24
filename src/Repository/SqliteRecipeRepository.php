<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\Recipe;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Persists recipes in a SQLite database. Ingredients, steps and tags are stored as JSON columns
 * since they are always read and written together with their recipe. The schema is created on first use.
 */
class SqliteRecipeRepository extends AbstractRecipeRepository
{
    private readonly PDO $pdo;

    public function __construct(string $file)
    {
        if (!extension_loaded('pdo_sqlite')) {
            throw new RuntimeException('The pdo_sqlite PHP extension is required for SQLite storage.');
        }

        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create data directory: $dir");
        }

        $this->pdo = new PDO('sqlite:' . $file, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA synchronous = NORMAL');
        $this->migrate();
    }

    public function all(): array
    {
        $rows = $this->pdo->query('SELECT * FROM recipes ORDER BY updated_at DESC, id DESC')->fetchAll();

        return array_map($this->hydrate(...), $rows);
    }

    public function find(string $id): ?Recipe
    {
        $stmt = $this->pdo->prepare('SELECT * FROM recipes WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    public function saveMany(array $recipes): array
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
            INSERT INTO recipes (id, title, description, category, image, cooking_time, ingredients, steps, tags, created_at, updated_at)
            VALUES (:id, :title, :description, :category, :image, :cooking_time, :ingredients, :steps, :tags, :created_at, :updated_at)
            ON CONFLICT(id) DO UPDATE SET
                title = excluded.title,
                description = excluded.description,
                category = excluded.category,
                image = excluded.image,
                cooking_time = excluded.cooking_time,
                ingredients = excluded.ingredients,
                steps = excluded.steps,
                tags = excluded.tags,
                updated_at = excluded.updated_at
            SQL);

        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            foreach ($recipes as $recipe) {
                $stmt->execute([
                    'id' => $recipe->id,
                    'title' => $recipe->title,
                    'description' => $recipe->description,
                    'category' => $recipe->category,
                    'image' => $recipe->image,
                    'cooking_time' => $recipe->cookingTime,
                    'ingredients' => $this->encode($recipe->ingredients),
                    'steps' => $this->encode($recipe->steps),
                    'tags' => $this->encode($recipe->tags),
                    'created_at' => $recipe->createdAt,
                    'updated_at' => $recipe->updatedAt,
                ]);
            }
            $this->pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        }

        return $recipes;
    }

    public function delete(string $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM recipes WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    private function migrate(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS recipes (
                id           TEXT PRIMARY KEY,
                title        TEXT NOT NULL,
                description  TEXT NOT NULL DEFAULT '',
                category     TEXT NOT NULL DEFAULT '',
                image        TEXT,
                cooking_time INTEGER NOT NULL,
                ingredients  TEXT NOT NULL,
                steps        TEXT NOT NULL,
                tags         TEXT NOT NULL,
                created_at   TEXT NOT NULL,
                updated_at   TEXT NOT NULL
            )
            SQL);
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_recipes_updated_at ON recipes (updated_at)');
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Recipe
    {
        foreach (['ingredients', 'steps', 'tags'] as $column) {
            $row[$column] = json_decode((string) $row[$column], true, flags: JSON_THROW_ON_ERROR);
        }

        return Recipe::fromArray($row);
    }

    private function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
