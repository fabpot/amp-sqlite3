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

namespace Fabpot\Amp\Sqlite\Internal;

use Fabpot\Amp\Sqlite\SqliteBlob;
use Fabpot\Amp\Sqlite\SqliteBlobMode;
use Fabpot\Amp\Sqlite\SqliteJournalMode;
use Fabpot\Amp\Sqlite\SqliteOpenMode;
use Fabpot\Amp\Sqlite\SqliteSynchronousMode;

/**
 * Runs inside the child process and executes protocol operations against the native SQLite3 connection.
 */
final class WorkerProcess
{
    private readonly \SQLite3 $database;
    private readonly int $batchSize;
    private bool $closed = false;
    private int $nextBlobId = 1;
    private int $nextResultId = 1;
    private int $nextStatementId = 1;

    /** @var array<int, resource> */
    private array $blobs = [];

    /** @var array<int, \SQLite3Stmt> */
    private array $statements = [];

    /** @var array<int, array{result: \SQLite3Result, statement: \SQLite3Stmt, statement_id: int|null, pending: array<string, mixed>|null}> */
    private array $results = [];

    /**
     * @param array{
     *     path: string,
     *     open_mode: string,
     *     journal_mode: string,
     *     synchronous_mode: string,
     *     foreign_keys: bool,
     *     busy_timeout: int,
     *     batch_size: positive-int,
     *     trusted_schema: bool,
     *     extended_result_codes: bool,
     *     pragmas: array<string, null|bool|int|float|string>,
     *     functions: array<string, array{callback: string, arg_count: int, deterministic: bool}>,
     *     aggregates: array<string, array{step: string, final: string, arg_count: int}>,
     *     collations: array<string, string>
     * } $open
     */
    public function __construct(array $open)
    {
        if (!\extension_loaded('sqlite3')) {
            throw new \RuntimeException('The sqlite3 extension is not loaded');
        }

        $version = \SQLite3::version()['versionString'];
        if (\version_compare($version, '3.31.0', '<')) {
            throw new \RuntimeException("SQLite 3.31.0 or newer is required, {$version} is installed");
        }

        $flags = match ($open['open_mode']) {
            SqliteOpenMode::ReadOnly->name => SQLITE3_OPEN_READONLY,
            SqliteOpenMode::ReadWrite->name => SQLITE3_OPEN_READWRITE,
            SqliteOpenMode::ReadWriteCreate->name => SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE,
        };

        $this->database = new \SQLite3($open['path'], $flags);
        $this->database->enableExceptions(true);
        $this->database->enableExtendedResultCodes($open['extended_result_codes']);
        $this->database->busyTimeout($open['busy_timeout']);
        $this->batchSize = $open['batch_size'];

        $this->applyPragma('trusted_schema', $open['trusted_schema']);
        $this->applyPragma('foreign_keys', $open['foreign_keys']);
        $this->applyJournalMode($open);
        $this->applySynchronousMode($open);

        foreach ($open['pragmas'] as $name => $value) {
            $this->applyPragma($name, $value);
        }

        $this->registerFunctions($open['functions']);
        $this->registerAggregates($open['aggregates']);
        $this->registerCollations($open['collations']);
    }

