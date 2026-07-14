<?php

declare(strict_types=1);

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
        return $path[0] === '/' || $path[0] === '\\' || (isset($path[2]) && \ctype_alpha($path[0]) && $path[1] === ':');
    }
}
