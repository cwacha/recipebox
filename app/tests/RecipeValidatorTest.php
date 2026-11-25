<?php

declare(strict_types=1);

namespace Tests;

use App\Exception\ValidationException;
use App\Validation\RecipeValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecipeValidatorTest extends TestCase
{
    public function testNormalisesLenientInput(): void
    {
        $data = (new RecipeValidator())->validate([
            'title' => '  Soup ',
            'cookingTime' => '30',
            'ingredients' => ['2 carrots', ['amount' => '1 l', 'item' => 'water'], ['name' => ' ']],
            'steps' => ['Chop', ['text' => 'Boil'], ''],
            'tags' => 'Quick, quick , Vegan',
        ]);

        self::assertSame('Soup', $data['title']);
        self::assertSame(30, $data['cooking_time']);
        self::assertSame([['quantity' => '', 'name' => '2 carrots'], ['quantity' => '1 l', 'name' => 'water']], $data['ingredients']);
        self::assertSame(['Chop', 'Boil'], $data['steps']);
        self::assertSame(['quick', 'vegan'], $data['tags']);
    }

    public function testReportsAllMissingFields(): void
    {
        try {
            (new RecipeValidator())->validate(['title' => '', 'cooking_time' => 0]);
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(['title', 'cooking_time', 'ingredients', 'steps'], array_keys($e->errors));
        }
    }

    #[DataProvider('badImages')]
    public function testRejectsUnsafeImagePaths(string $image): void
    {
        $this->expectException(ValidationException::class);
        (new RecipeValidator())->validate($this->minimal(['image' => $image]));
    }

    /** @return iterable<string, array{string}> */
    public static function badImages(): iterable
    {
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'traversal' => ['../../etc/passwd.png'];
        yield 'absolute' => ['/etc/passwd.png'];
        yield 'php file' => ['uploads/shell.php'];
    }

    public function testAcceptsUrlAndRelativeImages(): void
    {
        $v = new RecipeValidator();
        self::assertSame('https://x.test/a.jpg', $v->validate($this->minimal(['image' => 'https://x.test/a.jpg']))['image']);
        self::assertSame('uploads/ab12.png', $v->validate($this->minimal(['image' => 'uploads/ab12.png']))['image']);
    }

    /** @return array<string, mixed> */
    private function minimal(array $override = []): array
    {
        return $override + [
            'title' => 'T',
            'cooking_time' => 5,
            'ingredients' => ['x'],
            'steps' => ['y'],
        ];
    }
}
