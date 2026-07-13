<?php

declare(strict_types=1);

namespace Fabpot\Amp\Sqlite;

use Amp\Sql\SqlTransactionIsolation;

enum SqliteTransactionMode: string implements SqlTransactionIsolation
{
    case Deferred = 'deferred';
    case Immediate = 'immediate';
    case Exclusive = 'exclusive';

    public function getLabel(): string
    {
        return \ucfirst($this->value);
    }

    public function toSql(): string
    {
        return \strtoupper($this->value);
    }
}
