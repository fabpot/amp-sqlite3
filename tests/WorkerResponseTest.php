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

use Fabpot\Amp\Sqlite\Internal\WorkerFailure;
use Fabpot\Amp\Sqlite\Internal\WorkerResponse;
use Fabpot\Amp\Sqlite\SqliteBlob;
use Fabpot\Amp\Sqlite\SqliteException;
use Fabpot\Amp\Sqlite\SqliteQueryError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkerResponseTest extends TestCase
{
    public function testUnwrapsValue(): void
    {
        self::assertSame(['statement_id' => 1], WorkerResponse::unwrap(['id' => 3, 'value' => ['statement_id' => 1]], 3, 'SELECT 1'));
    }

    public function testUnwrapsQueryErrorWithTheFailedSql(): void
    {
        try {
            WorkerResponse::unwrap(['id' => 1, 'query_error' => ['message' => 'no such table: t', 'code' => 1, 'extended_code' => 1]], 1, 'SELECT * FROM t');
            self::fail('Expected the query error to be thrown');
        } catch (SqliteQueryError $error) {
            self::assertSame('no such table: t', $error->getMessage());
            self::assertSame('SELECT * FROM t', $error->getQuery());
            self::assertSame(1, $error->getResultCode());
        }
    }

    public function testUnwrapsOperationError(): void
    {
        $this->expectException(SqliteException::class);
        $this->expectExceptionMessage('Could not read from SQLite BLOB');
        $this->expectExceptionCode(0);

        WorkerResponse::unwrap(['id' => 1, 'operation_error' => ['message' => 'Could not read from SQLite BLOB', 'code' => null]], 1, '');
    }

    public function testProtocolErrorIsAWorkerFailure(): void
    {
        $this->expectException(WorkerFailure::class);
        $this->expectExceptionMessage("Unknown operation 'unknown'");

        WorkerResponse::unwrap(['id' => 1, 'protocol_error' => ['message' => "Unknown operation 'unknown'"]], 1, '');
    }

    #[DataProvider('provideInvalidEnvelopes')]
    public function testRejectsInvalidEnvelope(mixed $response): void
    {
        $this->expectException(WorkerFailure::class);
        $this->expectExceptionMessage('Received an invalid response from the SQLite child process');

        WorkerResponse::unwrap($response, 1, '');
    }

    public static function provideInvalidEnvelopes(): iterable
    {
        yield 'not an array' => [null];
        yield 'mismatched id' => [['id' => 2, 'value' => null]];
        yield 'extra key' => [['id' => 1, 'value' => null, 'more' => true]];
        yield 'missing value' => [['id' => 1, 'result' => null]];
        yield 'malformed query error' => [['id' => 1, 'query_error' => ['message' => 'failed']]];
        yield 'malformed operation error' => [['id' => 1, 'operation_error' => ['message' => 'failed', 'code' => '1']]];
        yield 'malformed protocol error' => [['id' => 1, 'protocol_error' => []]];
    }

    public function testAcceptsRowAndCommandResults(): void
    {
        $rows = ['result_id' => 1, 'rows' => [['a' => new SqliteBlob('x')]], 'exhausted' => false, 'row_count' => null, 'column_count' => 1, 'column_names' => ['a'], 'last_insert_id' => null];
        $command = ['result_id' => null, 'rows' => [], 'exhausted' => true, 'row_count' => 2, 'column_count' => null, 'column_names' => null, 'last_insert_id' => 7];

        self::assertSame($rows, WorkerResponse::result($rows));
        self::assertSame($command, WorkerResponse::result($command));
    }

    #[DataProvider('provideInvalidResults')]
    public function testRejectsInvalidResult(mixed $value): void
    {
        $this->expectException(WorkerFailure::class);

        WorkerResponse::result($value);
    }

    public static function provideInvalidResults(): iterable
    {
        $rows = ['result_id' => 1, 'rows' => [], 'exhausted' => true, 'row_count' => null, 'column_count' => 1, 'column_names' => ['a'], 'last_insert_id' => null];
        $command = ['result_id' => null, 'rows' => [], 'exhausted' => true, 'row_count' => 0, 'column_count' => null, 'column_names' => null, 'last_insert_id' => null];

        yield 'missing key' => [\array_slice($rows, 1)];
        yield 'non-list rows' => [['rows' => [1 => ['a' => 1]]] + $rows];
        yield 'invalid row value' => [['rows' => [['a' => [1]]]] + $rows];
        yield 'column count mismatch' => [['column_count' => 2] + $rows];
        yield 'row result with row count' => [['row_count' => 1] + $rows];
        yield 'non-positive result ID' => [['result_id' => 0] + $rows];
        yield 'command with rows' => [['rows' => [['a' => 1]]] + $command];
        yield 'unexhausted command' => [['exhausted' => false] + $command];
        yield 'negative row count' => [['row_count' => -1] + $command];
        yield 'command with columns' => [['column_names' => []] + $command];
    }

    #[DataProvider('provideInvalidPayloads')]
    public function testRejectsInvalidPayload(string $type, mixed $value): void
    {
        $this->expectException(WorkerFailure::class);

        WorkerResponse::{$type}($value);
    }

    public static function provideInvalidPayloads(): iterable
    {
        yield 'void with value' => ['void', false];
        yield 'empty unexhausted batch' => ['batch', ['rows' => [], 'exhausted' => false]];
        yield 'negative BLOB length' => ['openBlob', ['blob_id' => 1, 'length' => -1]];
        yield 'non-positive statement ID' => ['statementId', ['statement_id' => 0]];
        yield 'non-string BLOB bytes' => ['blobBytes', ['bytes' => 1]];
    }
}
