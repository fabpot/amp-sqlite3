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

use Amp\Sql\SqlConnection;
use Amp\Sql\SqlTransactionIsolation;

/**
 * @extends SqlConnection<SqliteConfig, SqliteResult, SqliteStatement, SqliteTransaction>
 */
interface SqliteConnection extends SqliteLink, SqlConnection
{
    /**
     * @throws SqliteTransactionError If the current fiber owns unread results or open BLOB streams of the active transaction.
     * @throws SqliteConnectionException If the connection is closed or lost while beginning the transaction.
     */
    public function beginTransaction(): SqliteTransaction;

    public function getConfig(): SqliteConfig;

    public function getTransactionIsolation(): SqliteTransactionMode;

    /**
     * @throws \InvalidArgumentException If the isolation is not a SqliteTransactionMode.
     */
    public function setTransactionIsolation(SqlTransactionIsolation $isolation): void;

    /**
     * Executes one or more SQL statements without parameters.
     *
     * Statements execute atomically using the connection's configured transaction mode.
     */
    public function executeScript(string $sql): void;

    /**
     * Copies the entire database to the given file using SQLite's online backup API,
     * replacing any existing contents. The destination must not be an open database.
     */
    public function backup(string $destinationPath, string $database = 'main'): void;

    /**
     * Replaces the entire database with the contents of the given file using SQLite's
     * online backup API. This is the only way to load a file into a :memory: database.
     */
    public function restore(string $sourcePath, string $database = 'main'): void;
}
