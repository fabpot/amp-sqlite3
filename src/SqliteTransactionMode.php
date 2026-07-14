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
