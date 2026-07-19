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

use Amp\Sql\SqlConfig;
use Fabpot\Amp\Sqlite\SqliteConfig;
use Fabpot\Amp\Sqlite\SqliteConnectionException;
use Fabpot\Amp\Sqlite\SqliteConnector;
use Fabpot\Amp\Sqlite\SqliteJournalMode;
use Fabpot\Amp\Sqlite\SqliteOpenMode;
use PHPUnit\Framework\TestCase;
use function Amp\async;
use function Amp\delay;

final class SqliteConnectionTest extends TestCase
{
    public function testKeepsMemoryDatabaseStateInDedicatedProcess(): void
    {
        $connection = (new SqliteConnector())->connect(new SqliteConfig(':memory:'));

        try {
            $connection->query('CREATE TABLE users (name TEXT NOT NULL)');
            $connection->execute('INSERT INTO users VALUES (?)', ['Fabien']);

            self::assertSame(['name' => 'Fabien'], $connection->query('SELECT name FROM users')->fetchRow());
        } finally {
            $connection->close();
        }
    }

    public function testAppliesMemoryDatabaseDefaults(): void
    {
        $connection = (new SqliteConnector())->connect(new SqliteConfig(':memory:'));

        try {
            self::assertSame(['foreign_keys' => 1], $connection->query('PRAGMA foreign_keys')->fetchRow());
            self::assertSame(['trusted_schema' => 0], $connection->query('PRAGMA trusted_schema')->fetchRow());
            self::assertSame(['journal_mode' => 'memory'], $connection->query('PRAGMA journal_mode')->fetchRow());
        } finally {
            $connection->close();
        }
    }

    public function testResolvesRelativePathBeforeStartingChild(): void
    {
        $directory = \sys_get_temp_dir() . '/amp-sqlite-' . \bin2hex(\random_bytes(8));
        \mkdir($directory);
        $workingDirectory = \getcwd();
        \chdir($directory);

        try {
            $connection = (new SqliteConnector())->connect(new SqliteConfig('database.sqlite'));
            $connection->close();

            self::assertFileExists($directory . '/database.sqlite');
        } finally {
            \chdir($workingDirectory);
            @\unlink($directory . '/database.sqlite');
            @\rmdir($directory);
        }
    }

    public function testRecognizesWindowsAbsolutePaths(): void
    {
        self::assertSame('C:\\database.sqlite', \Fabpot\Amp\Sqlite\Internal\Path::resolve('C:\\database.sqlite'));
        self::assertSame('z:/database.sqlite', \Fabpot\Amp\Sqlite\Internal\Path::resolve('z:/database.sqlite'));
    }

    public function testRejectsNonSqliteConfig(): void
    {
        $config = new class('', 0) extends SqlConfig {
        };

        $this->expectException(\TypeError::class);

        (new SqliteConnector())->connect($config);
    }

    public function testRejectsInheritedServerConfiguration(): void
    {
        $this->expectException(\ValueError::class);

        (new SqliteConnector())->connect((new SqliteConfig(':memory:'))->withHost('localhost'));
    }

    public function testRevalidatesInheritedDatabaseMutation(): void
    {
        $this->expectException(\ValueError::class);

        (new SqliteConnector())->connect((new SqliteConfig(':memory:'))->withDatabase('file:database.sqlite'));
    }

    public function testRejectsMissingDatabaseInReadWriteMode(): void
    {
        $path = \sys_get_temp_dir() . '/amp-sqlite-' . \bin2hex(\random_bytes(8)) . '.sqlite';
        $config = (new SqliteConfig($path))->withOpenMode(SqliteOpenMode::ReadWrite);

        $this->expectException(SqliteConnectionException::class);

        (new SqliteConnector())->connect($config);
    }

    public function testEnablesWalForWritableFileDatabase(): void
    {
        $path = \sys_get_temp_dir() . '/amp-sqlite-' . \bin2hex(\random_bytes(8)) . '.sqlite';
        $connection = (new SqliteConnector())->connect(new SqliteConfig($path));

        try {
            self::assertSame(['journal_mode' => 'wal'], $connection->query('PRAGMA journal_mode')->fetchRow());
            self::assertSame(['synchronous' => 1], $connection->query('PRAGMA synchronous')->fetchRow());
        } finally {
            $connection->close();
            @\unlink($path);
            @\unlink($path . '-shm');
            @\unlink($path . '-wal');
        }
    }

