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
use Fabpot\Amp\Sqlite\SqliteConnection;
use Fabpot\Amp\Sqlite\SqliteConnectionException;
use Fabpot\Amp\Sqlite\SqliteConnector;
use Fabpot\Amp\Sqlite\SqliteException;
use Fabpot\Amp\Sqlite\SqliteJournalMode;
use Fabpot\Amp\Sqlite\SqliteOpenMode;
use Fabpot\Amp\Sqlite\SqliteQueryError;
use Fabpot\Amp\Sqlite\SqliteSynchronousMode;
use Fabpot\Amp\Sqlite\SqliteTransactionMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use function Amp\async;
use function Amp\delay;

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
        yield 'NUL byte' => ["SELECT 1\0", 'SQL must not contain NUL bytes'];
    }

    public function testRejectsScriptWithNulByte(): void
    {
        $this->expectException(SqliteQueryError::class);
        $this->expectExceptionMessage('SQL must not contain NUL bytes');

        $this->connection->executeScript("CREATE TABLE events (name TEXT);\0; CREATE TABLE ignored (name TEXT)");
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

    public function testExecutesMultipleStatementsAsScript(): void
    {
        $this->connection->executeScript(<<<'SQL'
            CREATE TABLE events (name TEXT NOT NULL);
            INSERT INTO events VALUES ('created');
            INSERT INTO events VALUES ('updated');
            SQL);

        self::assertSame(
            [['name' => 'created'], ['name' => 'updated']],
            \iterator_to_array($this->connection->query('SELECT name FROM events ORDER BY rowid')),
        );
    }

    public function testFailedScriptRollsBackEveryStatement(): void
    {
        try {
            $this->connection->executeScript(<<<'SQL'
                CREATE TABLE events (name TEXT NOT NULL);
                INSERT INTO events VALUES ('created');
                INSERT INTO missing_table VALUES ('failed');
                INSERT INTO events VALUES ('skipped');
                SQL);
            self::fail('Expected the invalid statement to fail');
        } catch (SqliteQueryError) {
        }

        self::assertNull($this->connection->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'events'")->fetchRow());
    }

    public function testScriptUsesConfiguredTransactionMode(): void
    {
        $connection = (new SqliteConnector())->connect((new SqliteConfig(':memory:'))->withTransactionMode(SqliteTransactionMode::Immediate));

        try {
            $connection->executeScript('CREATE TABLE events (name TEXT NOT NULL); INSERT INTO events VALUES (\'created\');');
            self::assertSame(['name' => 'created'], $connection->query('SELECT name FROM events')->fetchRow());
        } finally {
            $connection->close();
        }
    }

    public function testScriptCannotControlItsTransaction(): void
    {
        $this->expectException(SqliteQueryError::class);
        $this->expectExceptionMessage('SQL scripts cannot contain transaction-control statements');

        $this->connection->executeScript('BEGIN; SELECT 1; COMMIT;');
    }

    public function testIgnoresEmptyStatements(): void
    {
        $this->connection->executeScript("; CREATE TABLE events (name TEXT NOT NULL);; INSERT INTO events VALUES ('created');;");

        self::assertSame(['name' => 'created'], $this->connection->query('; SELECT name FROM events;;')->fetchRow());
    }

    #[DataProvider('provideInsignificantPrefixes')]
    public function testScriptCannotHideTransactionControl(string $prefix): void
    {
        try {
            $this->connection->executeScript("CREATE TABLE events (name TEXT);{$prefix}COMMIT; INSERT INTO events VALUES ('committed')");
            self::fail('Expected the transaction-control statement to be rejected');
        } catch (SqliteQueryError $error) {
            self::assertSame('SQL scripts cannot contain transaction-control statements', $error->getMessage());
        }

        self::assertNull($this->connection->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'events'")->fetchRow());
    }

    public static function provideInsignificantPrefixes(): iterable
    {
        yield 'empty statement' => [' ;'];
        yield 'long comment block' => [\str_repeat("-- note\n", 4000)];
        yield 'carriage return inside line comment' => [" --x\ry\n"];
        yield 'vertical tab after whitespace' => [" \v"];
    }

    public function testScriptRunsStatementsAfterLongCommentBlock(): void
    {
        $this->connection->executeScript("CREATE TABLE events (name TEXT); INSERT INTO events VALUES ('first');" . \str_repeat("-- note\n", 4000) . "INSERT INTO events VALUES ('second')");

        self::assertSame(
            [['name' => 'first'], ['name' => 'second']],
            \iterator_to_array($this->connection->query('SELECT name FROM events ORDER BY rowid')),
        );
    }

    public function testRejectsSecondStatementAfterLongCommentBlock(): void
    {
        $this->connection->query('CREATE TABLE events (name TEXT)');
        $this->connection->query("INSERT INTO events VALUES ('kept')");

        try {
            $this->connection->query('SELECT 1;' . \str_repeat("-- note\n", 4000) . 'DELETE FROM events');
            self::fail('Expected the second statement to be rejected');
        } catch (SqliteQueryError $error) {
            self::assertSame('Only one SQL statement may be executed at a time', $error->getMessage());
        }

        self::assertSame(['name' => 'kept'], $this->connection->query('SELECT name FROM events')->fetchRow());
    }

    public function testLineCommentOnlyEndsAtNewline(): void
    {
        $this->connection->query('CREATE TABLE events (name TEXT)');

        self::assertNotNull($this->connection->query("--x\ry\nEXPLAIN INSERT INTO events VALUES ('explained')")->fetchRow());
        self::assertSame(0, $this->connection->query('SELECT COUNT(*) AS count FROM events')->fetchRow()['count']);
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

    public function testWorkerRejectsZeroLengthBlobReads(): void
    {
        $worker = $this->createWorker(batchSize: 1);

        try {
            $this->expectException(ProtocolError::class);
            $this->expectExceptionMessage("Protocol field 'length' must be a positive integer");

            $worker->handle(['operation' => 'readBlob', 'blob_id' => 1, 'length' => 0]);
        } finally {
            $worker->shutdown();
        }
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

    public function testConnectionCloseKeepsTheRowsOfAFullyBufferedResult(): void
    {
        $result = $this->connection->query('SELECT 1 AS value UNION ALL SELECT 2');

        $this->connection->close();

        self::assertSame([['value' => 1], ['value' => 2]], \iterator_to_array($result));
        self::assertTrue($result->isClosed());
    }

    public function testConnectionCloseKeepsTheRowsBufferedFromTheLastBatch(): void
    {
        $result = $this->connection->query('SELECT 1 AS value UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4');
        $result->fetchRow();
        $result->fetchRow();
        $result->fetchRow();

        $this->connection->close();

        self::assertSame(['value' => 4], $result->fetchRow());
        self::assertNull($result->fetchRow());
        self::assertTrue($result->isClosed());
    }

    public function testFetchRowAfterConnectionCloseFailsInsteadOfEndingResult(): void
    {
        $result = $this->connection->query('SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3');
        $result->fetchRow();

        $this->connection->close();

        $this->expectException(SqliteConnectionException::class);
        $result->fetchRow();
    }

    public function testIterationAfterConnectionCloseFailsInsteadOfEndingResult(): void
    {
        $result = $this->connection->query('SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3');

        $this->expectException(SqliteConnectionException::class);

        foreach ($result as $_) {
            $this->connection->close();
        }
    }

    public function testConnectionCloseInvalidatesResultAndReleasesWaitingOperation(): void
    {
        $result = $this->connection->query('SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3');
        $closed = 0;
        $result->onClose(static function () use (&$closed): void {
            ++$closed;
        });
        $future = async(fn () => $this->connection->query('SELECT 4'));
        delay(0);

        self::assertFalse($future->isComplete());
        $this->connection->close();
        delay(0);

        self::assertTrue($future->isComplete());
        self::assertTrue($result->isClosed());
        self::assertSame(1, $closed);

        $this->expectException(SqliteConnectionException::class);
        $future->await();
    }

    public function testFetchRowDuringCloseFailsWithoutInvalidatingTheConnection(): void
    {
        $this->connection->query('CREATE TABLE numbers (value INTEGER)');
        $this->connection->execute('INSERT INTO numbers VALUES (1), (2), (3), (4), (5)');
        $result = $this->connection->query('SELECT value FROM numbers');

        self::assertSame(['value' => 1], $result->fetchRow());
        self::assertSame(['value' => 2], $result->fetchRow());

        $close = async(static fn () => $result->close());
        delay(0); // the close request is now in flight

        try {
            $result->fetchRow();
            self::fail('Expected fetching from a closing result to fail');
        } catch (SqliteException $exception) {
            self::assertSame('The SQLite result is closed', $exception->getMessage());
        }

        $close->await();

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

        $this->expectException(SqliteException::class);

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

    public function testLastInsertIdIsSpecificToTheResult(): void
    {
        $this->connection->query('CREATE TABLE entries (id INTEGER PRIMARY KEY, value TEXT UNIQUE)');

        self::assertSame(1, $this->connection->query("INSERT INTO entries (value) VALUES ('first')")->getLastInsertId());
        self::assertNull($this->connection->query('SELECT * FROM entries')->getLastInsertId());
        self::assertNull($this->connection->query("UPDATE entries SET value = 'updated' WHERE id = 1")->getLastInsertId());
        self::assertNull($this->connection->query('CREATE TABLE other (id INTEGER PRIMARY KEY)')->getLastInsertId());
        self::assertSame(1, $this->connection->query('INSERT INTO other (id) VALUES (1)')->getLastInsertId());
        self::assertNull($this->connection->query("INSERT OR IGNORE INTO entries (id, value) VALUES (1, 'ignored')")->getLastInsertId());
        self::assertSame(3, $this->connection->query("INSERT INTO entries (value) VALUES ('second'), ('third')")->getLastInsertId());
        self::assertSame(5, $this->connection->query("INSERT INTO entries (value) VALUES (lower('Fourth')), (upper('fifth'))")->getLastInsertId());
        self::assertNull($this->connection->query("DELETE FROM entries WHERE value = 'second'")->getLastInsertId());
    }

    public function testLastInsertIdHandlesTriggersAndUpserts(): void
    {
        $this->connection->executeScript(<<<'SQL'
            CREATE TABLE entries (id INTEGER PRIMARY KEY, value TEXT UNIQUE);
            CREATE TABLE audit (id INTEGER PRIMARY KEY, value TEXT);
            CREATE TRIGGER audit_insert AFTER INSERT ON entries BEGIN
                INSERT INTO audit (value) VALUES (NEW.value);
            END;
            SQL);

        self::assertSame(10, $this->connection->query("INSERT INTO entries (id, value) VALUES (10, 'first')")->getLastInsertId());
        self::assertNull($this->connection->query(<<<'SQL'
            INSERT INTO entries (id, value) VALUES (11, 'first')
            ON CONFLICT(value) DO UPDATE SET value = excluded.value
            SQL)->getLastInsertId());
        self::assertSame(12, $this->connection->query(<<<'SQL'
            INSERT INTO entries (id, value) VALUES (12, 'second')
            ON CONFLICT(value) DO UPDATE SET value = excluded.value
            SQL)->getLastInsertId());
    }

    public function testLastInsertIdIsNullForNonRowIdInsertsAndRejectsReturning(): void
    {
        $this->connection->executeScript(<<<'SQL'
            CREATE TABLE entries (id INTEGER PRIMARY KEY, value TEXT);
            CREATE TABLE keyed (id TEXT PRIMARY KEY) WITHOUT ROWID;
            CREATE VIEW entry_view AS SELECT id, value FROM entries;
            CREATE TRIGGER entry_view_insert INSTEAD OF INSERT ON entry_view BEGIN
                INSERT INTO entries (id, value) VALUES (NEW.id, NEW.value);
            END;
            SQL);

        self::assertNull($this->connection->query("INSERT INTO keyed VALUES ('key')")->getLastInsertId());
        self::assertNull($this->connection->query("INSERT INTO entry_view VALUES (20, 'view')")->getLastInsertId());

        try {
            $this->connection->query("INSERT INTO entries (id, value) VALUES (21, 'returning') RETURNING id");
            self::fail('Expected the unsafe RETURNING statement to be rejected');
        } catch (SqliteQueryError $error) {
            self::assertSame(
                'Row-producing DML statements are not supported by the PHP SQLite3 extension',
                $error->getMessage(),
            );
        }
        self::assertNull($this->connection->query('SELECT id FROM entries WHERE id = 21')->fetchRow());
    }

    public function testPreparedInsertReportsOnlyIdsFromAffectedRows(): void
    {
        $this->connection->query('CREATE TABLE entries (id INTEGER PRIMARY KEY, value TEXT UNIQUE)');
        $statement = $this->connection->prepare('INSERT OR IGNORE INTO entries (id, value) VALUES (?, ?)');

        self::assertSame(5, $statement->execute([5, 'value'])->getLastInsertId());
        self::assertNull($statement->execute([6, 'value'])->getLastInsertId());
    }

    public function testPreparedInsertRefreshesMetadataAfterAnotherConnectionRecreatesTable(): void
    {
        $path = \sys_get_temp_dir() . '/amp-sqlite-' . \bin2hex(\random_bytes(8)) . '.sqlite';
        $connection = (new SqliteConnector())->connect(new SqliteConfig($path));
        $other = (new SqliteConnector())->connect(new SqliteConfig($path));

        try {
            $connection->query('CREATE TABLE entries (value TEXT)');
            $statement = $connection->prepare('INSERT INTO entries (value) VALUES (?)');
            self::assertSame(1, $statement->execute(['rowid'])->getLastInsertId());
            self::assertSame(2, $statement->execute(['cached'])->getLastInsertId());

            $other->executeScript('DROP TABLE entries; CREATE TABLE entries (value TEXT PRIMARY KEY) WITHOUT ROWID;');

            self::assertNull($statement->execute(['without rowid'])->getLastInsertId());
        } finally {
            $connection->close();
            $other->close();
            @\unlink($path);
            @\unlink($path . '-shm');
            @\unlink($path . '-wal');
        }
    }

    public function testPreparedInsertRefreshesMetadataAfterTemporaryTableShadowing(): void
    {
        $this->connection->query('CREATE TABLE entries (id INTEGER PRIMARY KEY, value TEXT)');
        $this->connection->query("INSERT INTO entries (value) VALUES ('main')");
        $statement = $this->connection->prepare('INSERT INTO entries (value) VALUES (?)');
        $this->connection->query('CREATE TEMP TABLE entries (value TEXT PRIMARY KEY) WITHOUT ROWID');

        $result = $statement->execute(['temporary']);

        self::assertNull($result->getLastInsertId());
        self::assertSame([['value' => 'temporary']], \iterator_to_array($this->connection->query('SELECT value FROM temp.entries')));
        self::assertSame([['value' => 'main']], \iterator_to_array($this->connection->query('SELECT value FROM main.entries')));
    }

    public function testRejectsRowProducingDmlAfterEmptyStatement(): void
    {
        $this->connection->query('CREATE TABLE entries (id INTEGER PRIMARY KEY)');

        $this->expectException(SqliteQueryError::class);
        $this->expectExceptionMessage('Row-producing DML statements are not supported by the PHP SQLite3 extension');

        $this->connection->query('; INSERT INTO entries DEFAULT VALUES RETURNING id');
    }

    public function testPreparedDmlBecomingRowProducingIsRejectedBeforeExecution(): void
    {
        $this->connection->query('CREATE TABLE entries (value TEXT)');
        $statement = $this->connection->prepare('INSERT INTO entries VALUES (?)');
        $this->connection->query('PRAGMA count_changes = ON')->close();

        try {
            $statement->execute(['duplicated']);
            self::fail('Expected row-producing DML to be rejected');
        } catch (SqliteQueryError $error) {
            self::assertSame(
                'Row-producing DML statements are not supported by the PHP SQLite3 extension',
                $error->getMessage(),
            );
        }

        self::assertSame(0, $this->connection->query('SELECT COUNT(*) AS count FROM entries')->fetchRow()['count']);
    }

    public function testDirectDmlBecomingRowProducingIsRejectedBeforeExecution(): void
    {
        $this->connection->query('CREATE TABLE entries (value TEXT)');
        $this->connection->query("INSERT INTO entries VALUES ('once')");
        $this->connection->query('PRAGMA count_changes = ON')->close();

        try {
            $this->connection->query("INSERT INTO entries VALUES ('once')");
            self::fail('Expected row-producing DML to be rejected');
        } catch (SqliteQueryError $error) {
            self::assertSame(
                'Row-producing DML statements are not supported by the PHP SQLite3 extension',
                $error->getMessage(),
            );
        }

        self::assertSame(1, $this->connection->query('SELECT COUNT(*) AS count FROM entries')->fetchRow()['count']);
    }

    public function testExplainOfDmlRemainsReadOnly(): void
    {
        $this->connection->query('CREATE TABLE entries (value TEXT)');

        self::assertNotNull($this->connection->query("EXPLAIN UPDATE entries SET value = 'updated'")->fetchRow());
        self::assertNotNull($this->connection->query("EXPLAIN QUERY PLAN DELETE FROM entries WHERE value = 'deleted'")->fetchRow());
        self::assertNotNull($this->connection->query("; EXPLAIN DELETE FROM entries WHERE value = 'deleted'")->fetchRow());
        self::assertSame(0, $this->connection->query('SELECT COUNT(*) AS count FROM entries')->fetchRow()['count']);
    }

    public function testTableDefinitionTextDoesNotHideUnambiguousInsertId(): void
    {
        $this->connection->executeScript(<<<'SQL'
            CREATE TABLE defaults_without_rowid (
                id INTEGER PRIMARY KEY,
                note TEXT DEFAULT 'WITHOUT ROWID'
            );
            CREATE TABLE quoted_without_rowid (
                id INTEGER PRIMARY KEY,
                "WITHOUT ROWID" TEXT
            );
            CREATE TABLE defaults_virtual (
                id INTEGER PRIMARY KEY,
                note TEXT DEFAULT 'CREATE VIRTUAL TABLE'
            );
            SQL);

        self::assertSame(1, $this->connection->query('INSERT INTO defaults_without_rowid DEFAULT VALUES')->getLastInsertId());
        self::assertSame(1, $this->connection->query('INSERT INTO quoted_without_rowid DEFAULT VALUES')->getLastInsertId());
        self::assertSame(1, $this->connection->query('INSERT INTO defaults_virtual DEFAULT VALUES')->getLastInsertId());
    }

    #[DataProvider('provideInsertTargetStorage')]
    public function testLastInsertIdDependsOnTableStorage(string $schema, string $insert, ?int $lastInsertId): void
    {
        $this->connection->executeScript($schema);

        self::assertSame($lastInsertId, $this->connection->query($insert)->getLastInsertId());
    }

    public static function provideInsertTargetStorage(): iterable
    {
        yield 'integer primary key' => ['CREATE TABLE entries (id INTEGER PRIMARY KEY)', 'INSERT INTO entries DEFAULT VALUES', 1];
        yield 'text primary key' => ['CREATE TABLE entries (code TEXT PRIMARY KEY)', "INSERT INTO entries VALUES ('a')", 1];
        yield 'composite primary key' => ['CREATE TABLE entries (a, b, PRIMARY KEY (a, b), UNIQUE (b))', 'INSERT INTO entries VALUES (1, 2)', 1];
        yield 'composite primary key without rowid' => ['CREATE TABLE entries (a, b, PRIMARY KEY (a, b), UNIQUE (b)) WITHOUT ROWID', 'INSERT INTO entries VALUES (1, 2)', null];
        yield 'temporary table without rowid' => ['CREATE TEMP TABLE entries (code PRIMARY KEY) WITHOUT ROWID', "INSERT INTO entries VALUES ('a')", null];
        yield 'attached table without rowid' => ["ATTACH DATABASE ':memory:' AS auxiliary; CREATE TABLE auxiliary.entries (code PRIMARY KEY) WITHOUT ROWID", "INSERT INTO auxiliary.entries VALUES ('a')", null];
        yield 'attached table with rowid' => ["ATTACH DATABASE ':memory:' AS auxiliary; CREATE TABLE auxiliary.entries (code PRIMARY KEY)", "INSERT INTO auxiliary.entries VALUES ('a')", 1];
    }

    public function testLastInsertIdIsNullForVirtualTables(): void
    {
        if ($this->connection->query("SELECT sqlite_compileoption_used('ENABLE_FTS5') AS enabled")->fetchRow() !== ['enabled' => 1]) {
            self::markTestSkipped('SQLite was built without FTS5');
        }

        $this->connection->query('CREATE VIRTUAL TABLE documents USING fts5(body)');

        self::assertNull($this->connection->query("INSERT INTO documents VALUES ('text')")->getLastInsertId());
    }

    #[DataProvider('provideVirtualTableTriggers')]
    public function testLastInsertIdIgnoresStatementsPreparedByVirtualTables(string $module, string $schema, string $insert): void
    {
        $this->withFreshDatabase($module, $schema, static function (SqliteConnection $connection) use ($insert): void {
            $statement = $connection->prepare($insert);

            self::assertSame(2, $statement->execute(['second'])->getLastInsertId());
            self::assertSame(3, $statement->execute(['third'])->getLastInsertId());
            self::assertSame(4, $connection->execute($insert, ['fourth'])->getLastInsertId());
        });
    }

    public static function provideVirtualTableTriggers(): iterable
    {
        yield 'FTS5' => ['FTS5', <<<'SQL'
            CREATE TABLE posts (id INTEGER PRIMARY KEY, body TEXT);
            CREATE VIRTUAL TABLE posts_fts USING fts5(body, content = 'posts', content_rowid = 'id');
            CREATE TRIGGER posts_insert AFTER INSERT ON posts BEGIN
                INSERT INTO posts_fts (rowid, body) VALUES (NEW.id, NEW.body);
            END;
            INSERT INTO posts (body) VALUES ('first');
            SQL, 'INSERT INTO posts (body) VALUES (?)'];
        yield 'R*Tree' => ['RTREE', <<<'SQL'
            CREATE TABLE shapes (id INTEGER PRIMARY KEY, name TEXT);
            CREATE VIRTUAL TABLE shapes_index USING rtree(id, min_x, max_x);
            CREATE TRIGGER shapes_insert AFTER INSERT ON shapes BEGIN
                INSERT INTO shapes_index VALUES (NEW.id, NEW.id, NEW.id);
            END;
            INSERT INTO shapes (name) VALUES ('first');
            SQL, 'INSERT INTO shapes (name) VALUES (?)'];
    }

    public function testLastInsertIdOfInsertSelectingFromFullTextIndex(): void
    {
        $schema = <<<'SQL'
            CREATE TABLE posts (id INTEGER PRIMARY KEY, body TEXT);
            CREATE VIRTUAL TABLE posts_fts USING fts5(body, content = 'posts', content_rowid = 'id');
            INSERT INTO posts (body) VALUES ('first');
            INSERT INTO posts_fts (rowid, body) VALUES (1, 'first');
            SQL;

        $this->withFreshDatabase('FTS5', $schema, static function (SqliteConnection $connection): void {
            self::assertSame(2, $connection->query("INSERT INTO posts (body) SELECT body FROM posts_fts WHERE posts_fts MATCH 'first'")->getLastInsertId());
        });
    }

    public function testUpdatingVirtualTableHasNoLastInsertId(): void
    {
        $schema = <<<'SQL'
            CREATE VIRTUAL TABLE shapes_index USING rtree(id, min_x, max_x);
            INSERT INTO shapes_index VALUES (1, 1, 1);
            SQL;

        $this->withFreshDatabase('RTREE', $schema, static function (SqliteConnection $connection): void {
            self::assertNull($connection->query('UPDATE shapes_index SET max_x = 5 WHERE id = 1')->getLastInsertId());
            self::assertNull($connection->query('INSERT INTO shapes_index VALUES (2, 2, 2)')->getLastInsertId());
        });
    }

    public function testLastInsertIdSupportsTemporaryAndAttachedTables(): void
    {
        $this->connection->executeScript(<<<'SQL'
            CREATE TEMP TABLE temporary_entries (id INTEGER PRIMARY KEY);
            ATTACH DATABASE ':memory:' AS auxiliary;
            CREATE TABLE auxiliary.entries (id INTEGER PRIMARY KEY);
            SQL);

        self::assertSame(1, $this->connection->query('INSERT INTO temporary_entries DEFAULT VALUES')->getLastInsertId());
        self::assertSame(1, $this->connection->query('INSERT INTO auxiliary.entries DEFAULT VALUES')->getLastInsertId());
    }

    public function testLastInsertIdFollowsTablesRecreatedWithAnotherStorage(): void
    {
        $this->connection->query('CREATE TABLE entries (code TEXT PRIMARY KEY)');
        self::assertSame(1, $this->connection->query("INSERT INTO entries VALUES ('a')")->getLastInsertId());

        $this->connection->query('DROP TABLE entries');
        $this->connection->query('CREATE TABLE entries (code TEXT PRIMARY KEY) WITHOUT ROWID');
        self::assertNull($this->connection->query("INSERT INTO entries VALUES ('b')")->getLastInsertId());

        $this->connection->query('DROP TABLE entries');
        $this->connection->query('CREATE TABLE entries (code TEXT PRIMARY KEY)');
        self::assertSame(1, $this->connection->query("INSERT INTO entries VALUES ('c')")->getLastInsertId());
    }

    public function testLastInsertIdFollowsTableRecreatedByAnotherConnection(): void
    {
        $path = \sys_get_temp_dir() . '/amp-sqlite-' . \bin2hex(\random_bytes(8)) . '.sqlite';
        $connection = (new SqliteConnector())->connect(new SqliteConfig($path));
        $other = (new SqliteConnector())->connect(new SqliteConfig($path));

        try {
            $connection->query('CREATE TABLE entries (value TEXT PRIMARY KEY)');
            self::assertSame(1, $connection->query("INSERT INTO entries VALUES ('rowid')")->getLastInsertId());

            $other->executeScript('DROP TABLE entries; CREATE TABLE entries (value TEXT PRIMARY KEY) WITHOUT ROWID;');

            self::assertNull($connection->query("INSERT INTO entries VALUES ('without rowid')")->getLastInsertId());
        } finally {
            $connection->close();
            $other->close();
            @\unlink($path);
            @\unlink($path . '-shm');
            @\unlink($path . '-wal');
        }
    }

    #[DataProvider('provideReattachments')]
    public function testLastInsertIdFollowsReattachedDatabase(string $reattachment): void
    {
        $prefix = \sys_get_temp_dir() . '/amp-sqlite-' . \bin2hex(\random_bytes(8));
        // Both files have the same schema version, so only the reattachment tells their tables apart
        foreach (['rowid' => '', 'without_rowid' => ' WITHOUT ROWID'] as $name => $storage) {
            $database = new \SQLite3("{$prefix}-{$name}.sqlite");
            $database->exec("CREATE TABLE entries (code TEXT PRIMARY KEY){$storage}");
            $database->close();
        }

        try {
            $this->connection->query("ATTACH DATABASE '{$prefix}-rowid.sqlite' AS auxiliary");
            $detach = 'DETACH DATABASE auxiliary';
            $attach = "ATTACH DATABASE '{$prefix}-without_rowid.sqlite' AS auxiliary";
            $statements = $reattachment === 'prepared' ? [$this->connection->prepare($detach), $this->connection->prepare($attach)] : [];

            self::assertSame(1, $this->connection->query("INSERT INTO auxiliary.entries VALUES ('a')")->getLastInsertId());

            if ($reattachment === 'script') {
                $this->connection->executeScript("{$detach}; {$attach};");
            } elseif ($reattachment === 'prepared') {
                $statements[0]->execute();
                $statements[1]->execute();
            } else {
                $this->connection->query($detach);
                $this->connection->query($attach);
            }

            self::assertNull($this->connection->query("INSERT INTO auxiliary.entries VALUES ('b')")->getLastInsertId());
        } finally {
            $this->connection->close();
            @\unlink("{$prefix}-rowid.sqlite");
            @\unlink("{$prefix}-without_rowid.sqlite");
        }
    }

    public static function provideReattachments(): iterable
    {
        yield 'queries' => ['query'];
        yield 'prepared statements' => ['prepared'];
        yield 'script' => ['script'];
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

    public function testCommandRowCountExcludesTriggerChanges(): void
    {
        $this->connection->query('CREATE TABLE source (value INTEGER)');
        $this->connection->query('CREATE TABLE target (value INTEGER)');
        $this->connection->query('CREATE TRIGGER copy AFTER INSERT ON source BEGIN INSERT INTO target VALUES (NEW.value); INSERT INTO target VALUES (NEW.value); END');

        $result = $this->connection->query('INSERT INTO source VALUES (1), (2)');

        self::assertSame(2, $result->getRowCount());
    }

    public function testCommandRowCountExcludesVirtualTableInternalChanges(): void
    {
        $this->connection->executeScript(<<<'SQL'
            CREATE TABLE posts (id INTEGER PRIMARY KEY, body TEXT);
            CREATE VIRTUAL TABLE posts_fts USING fts5(body, content='posts', content_rowid='id');
            CREATE TRIGGER posts_ai AFTER INSERT ON posts BEGIN INSERT INTO posts_fts (rowid, body) VALUES (NEW.id, NEW.body); END;
            SQL);

        self::assertSame(1, $this->connection->execute('INSERT INTO posts (body) VALUES (?)', ['hello'])->getRowCount());
        self::assertSame(1, $this->connection->query("INSERT INTO posts_fts (rowid, body) VALUES (99, 'direct')")->getRowCount());
        self::assertSame(0, $this->connection->query('CREATE VIRTUAL TABLE other_fts USING fts5(body)')->getRowCount());
    }

    public function testCommandRowCountIsZeroForStatementsAfterDml(): void
    {
        $this->connection->query('CREATE TABLE entries (value INTEGER)');
        $this->connection->query('INSERT INTO entries VALUES (1), (2), (3)');

        self::assertSame(0, $this->connection->query('CREATE TABLE copy AS SELECT * FROM entries')->getRowCount());
        self::assertSame(0, $this->connection->query('ANALYZE')->getRowCount());
        self::assertSame(0, $this->connection->query('PRAGMA user_version = 3')->getRowCount());
    }

    public function testCommandRowCountCountsDmlPrefixedByCommentsOrCommonTableExpressions(): void
    {
        $this->connection->query('CREATE TABLE entries (value INTEGER)');
        $this->connection->query('INSERT INTO entries VALUES (1), (2), (3)');

        self::assertSame(2, $this->connection->query('/* cleanup */ WITH old AS (SELECT 1) DELETE FROM entries WHERE value > 1')->getRowCount());
        self::assertSame(0, $this->connection->query('CREATE TABLE other (value INTEGER)')->getRowCount());

        $update = $this->connection->prepare('UPDATE entries SET value = value + 1');
        self::assertSame(1, $update->execute()->getRowCount());
        $this->connection->query('CREATE TABLE another (value INTEGER)');
        self::assertSame(1, $update->execute()->getRowCount());
        self::assertSame(0, $this->connection->query("DELETE FROM entries WHERE value < 0")->getRowCount());
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
            self::assertNull($error->getResultCode());
            self::assertNull($error->getExtendedResultCode());
        }
    }

    /**
     * @param \Closure(SqliteConnection):void $test
     */
    private function withFreshDatabase(string $module, string $schema, \Closure $test): void
    {
        if ($this->connection->query("SELECT sqlite_compileoption_used('ENABLE_{$module}') AS enabled")->fetchRow() !== ['enabled' => 1]) {
            self::markTestSkipped("SQLite was built without {$module}");
        }

        $path = \sys_get_temp_dir() . '/amp-sqlite-' . \bin2hex(\random_bytes(8)) . '.sqlite';
        $database = new \SQLite3($path);
        $database->exec($schema);
        $database->close();
        $connection = (new SqliteConnector())->connect(new SqliteConfig($path));

        try {
            $test($connection);
        } finally {
            $connection->close();
            @\unlink($path);
            @\unlink($path . '-shm');
            @\unlink($path . '-wal');
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
