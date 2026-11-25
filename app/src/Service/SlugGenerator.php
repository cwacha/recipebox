<?php

declare(strict_types=1);

namespace App\Service;

use Transliterator;

/** Turns titles into readable, URL safe slugs such as "kaese-spaetzle". */
final class SlugGenerator
{
    /** Slugs that would clash with fixed routes (/recipes/new). */
    private const RESERVED = ['new'];
    private const MAX_LENGTH = 80;

    public static function slugify(string $text): string
    {
        $text = strtr($text, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss']);

        $transliterator = class_exists(Transliterator::class) ? Transliterator::create('Any-Latin; Latin-ASCII') : null;
        $ascii = $transliterator?->transliterate($text);
        if ($ascii === null || $ascii === false) {
            $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
        }

        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)), '-');
        if (strlen($slug) > self::MAX_LENGTH) {
            $slug = rtrim(substr($slug, 0, self::MAX_LENGTH), '-');
        }

        return $slug === '' ? 'recipe' : $slug;
    }

    /**
     * @param callable(string): bool $isTaken
     */
    public static function unique(string $title, callable $isTaken): string
    {
        $base = self::slugify($title);
        if (in_array($base, self::RESERVED, true)) {
            $base .= '-recipe';
        }

        $slug = $base;
        for ($n = 2; $isTaken($slug); $n++) {
            $slug = $base . '-' . $n;
        }

        return $slug;
    }
}
