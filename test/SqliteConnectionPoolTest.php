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
use Fabpot\Amp\Sqlite\SqliteConnectionPool;
use PHPUnit\Framework\TestCase;
use function Amp\async;
use function Amp\delay;

final class SqliteConnectionPoolTest extends TestCase
{
    private string $path;
    private SqliteConnectionPool $pool;

    protected function setUp(): void
    {
        $this->path = \sys_get_temp_dir() . '/amp-sqlite-pool-' . \bin2hex(\random_bytes(8)) . '.sqlite';
        $this->pool = new SqliteConnectionPool(new SqliteConfig($this->path));
        $this->pool->query('CREATE TABLE entries (value TEXT)')->close();
    }

    protected function tearDown(): void
    {
        $this->pool->close();
        @\unlink($this->path);
        @\unlink($this->path . '-shm');
        @\unlink($this->path . '-wal');
    }

    public function testRejectsMemoryDatabases(): void
    {
        $this->expectException(\ValueError::class);

        new SqliteConnectionPool(new SqliteConfig(':memory:'));
    }

    public function testQueriesRunOnPooledConnections(): void
    {
        $this->pool->execute('INSERT INTO entries VALUES (?)', ['pooled']);

        self::assertSame(
            [['value' => 'pooled']],
            \iterator_to_array($this->pool->query('SELECT value FROM entries')),
        );
        self::assertGreaterThan(0, $this->pool->getConnectionCount());
    }

    public function testConcurrentQueriesUseSeparateConnections(): void
    {
        $this->pool->execute('INSERT INTO entries VALUES (?)', ['row']);

        $first = async(fn () => $this->pool->query('SELECT value FROM entries UNION ALL SELECT value FROM entries'));
        $second = async(fn () => $this->pool->query('SELECT value FROM entries'));

        $firstResult = $first->await();
        $secondResult = $second->await();

        self::assertCount(1, \iterator_to_array($secondResult));
        self::assertCount(2, \iterator_to_array($firstResult));
        self::assertGreaterThanOrEqual(2, $this->pool->getConnectionCount());
    }

    public function testTransactionOwnsItsConnectionUntilFinished(): void
    {
        $transaction = $this->pool->beginTransaction();
        $transaction->execute('INSERT INTO entries VALUES (?)', ['in transaction']);

        self::assertSame([], \iterator_to_array($this->pool->query('SELECT value FROM entries')));

        $transaction->commit();

        self::assertSame(
            [['value' => 'in transaction']],
            \iterator_to_array($this->pool->query('SELECT value FROM entries')),
        );
    }

    public function testNestedTransactionsOnPooledConnection(): void
    {
        $transaction = $this->pool->beginTransaction();
        $nested = $transaction->beginTransaction();
        $nested->execute('INSERT INTO entries VALUES (?)', ['nested']);
        $nested->rollback();
        $transaction->commit();

        self::assertSame([], \iterator_to_array($this->pool->query('SELECT value FROM entries')));
    }

    public function testPreparedStatementsSurviveAcrossConnections(): void
    {
        $statement = $this->pool->prepare('INSERT INTO entries VALUES (?)');
        $statement->execute(['first']);
        $statement->execute(['second']);

        self::assertSame(
            [['value' => 'first'], ['value' => 'second']],
            \iterator_to_array($this->pool->query('SELECT value FROM entries ORDER BY value')),
        );
    }

    public function testOpenBlobReleasesConnectionOnClose(): void
    {
        $rowId = $this->pool->query('INSERT INTO entries VALUES (zeroblob(3))')->getLastInsertId();

        $blob = $this->pool->openBlob('entries', 'value', $rowId);
        $idleBefore = $this->pool->getIdleConnectionCount();
        $blob->close();
        delay(0.01);

        self::assertSame($idleBefore + 1, $this->pool->getIdleConnectionCount());
    }

    public function testExtractConnectionRemovesItFromThePool(): void
    {
        $countBefore = $this->pool->getConnectionCount();
        $connection = $this->pool->extractConnection();

        try {
            self::assertLessThan($countBefore + 1, $this->pool->getConnectionCount());
            self::assertSame(['answer' => 42], $connection->query('SELECT 42 AS answer')->fetchRow());
        } finally {
            $connection->close();
        }
    }

    public function testBackupRunsOnPooledConnection(): void
    {
        $this->pool->execute('INSERT INTO entries VALUES (?)', ['backed up']);
        $backupPath = $this->path . '.backup';

        try {
            $this->pool->backup($backupPath);

            $copy = new \SQLite3($backupPath);
            self::assertSame(1, $copy->querySingle('SELECT COUNT(*) FROM entries'));
            $copy->close();
        } finally {
            @\unlink($backupPath);
        }
    }

    public function testPooledResultExposesMetadata(): void
    {
        $insert = $this->pool->execute('INSERT INTO entries VALUES (?)', ['row']);

        self::assertSame(1, $insert->getRowCount());
        self::assertSame(1, $insert->getLastInsertId());

        $rows = $this->pool->query('SELECT value FROM entries');

        self::assertSame(['value'], $rows->getColumnNames());
        self::assertSame(1, $rows->getColumnCount());
    }
}