    /**
     * @param array<string, mixed> $request
     */
    public function handle(array $request): mixed
    {
        return match ($request['operation']) {
            'close' => $this->close(),
            'backup' => $this->backup($request['path'], $request['database']),
            'restore' => $this->restore($request['path'], $request['database']),
            'openBlob' => $this->openBlob($request),
            'readBlob' => $this->readBlob($request['blob_id'], $request['length']),
            'writeBlob' => $this->writeBlob($request['blob_id'], $request['bytes']),
            'closeBlob' => $this->closeBlob($request['blob_id']),
            'prepare' => $this->prepare($request['sql']),
            'closeStatement' => $this->closeStatement($request['statement_id']),
            'fetch' => $this->fetch($request['result_id']),
            'closeResult' => $this->closeResult($request['result_id']),
            'execute', 'executeStatement' => $this->execute($request),
            default => throw new ProtocolError("Unknown operation '{$request['operation']}'"),
        };
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function getLastExtendedErrorCode(): int
    {
        return $this->database->lastExtendedErrorCode();
    }

    public function shutdown(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        foreach ($this->blobs as $blob) {
            \fclose($blob);
        }
        foreach ($this->results as $resource) {
            $this->closeNativeResult($resource);
        }
        foreach ($this->statements as $statement) {
            $statement->close();
        }
        $this->database->close();
    }

    private function close(): null
    {
        $this->shutdown();

        return null;
    }

    private function backup(string $path, string $database): null
    {
        $destination = new \SQLite3($path, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        $destination->enableExceptions(true);
        try {
            if (!$this->database->backup($destination, $database)) {
                throw new \RuntimeException('Could not back up the SQLite database');
            }
        } finally {
            $destination->close();
        }

        return null;
    }

    private function restore(string $path, string $database): null
    {
        $source = new \SQLite3($path, SQLITE3_OPEN_READONLY);
        $source->enableExceptions(true);
        try {
            if (!$source->backup($this->database, 'main', $database)) {
                throw new \RuntimeException('Could not restore the SQLite database');
            }
        } finally {
            $source->close();
        }

        return null;
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array{blob_id: int, length: int}
     */
    private function openBlob(array $request): array
    {
        $flags = $request['mode'] === SqliteBlobMode::ReadWrite->name
            ? SQLITE3_OPEN_READWRITE
            : SQLITE3_OPEN_READONLY;
        $blob = $this->database->openBlob(
            $request['table'],
            $request['column'],
            $request['row_id'],
            $request['database'],
            $flags,
        );
        $blobId = $this->nextBlobId++;
        $this->blobs[$blobId] = $blob;
        $stat = \fstat($blob);
        if ($stat === false) {
            throw new \RuntimeException('Could not determine SQLite BLOB length');
        }

        return ['blob_id' => $blobId, 'length' => $stat['size']];
    }

    /**
     * @return array{bytes: string}
     */
    private function readBlob(int $blobId, int $length): array
    {
        if (!isset($this->blobs[$blobId])) {
            throw new ProtocolError("Unknown BLOB ID '{$blobId}'");
        }

        $bytes = \fread($this->blobs[$blobId], $length);
        if ($bytes === false) {
            throw new \RuntimeException('Could not read from SQLite BLOB');
        }

        return ['bytes' => $bytes];
    }

    private function writeBlob(int $blobId, string $bytes): null
    {
        if (!isset($this->blobs[$blobId])) {
            throw new ProtocolError("Unknown BLOB ID '{$blobId}'");
        }

        if (\fwrite($this->blobs[$blobId], $bytes) !== \strlen($bytes)) {
            throw new \RuntimeException('Could not write to SQLite BLOB');
        }

        return null;
    }

    private function closeBlob(int $blobId): null
    {
        if ($blobId < 1 || $blobId >= $this->nextBlobId) {
            throw new ProtocolError("Unknown BLOB ID '{$blobId}'");
        }

        if (isset($this->blobs[$blobId])) {
            \fclose($this->blobs[$blobId]);
            unset($this->blobs[$blobId]);
        }

        return null;
    }

    /**
     * @return array{statement_id: int}
     */
    private function prepare(string $sql): array
    {
        $statement = $this->prepareSingleStatement($sql, 'Only one SQL statement may be prepared at a time');
        $statementId = $this->nextStatementId++;
        $this->statements[$statementId] = $statement;

        return ['statement_id' => $statementId];
    }

    private function closeStatement(int $statementId): null
    {
        if ($statementId < 1 || $statementId >= $this->nextStatementId) {
            throw new ProtocolError("Unknown statement ID '{$statementId}'");
        }

        if (isset($this->statements[$statementId])) {
            foreach ($this->results as $resultId => $resource) {
                if ($resource['statement_id'] === $statementId) {
                    $this->closeNativeResult($resource);
                    unset($this->results[$resultId]);
                }
            }
            $this->statements[$statementId]->close();
            unset($this->statements[$statementId]);
        }

        return null;
    }

    /**
     * @return array{rows: list<array<string, mixed>>, exhausted: bool}
     */
    private function fetch(int $resultId): array
    {
        if (!isset($this->results[$resultId])) {
            throw new ProtocolError("Unknown result ID '{$resultId}'");
        }

        $batch = $this->fetchBatch($resultId);
        if ($batch['exhausted']) {
            $this->closeNativeResult($this->results[$resultId]);
            unset($this->results[$resultId]);
        }

        return $batch;
    }

    private function closeResult(int $resultId): null
    {
        if ($resultId < 1 || $resultId >= $this->nextResultId) {
            throw new ProtocolError("Unknown result ID '{$resultId}'");
        }

        if (isset($this->results[$resultId])) {
            $this->closeNativeResult($this->results[$resultId]);
            unset($this->results[$resultId]);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>
     */
    private function execute(array $request): array
    {
        /** @var int $before */
        $before = $this->database->querySingle('SELECT total_changes()');
        $statementId = $request['statement_id'] ?? null;
        if ($statementId !== null) {
            if (!isset($this->statements[$statementId])) {
                throw new ProtocolError("Unknown statement ID '{$statementId}'");
            }
            $statement = $this->statements[$statementId];
            $statement->clear();
            try {
                $statement->reset();
            } catch (\Throwable) {
                $statement->reset();
            }
        } else {
            $statement = $this->prepareSingleStatement($request['sql'], 'Only one SQL statement may be executed at a time');
        }

        $this->bindParameters($statement, $request);

        $nativeResult = $statement->execute();
        $columns = $nativeResult->numColumns();
        /** @var int $after */
        $after = $this->database->querySingle('SELECT total_changes()');
        $value = [
            'result_id' => null,
            'rows' => [],
            'exhausted' => true,
            'row_count' => $columns > 0 ? null : $after - $before,
            'column_count' => $columns ?: null,
            'column_names' => null,
            'last_insert_id' => $this->database->lastInsertRowID(),
        ];

        if ($columns === 0) {
            $nativeResult->finalize();
            if ($statementId === null) {
                $statement->close();
            }

            return $value;
        }

        $columnNames = [];
        for ($column = 0; $column < $columns; ++$column) {
            $columnNames[] = $nativeResult->columnName($column);
        }
        $value['column_names'] = $columnNames;

        $resultId = $this->nextResultId++;
        $this->results[$resultId] = ['result' => $nativeResult, 'statement' => $statement, 'statement_id' => $statementId, 'pending' => null];
        try {
            $batch = $this->fetchBatch($resultId);
        } catch (\Throwable $exception) {
            $this->closeNativeResult($this->results[$resultId]);
            unset($this->results[$resultId]);

            throw $exception;
        }
        $value['result_id'] = $resultId;
        $value['rows'] = $batch['rows'];
        $value['exhausted'] = $batch['exhausted'];
        if ($batch['exhausted']) {
            $this->closeNativeResult($this->results[$resultId]);
            unset($this->results[$resultId]);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $request
     */
    private function bindParameters(\SQLite3Stmt $statement, array $request): void
    {
        /** @var bool $bindParameters */
        $bindParameters = $request['bind_parameters'] ?? true;
        if ($bindParameters === false && $statement->paramCount() > 0) {
            throw new \RuntimeException('Parameters are not allowed in direct queries');
        }

        if ($bindParameters && \count($request['params']) !== $statement->paramCount()) {
            throw new \RuntimeException(\sprintf(
                'Expected %d parameters, got %d',
                $statement->paramCount(),
                \count($request['params']),
            ));
        }

        foreach ($request['params'] as $key => $value) {
            $position = \is_int($key) ? $key + 1 : $key;
            $type = match (true) {
                $value === null => SQLITE3_NULL,
                \is_bool($value), \is_int($value) => SQLITE3_INTEGER,
                \is_float($value) => SQLITE3_FLOAT,
                $value instanceof SqliteBlob => SQLITE3_BLOB,
                default => SQLITE3_TEXT,
            };
            if (!$statement->bindValue($position, $value instanceof SqliteBlob ? $value->getBytes() : $value, $type)) {
                throw new \RuntimeException("Invalid parameter '{$position}'");
            }
        }
    }

    /**
     * @return array{rows: list<array<string, mixed>>, exhausted: bool}
     */
    private function fetchBatch(int $resultId): array
    {
        $result = $this->results[$resultId]['result'];
        $rows = [];
        if ($this->results[$resultId]['pending'] !== null) {
            $rows[] = $this->results[$resultId]['pending'];
            $this->results[$resultId]['pending'] = null;
        }

        while (\count($rows) < $this->batchSize) {
            $row = $result->fetchArray(SQLITE3_ASSOC);
            if ($row === false) {
                return ['rows' => $rows, 'exhausted' => true];
            }
            $rows[] = $this->convertRow($result, $row);
        }

        $row = $result->fetchArray(SQLITE3_ASSOC);
        if ($row === false) {
            return ['rows' => $rows, 'exhausted' => true];
        }

        $this->results[$resultId]['pending'] = $this->convertRow($result, $row);

        return ['rows' => $rows, 'exhausted' => false];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function convertRow(\SQLite3Result $result, array $row): array
    {
        $columnTypes = [];
        for ($column = 0, $columns = $result->numColumns(); $column < $columns; ++$column) {
            $columnTypes[$result->columnName($column)] = $result->columnType($column);
        }

        foreach ($row as $name => $value) {
            if ($columnTypes[$name] === SQLITE3_BLOB && \is_string($value)) {
                $row[$name] = new SqliteBlob($value);
            }
        }

        return $row;
    }

    /**
     * @param array{result: \SQLite3Result, statement: \SQLite3Stmt, statement_id: int|null, pending: array<string, mixed>|null} $resource
     */
    private function closeNativeResult(array $resource): void
    {
        $resource['result']->finalize();
        if ($resource['statement_id'] === null) {
            $resource['statement']->close();
        }
    }

    private function prepareSingleStatement(string $sql, string $error): \SQLite3Stmt
    {
        $statement = $this->database->prepare($sql);
        if (!$statement) {
            throw new \RuntimeException('SQL must contain an executable statement');
        }
        try {
            $consumedSql = $statement->getSQL();
        } catch (\Error $previous) {
            throw new \RuntimeException('SQL must contain an executable statement', previous: $previous);
        }
        if (SqlStatementBoundary::hasSecondStatement(\substr($sql, \strlen($consumedSql)))) {
            $statement->close();
            throw new \RuntimeException($error);
        }

        return $statement;
    }

    private function applyPragma(string $name, null|bool|int|float|string $value): null|bool|int|float|string
    {
        $encoded = match (true) {
            $value === null => 'NULL',
            \is_bool($value) => $value ? '1' : '0',
            \is_int($value), \is_float($value) => (string) $value,
            default => "'" . $this->database->escapeString($value) . "'",
        };

        /** @var null|bool|int|float|string */
        return $this->database->querySingle("PRAGMA {$name} = {$encoded}");
    }

    /**
     * @param array{path: string, open_mode: string, journal_mode: string, ...} $open
     */
    private function applyJournalMode(array $open): void
    {
        if ($open['journal_mode'] !== SqliteJournalMode::Automatic->value) {
            $effective = $this->applyPragma('journal_mode', $open['journal_mode']);
            if (\strtolower((string) $effective) !== $open['journal_mode']) {
                throw new \RuntimeException("Could not enable requested journal mode '{$open['journal_mode']}'");
            }
        } elseif ($open['path'] !== ':memory:' && $open['open_mode'] !== SqliteOpenMode::ReadOnly->name) {
            $effective = $this->applyPragma('journal_mode', 'wal');
            if (\strtolower((string) $effective) !== 'wal') {
                throw new \RuntimeException("Could not enable WAL journal mode, SQLite selected '{$effective}'");
            }
        }
    }

    /**
     * @param array{path: string, open_mode: string, synchronous_mode: string, ...} $open
     */
    private function applySynchronousMode(array $open): void
    {
        if ($open['synchronous_mode'] !== SqliteSynchronousMode::Automatic->value) {
            $this->applyPragma('synchronous', $open['synchronous_mode']);
        } elseif ($open['path'] !== ':memory:' && $open['open_mode'] !== SqliteOpenMode::ReadOnly->name) {
            $this->applyPragma('synchronous', 'normal');
        }
    }

    /**
     * @param array<string, array{callback: string, arg_count: int, deterministic: bool}> $functions
     */
    private function registerFunctions(array $functions): void
    {
        foreach ($functions as $name => $function) {
            if (!\is_callable($function['callback'])) {
                throw new \RuntimeException("Custom SQL function '{$name}' does not resolve to a callable in the child process");
            }
            $flags = $function['deterministic'] ? SQLITE3_DETERMINISTIC : 0;
            if (!$this->database->createFunction($name, $function['callback'], $function['arg_count'], $flags)) {
                throw new \RuntimeException("Could not register custom SQL function '{$name}'");
            }
        }
    }

    /**
     * @param array<string, array{step: string, final: string, arg_count: int}> $aggregates
     */
    private function registerAggregates(array $aggregates): void
    {
        foreach ($aggregates as $name => $aggregate) {
            if (!\is_callable($aggregate['step']) || !\is_callable($aggregate['final'])) {
                throw new \RuntimeException("Custom SQL aggregate '{$name}' does not resolve to callables in the child process");
            }
            if (!$this->database->createAggregate($name, $aggregate['step'], $aggregate['final'], $aggregate['arg_count'])) {
                throw new \RuntimeException("Could not register custom SQL aggregate '{$name}'");
            }
        }
    }

    /**
     * @param array<string, string> $collations
     */
    private function registerCollations(array $collations): void
    {
        foreach ($collations as $name => $callback) {
            if (!\is_callable($callback)) {
                throw new \RuntimeException("Custom collation '{$name}' does not resolve to a callable in the child process");
            }
            if (!$this->database->createCollation($name, $callback)) {
                throw new \RuntimeException("Could not register custom collation '{$name}'");
            }
        }
    }
}
