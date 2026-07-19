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

namespace Fabpot\Amp\Sqlite;

use Amp\Sql\SqlQueryError;

class SqliteQueryError extends SqlQueryError implements SqliteExceptionInterface
{
    public function __construct(
        string $message,
        string $query = '',
        private readonly int $resultCode = 0,
        private readonly int $extendedResultCode = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $query, $previous);
    }

    public function getResultCode(): int
    {
        return $this->resultCode;
    }

    public function getExtendedResultCode(): int
    {
        return $this->extendedResultCode;
    }
}
