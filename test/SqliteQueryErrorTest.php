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

use Fabpot\Amp\Sqlite\SqliteQueryError;
use PHPUnit\Framework\TestCase;

final class SqliteQueryErrorTest extends TestCase
{
    public function testExposesSQLiteCodesWithoutIncludingParameters(): void
    {
        $error = new SqliteQueryError(
            'Constraint failed',
            'INSERT INTO users (password) VALUES (:password)',
            19,
            2067,
        );

        self::assertSame(19, $error->getResultCode());
        self::assertSame(2067, $error->getExtendedResultCode());
        self::assertStringNotContainsString('secret', (string) $error);
    }
}
