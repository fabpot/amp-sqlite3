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
    public static function validate(?string $path): void
    {
        if ($path === null || $path === '') {
            throw new \InvalidArgumentException('SQLite database path must not be empty');
        }

        if (\strncasecmp($path, 'file:', 5) === 0) {
            throw new \InvalidArgumentException('SQLite URI filenames are not supported');
        }
    }

    public static function resolve(?string $path): string
    {
        $path = (string) $path;

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
        if (!isset($path[0])) {
            return false;
        }

        if ($path[0] === '/' || $path[0] === '\\') {
            return true;
        }

        return isset($path[2])
            && (('A' <= $path[0] && $path[0] <= 'Z') || ('a' <= $path[0] && $path[0] <= 'z'))
            && $path[1] === ':'
            && ($path[2] === '/' || $path[2] === '\\');
    }
}
