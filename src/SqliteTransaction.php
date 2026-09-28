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

use Amp\Sql\SqlTransaction;

/**
 * @extends SqlTransaction<SqliteResult, SqliteStatement, SqliteTransaction>
 */
interface SqliteTransaction extends SqliteLink, SqlTransaction
{
    /**
     * @throws SqliteTransactionError If the transaction is inactive, has an active nested transaction, or has unread results
     *                                 or open BLOB streams owned by the current fiber.
     * @throws SqliteQueryError If SQLite rejects the commit, for example because of a deferred constraint.
     * @throws SqliteConnectionException If the connection is lost while committing.
     */
    public function commit(): void;

    public function getIsolation(): SqliteTransactionMode;
}
