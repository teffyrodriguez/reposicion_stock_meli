<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Console;

/**
 * Lee un archivo .env simple (KEY=VALUE). Las variables ya definidas en el proceso ganan.
 */
final class Env
{
    /**
     * @return array<string, string>
     */
    public static function loadFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException('No se pudo leer ' . $path);
        }

        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $values = [];

        foreach (preg_split("/\r\n|\n|\r/", $contents) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }

            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }

            $key = trim(substr($line, 0, $eq));
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
                continue;
            }

            $value = trim(substr($line, $eq + 1));
            if (
                strlen($value) >= 2
                && (($value[0] === '"' && str_ends_with($value, '"'))
                    || ($value[0] === "'" && str_ends_with($value, "'")))
            ) {
                $value = substr($value, 1, -1);
            }

            $values[$key] = $value;
        }

        return $values;
    }

    /**
     * @param array<string, string> $file
     */
    public static function pick(false|string $process, array $file, string $key, ?string $default = null): string
    {
        if ($process !== false) {
            return $process;
        }
        if (array_key_exists($key, $file)) {
            return $file[$key];
        }
        if ($default !== null) {
            return $default;
        }

        throw new \RuntimeException('Falta la variable ' . $key . ' en .env');
    }
}
