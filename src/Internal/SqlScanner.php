<?php

declare(strict_types=1);

namespace Fabpot\Amp\Sqlite\Internal;

final class SqlScanner
{
    /**
     * @return list<array{style: 'named'|'positional'|'unsupported', name: string}>
     */
    public static function placeholders(string $sql): array
    {
        $placeholders = [];
        $length = \strlen($sql);

        for ($offset = 0; $offset < $length;) {
            $character = $sql[$offset];

            if ($character === "'" || $character === '"' || $character === '`') {
                $offset = self::skipQuoted($sql, $offset, $character);
                continue;
            }

            if ($character === '[') {
                $end = \strpos($sql, ']', $offset + 1);
                $offset = $end === false ? $length : $end + 1;
                continue;
            }

            if ($character === '-' && ($sql[$offset + 1] ?? '') === '-') {
                $end = \strpos($sql, "\n", $offset + 2);
                $offset = $end === false ? $length : $end + 1;
                continue;
            }

            if ($character === '/' && ($sql[$offset + 1] ?? '') === '*') {
                $end = \strpos($sql, '*/', $offset + 2);
                $offset = $end === false ? $length : $end + 2;
                continue;
            }

            if ($character === '?') {
                if (\ctype_digit($sql[$offset + 1] ?? '')) {
                    $placeholders[] = ['style' => 'unsupported', 'name' => '?'];
                    $offset += 2;
                } else {
                    $placeholders[] = ['style' => 'positional', 'name' => '?'];
                    ++$offset;
                }
                continue;
            }

            if ($character === ':' || $character === '@' || $character === '$') {
                $end = $offset + 1;
                while ($end < $length && (\ctype_alnum($sql[$end]) || $sql[$end] === '_')) {
                    ++$end;
                }

                if ($end > $offset + 1) {
                    $placeholders[] = [
                        'style' => $character === ':' ? 'named' : 'unsupported',
                        'name' => \substr($sql, $offset, $end - $offset),
                    ];
                    $offset = $end;
                    continue;
                }
            }

            ++$offset;
        }

        return $placeholders;
    }

    public static function hasExecutableSql(string $sql): bool
    {
        return self::stripIgnorable($sql, true) !== '';
    }

    public static function hasSecondStatement(string $remainder): bool
    {
        return self::stripIgnorable($remainder, false) !== '';
    }

    private static function skipQuoted(string $sql, int $offset, string $quote): int
    {
        $length = \strlen($sql);

        for (++$offset; $offset < $length; ++$offset) {
            if ($sql[$offset] !== $quote) {
                continue;
            }

            if (($sql[$offset + 1] ?? '') === $quote) {
                ++$offset;
                continue;
            }

            return $offset + 1;
        }

        return $length;
    }

    private static function stripIgnorable(string $sql, bool $stripSemicolons): string
    {
        do {
            $previous = $sql;
            $sql = \ltrim($sql, $stripSemicolons ? " \t\r\n;" : " \t\r\n");
            $sql = (string) \preg_replace('/\A(?:--[^\r\n]*(?:\r?\n|$)|\/\*.*?\*\/)/s', '', $sql, 1);
        } while ($sql !== $previous);

        return $sql;
    }
}
