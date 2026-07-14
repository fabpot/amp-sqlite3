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

use Fabpot\Amp\Sqlite\SqliteConfig;
use Fabpot\Amp\Sqlite\SqliteConnector;
use Fabpot\Amp\Sqlite\SqliteQueryError;
use PHPUnit\Framework\TestCase;
use function Amp\async;
use function Amp\delay;

final class SqliteBackupTest extends TestCase
{
    /** @var \Fabpot\Amp\Sqlite\SqliteConnection */
    private $connection;
    private string $path;

    protected function setUp(): void
    {
        $this->connection = (new SqliteConnector())->connect(new SqliteConfig(':memory:'));
        $this->path = \sys_get_temp_dir() . '/amp-sqlite-backup-' . \bin2hex(\random_bytes(8)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        @\unlink($this->path);
    }

    public function testBacksUpMemoryDatabaseToFile(): void
    {
        $this->connection->query('CREATE TABLE entries (value TEXT)');
        $this->connection->execute('INSERT INTO entries VALUES (?), (?)', ['a', 'b']);

        $this->connection->backup($this->path);

        $copy = (new SqliteConnector())->connect(new SqliteConfig($this->path));
        try {
            self::assertSame(['c' => 2], $copy->query('SELECT COUNT(*) AS c FROM entries')->fetchRow());
        } finally {
            $copy->close();
        }
    }

    public function testBackupReplacesExistingDestinationContents(): void
    {
        $existing = new \SQLite3($this->path);
        $existing->exec('CREATE TABLE stale (x INTEGER)');
        $existing->close();

        $this->connection->query('CREATE TABLE entries (value TEXT)');
        $this->connection->backup($this->path);

        $copy = (new SqliteConnector())->connect(new SqliteConfig($this->path));
        try {
            self::assertSame(['c' => 0], $copy->query('SELECT COUNT(*) AS c FROM entries')->fetchRow());

            $this->expectException(SqliteQueryError::class);
            $copy->query('SELECT COUNT(*) FROM stale');
        } finally {
            $copy->close();
        }
    }

    public function testRestoresFileIntoMemoryDatabase(): void
    {
        $source = new \SQLite3($this->path);
        $source->exec('CREATE TABLE entries (value TEXT)');
        $source->exec("INSERT INTO entries VALUES ('restored')");
        $source->close();

        $this->connection->query('CREATE TABLE replaced (x INTEGER)');
        $this->connection->restore($this->path);

        self::assertSame(['value' => 'restored'], $this->connection->query('SELECT value FROM entries')->fetchRow());

        $this->expectException(SqliteQueryError::class);
        $this->connection->query('SELECT COUNT(*) FROM replaced');
    }

    public function testBackupRoundTrip(): void
    {
        $this->connection->query('CREATE TABLE entries (value TEXT)');
        $this->connection->execute('INSERT INTO entries VALUES (?)', ['round trip']);
        $this->connection->backup($this->path);

        $this->connection->query('DROP TABLE entries');
        $this->connection->restore($this->path);

        self::assertSame(['value' => 'round trip'], $this->connection->query('SELECT value FROM entries')->fetchRow());
    }

    public function testRestoreFailsForMissingSource(): void
    {
        $this->expectException(SqliteQueryError::class);

        $this->connection->restore($this->path);
    }

    public function testRejectsMemoryPath(): void
    {
        $this->expectException(\ValueError::class);

        $this->connection->backup(':memory:');
    }

    public function testBackupWaitsForActiveTransaction(): void
    {
        $this->connection->query('CREATE TABLE entries (value TEXT)');
        $transaction = $this->connection->beginTransaction();
        $transaction->execute('INSERT INTO entries VALUES (?)', ['committed later']);

        $backup = async(fn () => $this->connection->backup($this->path));
        delay(0.05);
        self::assertFalse($backup->isComplete());

        $transaction->commit();
        $backup->await();

        $copy = (new SqliteConnector())->connect(new SqliteConfig($this->path));
        try {
            self::assertSame(['c' => 1], $copy->query('SELECT COUNT(*) AS c FROM entries')->fetchRow());
        } finally {
            $copy->close();
        }
    }
}
