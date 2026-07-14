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

/**
 * @extends SqlConnection<SqliteConfig, SqliteResult, SqliteStatement, SqliteTransaction>
 */
interface SqliteConnection extends SqliteLink, SqlConnection
{
    public function getConfig(): SqliteConfig;

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
