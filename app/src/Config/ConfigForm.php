<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Turns the submitted configuration form into typed values and validation errors.
 *
 * Every key from defaults/config.php is considered; keys that are not shipped are ignored, so a
 * request can never add settings of its own. Values are cast according to ConfigSchema.
 */
final class ConfigForm
{
    /**
     * @param  array<string, mixed> $defaults shipped defaults (also the allow-list of settings)
     * @param  array<string, mixed> $current  defaults merged with the local configuration
     * @param  array<string, mixed> $posted   the "config" fields of the request
     * @return array{values: array<string, mixed>, errors: array<string, string>}
     */
    public function parse(array $defaults, array $current, array $posted): array
    {
        $values = [];
        $errors = [];

        foreach ($defaults as $key => $default) {
            $key = (string) $key;
            $meta = ConfigSchema::describe($key, $default);

            if ($meta['type'] === 'onoff') {
                $values[$key] = array_key_exists($key, $posted)
                    ? self::toBool($posted[$key])
                    : (bool) ($current[$key] ?? $default);
                continue;
            }

            if (!array_key_exists($key, $posted)) {
                $values[$key] = $current[$key] ?? $default;
                continue;
            }

            $raw = $posted[$key];

            if ($meta['type'] === 'int') {
                $text = self::text($raw);
                if (preg_match('/^-?\d+$/', $text) !== 1) {
                    $errors[$key] = 'Enter a whole number.';
                    $values[$key] = $text;
                    continue;
                }

                $number = (int) $text;
                if (($meta['min'] !== null && $number < $meta['min']) || ($meta['max'] !== null && $number > $meta['max'])) {
                    $limits = [];
                    if ($meta['min'] !== null) {
                        $limits[] = 'at least ' . $meta['min'];
                    }
                    if ($meta['max'] !== null) {
                        $limits[] = 'at most ' . $meta['max'];
                    }
                    $errors[$key] = 'Enter a number ' . implode(' and ', $limits) . '.';
                    $values[$key] = $text;
                    continue;
                }

                $values[$key] = $number;
                continue;
            }

            if ($meta['type'] === 'multichoice') {
                $found = false;
                $selected = null;
                foreach ($meta['choices'] as $choice) {
                    if ($choice['key'] === self::text($raw)) {
                        $found = true;
                        $selected = $choice['value'];
                        break;
                    }
                }

                if (!$found) {
                    $errors[$key] = 'Choose one of the allowed values.';
                    $values[$key] = $current[$key] ?? $default;
                    continue;
                }

                $values[$key] = $selected;
                continue;
            }

            if ($meta['type'] === 'textarea') {
                $text = str_replace("\r\n", "\n", self::text($raw));
                // An emptied textarea means "use the shipped text". Storing '' would pin an empty
                // value that also hides later improvements to the default.
                $values[$key] = trim($text) === '' ? $default : $text;
                continue;
            }

            if ($meta['type'] === 'password') {
                if (!empty($posted[$key . '__clear'])) {
                    $values[$key] = '';
                    continue;
                }

                // An empty field means "keep the stored secret": it is deliberately never sent to the browser.
                $text = trim(self::text($raw));
                $values[$key] = $text === '' ? ($current[$key] ?? $default) : $text;
                continue;
            }

            if ($meta['nullable']) {
                if (!empty($posted[$key . '__null'])) {
                    $values[$key] = null;
                    continue;
                }

                $text = trim(self::text($raw));
                if ($text === '' && !$meta['allow_empty']) {
                    $errors[$key] = 'Enter a value or use the automatic value.';
                    $values[$key] = '';
                    continue;
                }

                $values[$key] = $text;
                continue;
            }

            // string
            $values[$key] = trim(self::text($raw));
        }

        return ['values' => $values, 'errors' => $errors];
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return is_scalar($value) && in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes'], true);
    }
}