    public function testConcurrentConnectionsCanInitializeNewDatabase(): void
    {
        $path = \sys_get_temp_dir() . '/amp-sqlite-' . \bin2hex(\random_bytes(8)) . '.sqlite';
        $connector = new SqliteConnector();
        $connections = [];
        $futures = [];

        try {
            for ($i = 0; $i < 10; ++$i) {
                $futures[] = async(fn () => $connector->connect(new SqliteConfig($path)));
            }

            foreach ($futures as $future) {
                $connection = $future->await();
                $connections[\spl_object_id($connection)] = $connection;
            }

            foreach ($connections as $connection) {
                self::assertSame(['journal_mode' => 'wal'], $connection->query('PRAGMA journal_mode')->fetchRow());
            }
        } finally {
            foreach ($futures as $future) {
                try {
                    $connection = $future->await();
                    $connections[\spl_object_id($connection)] = $connection;
                } catch (\Throwable) {
                }
            }
            foreach ($connections as $connection) {
                $connection->close();
            }
            @\unlink($path);
            @\unlink($path . '-shm');
            @\unlink($path . '-wal');
        }
    }

    public function testReadOnlyConnectionPreservesJournalMode(): void
    {
        $path = \sys_get_temp_dir() . '/amp-sqlite-' . \bin2hex(\random_bytes(8)) . '.sqlite';
        $database = new \SQLite3($path);
        $database->close();
        $config = (new SqliteConfig($path))->withOpenMode(SqliteOpenMode::ReadOnly);
        $connection = (new SqliteConnector())->connect($config);

        try {
            self::assertSame(['journal_mode' => 'delete'], $connection->query('PRAGMA journal_mode')->fetchRow());
        } finally {
            $connection->close();
            @\unlink($path);
        }
    }

    public function testAppliesAdditionalPragmas(): void
    {
        $config = (new SqliteConfig(':memory:'))->withPragma('cache_size', -123);
        $connection = (new SqliteConnector())->connect($config);

        try {
            self::assertSame(['cache_size' => -123], $connection->query('PRAGMA cache_size')->fetchRow());
        } finally {
            $connection->close();
        }
    }

    public function testProtocolErrorClosesConnection(): void
    {
        $factory = new ProtocolErrorProcessContextFactory();
        $connection = (new SqliteConnector($factory))->connect(new SqliteConfig(':memory:'));
        $closed = 0;
        $connection->onClose(static function () use (&$closed): void {
            ++$closed;
        });
        $factory->context->send(['id' => 1, 'operation' => 'unknown']);

        try {
            $connection->query('SELECT 1');
            self::fail('Expected the protocol error to fail the connection');
        } catch (SqliteConnectionException $exception) {
            self::assertSame("Unknown operation 'unknown'", $exception->getMessage());
        }
        delay(0);

        self::assertTrue($connection->isClosed());
        self::assertSame(1, $closed);
    }

    public function testUsesExplicitJournalMode(): void
    {
        $path = \sys_get_temp_dir() . '/amp-sqlite-' . \bin2hex(\random_bytes(8)) . '.sqlite';
        $config = (new SqliteConfig($path))->withJournalMode(SqliteJournalMode::Delete);
        $connection = (new SqliteConnector())->connect($config);

        try {
            self::assertSame(['journal_mode' => 'delete'], $connection->query('PRAGMA journal_mode')->fetchRow());
        } finally {
            $connection->close();
            @\unlink($path);
        }
    }
}

final class ProtocolErrorProcessContextFactory implements \Amp\Parallel\Context\ContextFactory
{
    public \Amp\Parallel\Context\ProcessContext $context;

    public function start(string|array $script, ?\Amp\Cancellation $cancellation = null): \Amp\Parallel\Context\Context
    {
        return $this->context = (new \Amp\Parallel\Context\ProcessContextFactory())->start($script, $cancellation);
    }
}
