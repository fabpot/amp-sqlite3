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

use Fabpot\Amp\Sqlite\Internal\ProtocolError;
use Fabpot\Amp\Sqlite\Internal\WorkerProcess;
use Fabpot\Amp\Sqlite\SqliteBlob;
use Fabpot\Amp\Sqlite\SqliteConfig;
use Fabpot\Amp\Sqlite\SqliteConnector;
use Fabpot\Amp\Sqlite\SqliteJournalMode;
use Fabpot\Amp\Sqlite\SqliteOpenMode;
use Fabpot\Amp\Sqlite\SqliteQueryError;
use Fabpot\Amp\Sqlite\SqliteSynchronousMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use function Amp\async;

final class SqliteQueryTest extends TestCase
{
    /** @var \Fabpot\Amp\Sqlite\SqliteConnection */
    private $connection;

    protected function setUp(): void
    {
        $this->connection = (new SqliteConnector())->connect((new SqliteConfig(':memory:'))->withBatchSize(2));
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    #[DataProvider('provideInvalidSql')]
    public function testRejectsInvalidSql(string $sql, string $message): void
    {
        $this->expectException(SqliteQueryError::class);
        $this->expectExceptionMessage($message);

        $this->connection->query($sql);
    }

    public static function provideInvalidSql(): iterable
    {
        yield 'empty' => ['', 'SQL must contain an executable statement'];
        yield 'whitespace' => [" \n\t", 'SQL must contain an executable statement'];
        yield 'comment' => ['/* only a comment */ -- still a comment', 'SQL must contain an executable statement'];
        yield 'semicolon' => [';;;', 'SQL must contain an executable statement'];
        yield 'second statement' => ['SELECT 1; SELECT 2', 'Only one SQL statement may be executed at a time'];
    }

    public function testAllowsTrailingCommentsAndSemicolonsInsideCompoundStatement(): void
    {
        $this->connection->query('CREATE TABLE events (name TEXT)');
        $this->connection->query(<<<'SQL'
            CREATE TRIGGER event_name AFTER INSERT ON events
            BEGIN
                UPDATE events SET name = upper(name) WHERE rowid = NEW.rowid;
            END; -- trailing comment
            SQL);

        $this->connection->execute('INSERT INTO events VALUES (?)', ['created']);

        self::assertSame(['name' => 'CREATED'], $this->connection->query('SELECT name FROM events')->fetchRow());
    }

    public function testAllowsUnterminatedTrailingBlockComment(): void
    {
        self::assertSame([1 => 1], $this->connection->query('SELECT 1; /* trailing')->fetchRow());
    }

    public function testStreamsRowsAcrossBatches(): void
    {
        $this->connection->query('CREATE TABLE numbers (value INTEGER)');
        foreach (\range(1, 5) as $value) {
            $this->connection->execute('INSERT INTO numbers VALUES (?)', [$value]);
        }

        $result = $this->connection->query('SELECT value FROM numbers ORDER BY value');

        self::assertSame(['value' => 1], $result->fetchRow());
        self::assertSame(
            [['value' => 2], ['value' => 3], ['value' => 4], ['value' => 5]],
            \iterator_to_array($result),
        );
        self::assertTrue($result->isClosed());
    }

    public function testResultOwnsConnectionUntilExhausted(): void
    {
        $result = $this->connection->query('SELECT 1 AS value UNION ALL SELECT 2');
        $future = async(fn () => $this->connection->query('SELECT 3 AS value')->fetchRow());

        self::assertFalse($future->isComplete());
        self::assertSame([['value' => 1], ['value' => 2]], \iterator_to_array($result));
        self::assertSame(['value' => 3], $future->await());
    }

    public function testFetchErrorReleasesConnection(): void
    {
        $this->connection->query('CREATE TABLE fetch_errors (value INTEGER)');
        foreach (\range(1, 5) as $value) {
            $this->connection->execute('INSERT INTO fetch_errors VALUES (?)', [$value]);
        }
        $result = $this->connection->query(<<<'SQL'
            SELECT CASE value
                WHEN 5 THEN json_extract('invalid', '$')
                ELSE value
            END AS value
            FROM fetch_errors
            SQL);

        self::assertSame(['value' => 1], $result->fetchRow());
        self::assertSame(['value' => 2], $result->fetchRow());

        try {
            $result->fetchRow();
            self::fail('Expected the later batch to fail');
        } catch (SqliteQueryError) {
        }

        self::assertTrue($result->isClosed());
        self::assertSame(['answer' => 42], $this->connection->query('SELECT 42 AS answer')->fetchRow());
    }

    public function testInitialFetchErrorDoesNotLeaveAStaleResult(): void
    {
        $worker = $this->createWorker(batchSize: 3);

        try {
            $worker->handle(['operation' => 'execute', 'sql' => 'CREATE TABLE fetch_errors (value INTEGER)', 'params' => [], 'bind_parameters' => false]);
            $worker->handle(['operation' => 'execute', 'sql' => 'INSERT INTO fetch_errors VALUES (1), (2), (3)', 'params' => [], 'bind_parameters' => false]);

            try {
                $worker->handle([
                    'operation' => 'execute',
                    'sql' => "SELECT CASE value WHEN 3 THEN json_extract('invalid', '$') ELSE value END FROM fetch_errors",
                    'params' => [],
                    'bind_parameters' => false,
                ]);
                self::fail('Expected the initial batch to fail');
            } catch (\SQLite3Exception) {
            }

            $this->expectException(ProtocolError::class);
            $this->expectExceptionMessage("Unknown result ID '1'");

            $worker->handle(['operation' => 'fetch', 'result_id' => 1]);
        } finally {
            $worker->shutdown();
        }
    }

    public function testClosingResultReleasesConnection(): void
    {
        $this->connection->query('CREATE TABLE numbers (value INTEGER)');
        $this->connection->execute('INSERT INTO numbers VALUES (1), (2), (3)');
        $result = $this->connection->query('SELECT value FROM numbers');

        self::assertSame(['value' => 1], $result->fetchRow());
        $result->close();

        self::assertSame(['answer' => 42], $this->connection->query('SELECT 42 AS answer')->fetchRow());
    }

    public function testFetchRowReturnsNullAfterExhaustion(): void
    {
        $result = $this->connection->query('SELECT 1 AS value');

        self::assertSame(['value' => 1], $result->fetchRow());
        self::assertNull($result->fetchRow());
        self::assertNull($this->connection->query('SELECT 1 WHERE 0')->fetchRow());
        self::assertNull($this->connection->query('CREATE TABLE empty_result (value INTEGER)')->fetchRow());
    }

    public function testFetchAfterExplicitCloseFails(): void
    {
        $result = $this->connection->query('SELECT 1');
        $result->close();

        $this->expectException(\Error::class);

        $result->fetchRow();
    }

    public function testSupportsNativeSQLiteParameters(): void
    {
        self::assertSame(
            ['first' => 'one', 'second' => 2],
            $this->connection->execute('SELECT ? AS first, ? AS second', ['one', 2])->fetchRow(),
        );
        self::assertSame(
            ['value' => 'same', 'again' => 'same'],
            $this->connection->execute('SELECT :value AS value, :value AS again', [':value' => 'same'])->fetchRow(),
        );
        self::assertSame(
            ['value' => 'second'],
            $this->connection->execute('SELECT :value AS value', [':value' => 'first', 'value' => 'second'])->fetchRow(),
        );
        self::assertSame(
            ['numbered' => 'one', 'colon' => 'two', 'at' => 'three', 'dollar' => 'four'],
            $this->connection->execute(
                'SELECT ?1 AS numbered, :colon AS colon, @at AS at, $dollar AS dollar',
                [0 => 'one', ':colon' => 'two', '@at' => 'three', 3 => 'four'],
            )->fetchRow(),
        );
    }

    public function testUnboundParametersEvaluateAsNull(): void
    {
        self::assertSame(
            ['first' => null, 'second' => 'value'],
            $this->connection->execute('SELECT ?1 AS first, ?2 AS second', [1 => 'value'])->fetchRow(),
        );
    }

    #[DataProvider('provideInvalidParameters')]
    public function testRejectsInvalidParameters(string $sql, array $params): void
    {
        $this->expectException(SqliteQueryError::class);

        $this->connection->execute($sql, $params);
    }

    public static function provideInvalidParameters(): iterable
    {
        yield 'extra positional' => ['SELECT 1', [1]];
        yield 'invalid position' => ['SELECT ?', [1 => 'value']];
        yield 'extra named' => ['SELECT :value', [':value' => 1, ':extra' => 2]];
        yield 'invalid named parameter' => ['SELECT :value', ['missing' => 1]];
    }

    public function testQueryRejectsPlaceholders(): void
    {
        $this->expectException(SqliteQueryError::class);

        $this->connection->query('SELECT ?');
    }

    public function testRejectsUnsupportedParameterValueWithoutLeakingIt(): void
    {
        try {
            $this->connection->execute('SELECT :password', [':password' => new \stdClass()]);
            self::fail('Expected a TypeError');
        } catch (\TypeError $error) {
            self::assertStringNotContainsString('secret', $error->getMessage());
        }
    }

    public function testRedactsParameterValuesFromExceptionTraces(): void
    {
        try {
            $this->connection->execute('SELECT 1', ['s3cr3t-password']);
            self::fail('Expected the invalid parameter to fail');
        } catch (SqliteQueryError $error) {
            self::assertStringNotContainsString('s3cr3t', \var_export($error->getTrace(), true));
        }
    }

    public function testPreservesSQLiteTypes(): void
    {
        $row = $this->connection->execute(
            'SELECT ? AS null_value, ? AS bool_value, ? AS int_value, ? AS float_value, ? AS text_value, ? AS blob_value',
            [null, true, 42, 1.5, 'text', new SqliteBlob("\0bytes")],
        )->fetchRow();

        self::assertNull($row['null_value']);
        self::assertSame(1, $row['bool_value']);
        self::assertSame(42, $row['int_value']);
        self::assertSame(1.5, $row['float_value']);
        self::assertSame('text', $row['text_value']);
        self::assertInstanceOf(SqliteBlob::class, $row['blob_value']);
        self::assertSame("\0bytes", $row['blob_value']->getBytes());
    }

    public function testReportsCommandMetadata(): void
    {
        $this->connection->query('CREATE TABLE entries (id INTEGER PRIMARY KEY, value TEXT)')->close();
        $insert = $this->connection->execute('INSERT INTO entries (value) VALUES (?)', ['value']);
        $ddl = $this->connection->query('CREATE TABLE other (id INTEGER)');

        self::assertSame(1, $insert->getRowCount());
        self::assertSame(1, $insert->getLastInsertId());
        self::assertNull($insert->getColumnCount());
        self::assertNull($insert->getColumnNames());
        self::assertSame(0, $ddl->getRowCount());
    }

    public function testReportsColumnNames(): void
    {
        $result = $this->connection->query('SELECT 1 AS id, 2 AS value, 3 AS "complex name"');

        self::assertSame(['id', 'value', 'complex name'], $result->getColumnNames());
        self::assertSame(3, $result->getColumnCount());
    }

    public function testReportsColumnNamesForEmptyResults(): void
    {
        $this->connection->query('CREATE TABLE entries (id INTEGER, value TEXT)');

        $result = $this->connection->query('SELECT id, value FROM entries');

        self::assertSame(['id', 'value'], $result->getColumnNames());
        self::assertNull($result->fetchRow());
    }

    public function testCommandRowCountIncludesTriggerChanges(): void
    {
        $this->connection->query('CREATE TABLE source (value INTEGER)');
        $this->connection->query('CREATE TABLE target (value INTEGER)');
        $this->connection->query('CREATE TRIGGER copy AFTER INSERT ON source BEGIN INSERT INTO target VALUES (NEW.value); END');

        $result = $this->connection->query('INSERT INTO source VALUES (1)');

        self::assertSame(2, $result->getRowCount());
    }

    public function testReportsPrimaryAndExtendedResultCodes(): void
    {
        $this->connection->query('CREATE TABLE unique_values (value INTEGER UNIQUE)');
        $this->connection->execute('INSERT INTO unique_values VALUES (1)');

        try {
            $this->connection->execute('INSERT INTO unique_values VALUES (1)');
            self::fail('Expected the duplicate value to fail');
        } catch (SqliteQueryError $error) {
            self::assertSame(19, $error->getResultCode());
            self::assertSame(2067, $error->getExtendedResultCode());
        }
    }

    public function testLibraryErrorsDoNotReportStaleResultCodes(): void
    {
        $this->connection->query('CREATE TABLE unique_values (value INTEGER UNIQUE)');
        $this->connection->execute('INSERT INTO unique_values VALUES (1)');

        try {
            $this->connection->execute('INSERT INTO unique_values VALUES (1)');
            self::fail('Expected the duplicate value to fail');
        } catch (SqliteQueryError) {
        }

        try {
            $this->connection->execute('SELECT :value', ['missing' => 1]);
            self::fail('Expected the invalid parameter to fail');
        } catch (SqliteQueryError $error) {
            self::assertSame(0, $error->getResultCode());
            self::assertSame(0, $error->getExtendedResultCode());
        }
    }

    private function createWorker(int $batchSize): WorkerProcess
    {
        return new WorkerProcess([
            'path' => ':memory:',
            'open_mode' => SqliteOpenMode::ReadWriteCreate->name,
            'journal_mode' => SqliteJournalMode::Automatic->value,
            'synchronous_mode' => SqliteSynchronousMode::Automatic->value,
            'foreign_keys' => true,
            'busy_timeout' => 5_000,
            'batch_size' => $batchSize,
            'trusted_schema' => false,
            'extended_result_codes' => true,
            'pragmas' => [],
            'functions' => [],
            'aggregates' => [],
            'collations' => [],
        ]);
    }
}
