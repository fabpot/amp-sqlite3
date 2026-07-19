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

use Fabpot\Amp\Sqlite\SqliteConnectionException;

/** @internal */
final class Path
{
    public static function resolve(string $path): string
    {
        if ($path === ':memory:' || self::isAbsolute($path)) {
            return $path;
        }

        $workingDirectory = \getcwd();
        if ($workingDirectory === false) {
            throw new SqliteConnectionException('Could not determine the current working directory');
        }

        return $workingDirectory . \DIRECTORY_SEPARATOR . $path;
    }

    private static function isAbsolute(string $path): bool
    {
        return $path[0] === '/' || $path[0] === '\\' || (isset($path[2]) && (('A' <= $path[0] && $path[0] <= 'Z') || ('a' <= $path[0] && $path[0] <= 'z')) && $path[1] === ':');
    }
}
