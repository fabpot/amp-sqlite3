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
        return self::skipInsignificant($remainder) !== '';
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
        return (string) \preg_replace('/\A(?:[ \t\n\f\r;]+|--[^\r\n]*(?:\r?\n|$)|\/\*.*?(?:\*\/|\z))*/s', '', $sql, 1);
    }
}
