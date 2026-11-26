<?php

declare(strict_types=1);

namespace App\View;

use App\Http\Request;
use App\Model\Recipe;
use Throwable;

/**
 * Renders plain PHP templates. Every template receives its variables plus a few helpers:
 * $e (escape), $url (base path aware URL), $img (image path -> URL), $minutes, $highlight, $servings.
 * Inside a template, $this->render('_partial', [...]) includes another template.
 */
final class View
{
    /** @param array<string, mixed> $shared variables every template gets (user, can, csrf, flash, ...) */
    public function __construct(
        private readonly string $dir,
        private readonly string $basePath = '',
        private readonly array $shared = [],
    ) {
    }

    /** A view that knows who is signed in (set up by the Application for every request). */
    public static function forRequest(string $dir, Request $request): self
    {
        return new self($dir, $request->basePath, $request->attribute('shared', []));
    }

    /** @param array<string, mixed> $vars */
    public function render(string $template, array $vars = []): string
    {
        $e = static fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $highlight = static function (string $text, array $terms) use ($e): string {
            if ($terms === []) {
                return $e($text);
            }
            $regex = '/(' . implode('|', array_map(static fn (string $t): string => preg_quote($t, '/'), $terms)) . ')/iu';
            $out = '';
            foreach (preg_split($regex, $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $i => $part) {
                $out .= $i % 2 === 1 ? '<mark>' . $e($part) . '</mark>' : $e($part);
            }

            return $out;
        };

        $helpers = [
            'e' => $e,
            'highlight' => $highlight,
            'basePath' => $this->basePath,
            'servings' => Recipe::SERVINGS,
            'url' => fn (string $path = '/'): string => $this->basePath . $path,
            'img' => fn (?string $path): ?string => match (true) {
                $path === null || $path === '' => null,
                (bool) preg_match('#^https?://#i', $path) => $path,
                default => $this->basePath . '/' . $path,
            },
            'minutes' => static function (int $min): string {
                if ($min < 60) {
                    return $min . ' min';
                }

                return intdiv($min, 60) . ' h' . ($min % 60 !== 0 ? ' ' . $min % 60 . ' min' : '');
            },
        ];

        $sharedDefaults = [
            'appTitle' => 'Recipe Box',
            'asset' => static fn (string $path): string => $path,
            'version' => '',
            'authEnabled' => false,
            'user' => null,
            'isGuest' => false,
            'can' => static fn (string $permission): bool => true,
            'csrf' => static fn (): string => '',
            'flash' => static fn (): array => [],
        ];

        extract($helpers);
        extract($vars, EXTR_SKIP);
        extract($this->shared + $sharedDefaults, EXTR_SKIP);

        ob_start();
        try {
            require $this->dir . '/' . $template . '.php';
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }

    /**
     * Renders a template inside the common layout.
     *
     * @param array<string, mixed> $vars
     * @param list<string>         $scripts page specific scripts below assets/js/
     */
    public function page(
        string $template,
        array $vars,
        string $title,
        string $active = '',
        array $scripts = [],
        string $description = 'Cooking recipes for four people: ingredients, cooking time and step by step instructions.',
    ): string {
        return $this->render('layout', [
            'content' => $this->render($template, $vars),
            'title' => $title,
            'active' => $active,
            'scripts' => $scripts,
            'description' => $description,
        ]);
    }
}
