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

namespace Fabpot\Amp\Sqlite\Test;

final class SqlCallables
{
    public static function slugify(string $value): string
    {
        return \strtolower(\str_replace(' ', '-', $value));
    }

    public static function longestStep(?string $context, int $rowNumber, string $value): string
    {
        return \strlen($value) > \strlen($context ?? '') ? $value : ($context ?? '');
    }

    public static function longestFinal(?string $context, int $rowCount): ?string
    {
        return $context;
    }

    public static function compareByLength(string $a, string $b): int
    {
        return \strlen($a) <=> \strlen($b) ?: \strcmp($a, $b);
    }

    public function instanceMethod(): void
    {
    }
}
