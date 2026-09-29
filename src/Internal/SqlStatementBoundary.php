<?php

declare(strict_types=1);

/*
 * This file is part of the fabpot/amphp-sqlite3 package.
 *
 * (c) Fabien Potencier <fabien@potencier.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Fabpot\Amp\Sqlite\Internal;

/** @internal */
final class SqlStatementBoundary
{
    public static function hasSecondStatement(string $remainder): bool
    {
        return self::insignificantLength($remainder) < \strlen($remainder);
    }

    public static function startsWithKeyword(string $sql, string ...$keywords): bool
    {
        return (bool) \preg_match('/\A(?:' . \implode('|', $keywords) . ')\b/i', self::skipInsignificant($sql));
    }

    /**
     * Strips leading whitespace, comments, and empty statements.
     */
    public static function skipInsignificant(string $sql): string
    {
        return \substr($sql, self::insignificantLength($sql));
    }

    /**
     * Mirrors SQLite's tokenizer: whitespace starts with a space, tab, newline, form feed, or carriage return and may
     * then include vertical tabs, a line comment only ends at a newline, and a lone trailing "/*" is not a comment.
     */
    private static function insignificantLength(string $sql): int
    {
        $length = \strlen($sql);
        $offset = 0;

        while ($offset < $length) {
            $char = $sql[$offset];
            if ($char === ';') {
                ++$offset;
            } elseif (\str_contains(" \t\n\f\r", $char)) {
                $offset += 1 + \strspn($sql, " \t\n\v\f\r", $offset + 1);
            } elseif ($char === '-' && ($sql[$offset + 1] ?? '') === '-') {
                $end = \strpos($sql, "\n", $offset + 2);
                $offset = $end === false ? $length : $end;
            } elseif ($char === '/' && ($sql[$offset + 1] ?? '') === '*' && $offset + 2 < $length) {
                $end = \strpos($sql, '*/', $offset + 2);
                $offset = $end === false ? $length : $end + 2;
            } else {
                break;
            }
        }

        return $offset;
    }
}
