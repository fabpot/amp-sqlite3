<?php

declare(strict_types=1);

namespace Fabpot\Amp\Sqlite;

use Amp\Sql\SqlQueryError;

class SqliteQueryError extends SqlQueryError
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
