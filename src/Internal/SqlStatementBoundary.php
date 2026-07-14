<?php

declare(strict_types=1);

namespace Fabpot\Amp\Sqlite\Internal;

final class SqlStatementBoundary
{
    public static function hasSecondStatement(string $remainder): bool
    {
        do {
            $previous = $remainder;
            $remainder = \ltrim($remainder);
            $remainder = (string) \preg_replace(
                '/\A(?:--[^\r\n]*(?:\r?\n|$)|\/\*.*?\*\/)/s',
                '',
                $remainder,
                1,
            );
        } while ($remainder !== $previous);

        return $remainder !== '';
    }
}
