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
        do {
            $previous = $remainder;
            $remainder = \ltrim($remainder);
            $remainder = (string) \preg_replace(
                '/\A(?:--[^\r\n]*(?:\r?\n|$)|\/\*.*?(?:\*\/|\z))/s',
                '',
                $remainder,
                1,
            );
        } while ($remainder !== $previous);

        return $remainder !== '';
    }
}
