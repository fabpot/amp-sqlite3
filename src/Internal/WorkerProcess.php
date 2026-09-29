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
 *
 * @internal
 */
final class WorkerProcess
{
    private const SQLITE_BUSY = 5;
    private const ROW_PRODUCING_DML_CACHE_SIZE = 256;
    private const ROW_PRODUCING_DML_CACHE_MAX_SQL_LENGTH = 4096;

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

    /** @var \WeakMap<\SQLite3Stmt, SqliteStatementInfo> */
    private \WeakMap $statementInfo;

    private bool $capturingMetadata = false;
    /** @var SqliteStatementMetadata|null */
    private ?array $capturedMetadata = null;

    /** @var array<string, \SQLite3Stmt> */
    private array $internalStatements = [];

    /** @var array<string, array{schema_version: int, ordinary: bool}> */
    private array $ordinaryRowIdTables = [];

    /** @var array<string, bool> */
    private array $rowProducingDml = [];

    /** @var array<int, array{result: \SQLite3Result, statement: \SQLite3Stmt, statement_id: int|null, pending: SqliteRow|null}> */
    private array $results = [];

    /**
     * @return SqliteOpenConfig
     */
    public static function validateOpen(#[\SensitiveParameter] mixed $open): array
    {
        if (!\is_array($open)
            || !\is_string($open['path'] ?? null)
            || !\is_string($open['open_mode'] ?? null)
            || !\is_string($open['journal_mode'] ?? null)
            || !\is_string($open['synchronous_mode'] ?? null)
            || !\is_bool($open['foreign_keys'] ?? null)
            || !\is_int($open['busy_timeout'] ?? null)
            || $open['busy_timeout'] < 0
            || !\is_int($open['batch_size'] ?? null)
            || $open['batch_size'] < 1
            || !\is_bool($open['trusted_schema'] ?? null)
            || !\is_bool($open['extended_result_codes'] ?? null)
            || !self::isPragmaMap($open['pragmas'] ?? null)
            || !self::isFunctionMap($open['functions'] ?? null)
            || !self::isAggregateMap($open['aggregates'] ?? null)
            || !self::isCollationMap($open['collations'] ?? null)
        ) {
            throw new ProtocolError('Invalid SQLite startup payload');
        }

        if (!\in_array($open['open_mode'], \array_column(SqliteOpenMode::cases(), 'name'), true)
            || !\in_array($open['journal_mode'], \array_column(SqliteJournalMode::cases(), 'value'), true)
            || !\in_array($open['synchronous_mode'], \array_column(SqliteSynchronousMode::cases(), 'value'), true)
        ) {
            throw new ProtocolError('Invalid SQLite startup mode');
        }

        /** @var SqliteOpenConfig $open */
        return $open;
    }

    public function __construct(#[\SensitiveParameter] mixed $open)
    {
        $open = self::validateOpen($open);

        if (!\extension_loaded('sqlite3')) {
            throw new \RuntimeException('The sqlite3 extension is not loaded');
        }

        $version = \SQLite3::version()['versionString'] ?? null;
        if (!\is_string($version)) {
            throw new \RuntimeException('Could not determine the SQLite version');
        }
        if (\version_compare($version, '3.31.0', '<')) {
            throw new \RuntimeException("SQLite 3.31.0 or newer is required, {$version} is installed");
        }

        $flags = match ($open['open_mode']) {
            SqliteOpenMode::ReadOnly->name => SQLITE3_OPEN_READONLY,
            SqliteOpenMode::ReadWrite->name => SQLITE3_OPEN_READWRITE,
            SqliteOpenMode::ReadWriteCreate->name => SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE,
        };

        $this->database = new \SQLite3($open['path'], $flags);
        /** @var \WeakMap<\SQLite3Stmt, SqliteStatementInfo> $statementInfo */
        $statementInfo = new \WeakMap();
        $this->statementInfo = $statementInfo;
        $this->database->enableExceptions(true);
        // Changing the authorizer expires every prepared statement, so it is installed only once
        $this->database->setAuthorizer($this->authorize(...));
        $this->database->enableExtendedResultCodes($open['extended_result_codes']);
        $this->database->busyTimeout($open['busy_timeout']);
        $this->batchSize = $open['batch_size'];

        $this->applyPragma('trusted_schema', $open['trusted_schema']);
        $this->applyPragma('foreign_keys', $open['foreign_keys']);

        // Pragmas such as page_size only take effect before WAL mode is enabled
        foreach ($open['pragmas'] as $name => $value) {
            $this->applyPragma($name, $value);
        }

        $this->applyJournalMode($open);
        $this->applySynchronousMode($open);

        $this->registerFunctions($open['functions']);
        $this->registerAggregates($open['aggregates']);
        $this->registerCollations($open['collations']);
    }

    /**
     * @param array<string, mixed> $request
     */
    public function handle(array $request): mixed
    {
        $operation = self::requireString($request, 'operation');

        return match ($operation) {
            'close' => $this->close(),
            'backup' => $this->backup(self::requireString($request, 'path'), self::requireString($request, 'database')),
            'restore' => $this->restore(self::requireString($request, 'path'), self::requireString($request, 'database')),
            'openBlob' => $this->openBlob($request),
            'readBlob' => $this->readBlob(self::requirePositiveInt($request, 'blob_id'), self::requirePositiveInt($request, 'length')),
            'writeBlob' => $this->writeBlob(self::requirePositiveInt($request, 'blob_id'), self::requireString($request, 'bytes')),
            'closeBlob' => $this->closeBlob(self::requirePositiveInt($request, 'blob_id')),
            'prepare' => $this->prepare(self::requireSql($request)),
            'closeStatement' => $this->closeStatement(self::requirePositiveInt($request, 'statement_id')),
            'fetch' => $this->fetch(self::requirePositiveInt($request, 'result_id')),
            'closeResult' => $this->closeResult(self::requirePositiveInt($request, 'result_id')),
            'execute' => $this->execute($request),
            'executeStatement' => $this->execute($request, self::requirePositiveInt($request, 'statement_id')),
            'executeScript' => $this->executeScript(
                self::requireSql($request),
                self::requireTransactionMode($request),
            ),
            default => throw new ProtocolError("Unknown operation '{$operation}'"),
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

    private static function isPragmaMap(mixed $value): bool
    {
        return \is_array($value) && \array_all(
            $value,
            static fn (mixed $item, int|string $key): bool => \is_string($key)
                && (\is_bool($item) || \is_int($item) || \is_float($item) || \is_string($item)),
        );
    }

    private static function isFunctionMap(mixed $value): bool
    {
        return \is_array($value) && \array_all(
            $value,
            static fn (mixed $item, int|string $key): bool => \is_string($key)
                && \is_array($item)
                && \is_string($item['callback'] ?? null)
                && \is_int($item['arg_count'] ?? null)
                && \is_bool($item['deterministic'] ?? null),
        );
    }

    private static function isAggregateMap(mixed $value): bool
    {
        return \is_array($value) && \array_all(
            $value,
            static fn (mixed $item, int|string $key): bool => \is_string($key)
                && \is_array($item)
                && \is_string($item['step'] ?? null)
                && \is_string($item['final'] ?? null)
                && \is_int($item['arg_count'] ?? null),
        );
    }

    private static function isCollationMap(mixed $value): bool
    {
        return \is_array($value) && \array_all(
            $value,
            static fn (mixed $item, int|string $key): bool => \is_string($key) && \is_string($item),
        );
    }

    /**
     * @param array<string, mixed> $request
     */
    private static function requireString(array $request, string $key): string
    {
        if (!\is_string($request[$key] ?? null)) {
            throw new ProtocolError("Protocol field '{$key}' must be a string");
        }

        return (string) $request[$key];
    }

    /**
     * @param array<string, mixed> $request
     */
    private static function requireSql(array $request): string
    {
        $sql = self::requireString($request, 'sql');
        if (\str_contains($sql, "\0")) {
            throw new \RuntimeException('SQL must not contain NUL bytes');
        }

        return $sql;
    }

    /**
     * @param array<string, mixed> $request
     */
    private static function requireInt(array $request, string $key): int
    {
        if (!\is_int($request[$key] ?? null)) {
            throw new ProtocolError("Protocol field '{$key}' must be an integer");
        }

        return (int) $request[$key];
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return positive-int
     */
    private static function requirePositiveInt(array $request, string $key): int
    {
        $value = self::requireInt($request, $key);
        if ($value < 1) {
            throw new ProtocolError("Protocol field '{$key}' must be a positive integer");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $request
     */
    private static function optionalBool(array $request, string $key, bool $default): bool
    {
        if (!\array_key_exists($key, $request)) {
            return $default;
        }
        if (!\is_bool($request[$key])) {
            throw new ProtocolError("Protocol field '{$key}' must be a boolean");
        }

        return $request[$key];
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array<array-key, SqliteParameterValue>
     */
    private static function requireParameters(array $request): array
    {
        $parameters = $request['params'] ?? null;
        if (!\is_array($parameters) || !\array_all($parameters, self::isParameterValue(...))) {
            throw new ProtocolError("Protocol field 'params' contains an invalid parameter");
        }

        /** @var array<array-key, SqliteParameterValue> $parameters */
        return $parameters;
    }

    private static function isParameterValue(mixed $value): bool
    {
        return $value === null
            || \is_bool($value)
            || \is_int($value)
            || \is_float($value)
            || \is_string($value)
            || $value instanceof SqliteBlob;
    }

    /**
     * @param array<string, mixed> $request
     */
    private static function requireTransactionMode(array $request): string
    {
        $mode = self::requireString($request, 'transaction_mode');
        if (!\in_array($mode, ['DEFERRED', 'IMMEDIATE', 'EXCLUSIVE'], true)) {
            throw new ProtocolError("Invalid transaction mode '{$mode}'");
        }

        return $mode;
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
        foreach ($this->internalStatements as $statement) {
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
        $mode = self::requireString($request, 'mode');
        if ($mode !== SqliteBlobMode::ReadOnly->name && $mode !== SqliteBlobMode::ReadWrite->name) {
            throw new ProtocolError("Invalid BLOB mode '{$mode}'");
        }
        $flags = $mode === SqliteBlobMode::ReadWrite->name ? SQLITE3_OPEN_READWRITE : SQLITE3_OPEN_READONLY;
        $blob = $this->database->openBlob(
            self::requireString($request, 'table'),
            self::requireString($request, 'column'),
            self::requireInt($request, 'row_id'),
            self::requireString($request, 'database'),
            $flags,
        );
        if ($blob === false) {
            throw new \RuntimeException('Could not open SQLite BLOB');
        }
        $blobId = $this->nextBlobId++;
        $this->blobs[$blobId] = $blob;
        $stat = \fstat($blob);
        if ($stat === false) {
            throw new \RuntimeException('Could not determine SQLite BLOB length');
        }

        return ['blob_id' => $blobId, 'length' => $stat['size']];
    }

    /**
     * @param positive-int $length
     *
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
     * @return array{rows: list<SqliteRow>, exhausted: bool}
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

    private function executeScript(string $sql, string $transactionMode): null
    {
        // Scripts may attach or detach databases
        $this->ordinaryRowIdTables = [];
        $this->database->exec('BEGIN ' . $transactionMode);

        try {
            if (!SqlStatementBoundary::hasSecondStatement($sql)) {
                throw new \RuntimeException('SQL script must contain an executable statement');
            }

            do {
                $statement = $this->database->prepare($sql);
                if (!$statement) {
                    throw new \RuntimeException('SQL script must contain an executable statement');
                }
                try {
                    $statementSql = $statement->getSQL();
                    if (SqlStatementBoundary::startsWithKeyword($statementSql, 'BEGIN', 'COMMIT', 'END', 'ROLLBACK', 'SAVEPOINT', 'RELEASE')) {
                        throw new \RuntimeException('SQL scripts cannot contain transaction-control statements');
                    }
                    $result = $statement->execute();
                    if ($result === false) {
                        throw new \RuntimeException('Could not execute SQLite statement');
                    }
                    $result->finalize();
                } finally {
                    $statement->close();
                }
                $sql = \substr($sql, \strlen($statementSql));
            } while (SqlStatementBoundary::hasSecondStatement($sql));

            $this->database->exec('COMMIT');
        } catch (\Throwable $exception) {
            try {
                $this->database->exec('ROLLBACK');
            } catch (\Throwable) {
            }

            throw $exception;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>
     */
    private function execute(array $request, ?int $statementId = null): array
    {
        $before = $this->totalChanges();
        $lastInsertIdBefore = $this->database->lastInsertRowID();
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
            $this->assertExecutable($statement);
        } else {
            $statement = $this->prepareSingleStatement(self::requireSql($request), 'Only one SQL statement may be executed at a time');
        }

        $this->bindParameters($statement, $request);

        $nativeResult = $this->executeStatement($statement);
        if ($this->statementInfo[$statement]['metadata']['attach'] ?? false) {
            $this->ordinaryRowIdTables = [];
        }
        $columns = $nativeResult->numColumns();
        $value = [
            'result_id' => null,
            'rows' => [],
            'exhausted' => true,
            'row_count' => null,
            'column_count' => $columns ?: null,
            'column_names' => null,
            'last_insert_id' => null,
        ];

        if ($columns === 0) {
            $value['row_count'] = $this->totalChanges() - $before;
            $value['last_insert_id'] = $this->detectLastInsertId($statement, $lastInsertIdBefore);
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
        $bindParameters = self::optionalBool($request, 'bind_parameters', true);
        if ($bindParameters === false && $statement->paramCount() > 0) {
            throw new \RuntimeException('Parameters are not allowed in direct queries');
        }

        foreach (self::requireParameters($request) as $key => $value) {
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
     * @return array{rows: list<SqliteRow>, exhausted: bool}
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
            /** @var array<array-key, null|int|float|string> $row */
            $rows[] = $this->convertRow($result, $row);
        }

        $row = $result->fetchArray(SQLITE3_ASSOC);
        if ($row === false) {
            return ['rows' => $rows, 'exhausted' => true];
        }

        /** @var array<array-key, null|int|float|string> $row */
        $this->results[$resultId]['pending'] = $this->convertRow($result, $row);

        return ['rows' => $rows, 'exhausted' => false];
    }

    /**
     * @param SqliteRow $row
     *
     * @return SqliteRow
     */
    private function convertRow(\SQLite3Result $result, array $row): array
    {
        /** @var array<array-key, int> $columnTypes */
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
     * @param array{result: \SQLite3Result, statement: \SQLite3Stmt, statement_id: int|null, pending: SqliteRow|null} $resource
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
        [$statement, $metadata] = $this->captureMetadata(fn (): \SQLite3Stmt|false => $this->database->prepare($sql));
        if (!$statement) {
            throw new \RuntimeException('SQL must contain an executable statement');
        }
        try {
            $consumedSql = $statement->getSQL();
            // @phpstan-ignore catch.neverThrown (getSQL() throws when the SQL only contains comments)
        } catch (\Error $previous) {
            throw new \RuntimeException('SQL must contain an executable statement', previous: $previous);
        }
        if (SqlStatementBoundary::hasSecondStatement(\substr($sql, \strlen($consumedSql)))) {
            $statement->close();
            throw new \RuntimeException($error);
        }

        $metadata ??= self::emptyMetadata();
        $writes = !$statement->readOnly()
            && !SqlStatementBoundary::startsWithKeyword($consumedSql, 'EXPLAIN')
            && ($metadata['insert'] !== null || $metadata['update'] || $metadata['delete']);

        try {
            if ($writes && $this->producesRows($consumedSql)) {
                throw new \RuntimeException('Row-producing DML statements are not supported by the PHP SQLite3 extension');
            }
        } catch (\Throwable $exception) {
            $statement->close();
            throw $exception;
        }

        $this->statementInfo[$statement] = ['metadata' => $metadata, 'writes' => $writes];

        return $statement;
    }

    /**
     * Rejects DML that PHP would execute twice because it now produces rows, which only count_changes can cause
     * after a statement without RETURNING has been prepared.
     */
    private function assertExecutable(\SQLite3Stmt $statement): void
    {
        if (($this->statementInfo[$statement]['writes'] ?? false) && $this->queryInternal('PRAGMA count_changes')) {
            throw new \RuntimeException('Row-producing DML statements are not supported by the PHP SQLite3 extension');
        }
    }

    private function executeStatement(\SQLite3Stmt $statement): \SQLite3Result
    {
        // SQLite recompiles an expired statement while executing it, which may change what it inserts into
        [$result, $metadata] = $this->captureMetadata(static fn (): \SQLite3Result|false => $statement->execute());
        if ($result === false) {
            throw new \RuntimeException('Could not execute SQLite statement');
        }

        $info = $this->statementInfo[$statement] ?? null;
        if ($metadata !== null && $info !== null) {
            $this->statementInfo[$statement] = ['metadata' => $metadata, 'writes' => $info['writes']];
        }

        return $result;
    }

    /**
     * @template T
     *
     * @param \Closure():T $operation
     *
     * @return array{T, SqliteStatementMetadata|null}
     */
    private function captureMetadata(\Closure $operation): array
    {
        $this->capturingMetadata = true;
        $this->capturedMetadata = null;

        try {
            return [$operation(), $this->capturedMetadata];
        } finally {
            $this->capturingMetadata = false;
            $this->capturedMetadata = null;
        }
    }

    private function authorize(int $action, ?string $first = null, ?string $second = null, ?string $database = null, ?string $source = null): int
    {
        if (!$this->capturingMetadata || $source !== null) {
            return \SQLite3::OK;
        }

        $metadata = $this->capturedMetadata ?? self::emptyMetadata();
        if ($action === \SQLite3::INSERT && $first !== null && $database !== null) {
            $target = ['database' => $database, 'table' => $first];
            if ($metadata['insert'] !== null && $metadata['insert'] !== $target) {
                $metadata['ambiguous_insert'] = true;
            } else {
                $metadata['insert'] = $target;
            }
        } elseif ($action === \SQLite3::UPDATE) {
            $metadata['update'] = true;
        } elseif ($action === \SQLite3::DELETE) {
            $metadata['delete'] = true;
        } elseif ($action === \SQLite3::ATTACH || $action === \SQLite3::DETACH) {
            $metadata['attach'] = true;
        }
        $this->capturedMetadata = $metadata;

        return \SQLite3::OK;
    }

    /**
     * @return SqliteStatementMetadata
     */
    private static function emptyMetadata(): array
    {
        return ['insert' => null, 'ambiguous_insert' => false, 'update' => false, 'delete' => false, 'attach' => false];
    }

    private function producesRows(string $sql): bool
    {
        // Without count_changes, only the SQL text (a RETURNING clause) decides whether DML produces rows
        if (\strlen($sql) > self::ROW_PRODUCING_DML_CACHE_MAX_SQL_LENGTH || $this->queryInternal('PRAGMA count_changes')) {
            return $this->explainProducesRows($sql);
        }

        if (!isset($this->rowProducingDml[$sql]) && \count($this->rowProducingDml) >= self::ROW_PRODUCING_DML_CACHE_SIZE) {
            unset($this->rowProducingDml[\array_key_first($this->rowProducingDml)]);
        }

        return $this->rowProducingDml[$sql] ??= $this->explainProducesRows($sql);
    }

    private function explainProducesRows(string $sql): bool
    {
        $statement = $this->database->prepare('EXPLAIN ' . SqlStatementBoundary::skipInsignificant($sql));
        if (!$statement) {
            return false;
        }

        try {
            $result = $statement->execute();
            if ($result === false) {
                return false;
            }

            try {
                while (($row = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
                    if (($row['opcode'] ?? null) === 'ResultRow') {
                        return true;
                    }
                }
            } finally {
                $result->finalize();
            }
        } finally {
            $statement->close();
        }

        return false;
    }

    private function totalChanges(): int
    {
        /** @var int */
        return $this->queryInternal('SELECT total_changes()');
    }

    private function queryInternal(string $sql): mixed
    {
        $statement = $this->internalStatements[$sql] ??= $this->database->prepare($sql) ?: throw new \RuntimeException("Could not prepare '{$sql}'");
        $result = $statement->execute();
        if ($result === false) {
            throw new \RuntimeException("Could not execute '{$sql}'");
        }

        try {
            $row = $result->fetchArray(SQLITE3_NUM);
        } finally {
            $result->finalize();
        }

        return $row === false ? null : $row[0];
    }

    private function detectLastInsertId(\SQLite3Stmt $statement, int $before): ?int
    {
        $info = $this->statementInfo[$statement] ?? null;
        $metadata = $info['metadata'] ?? null;
        $target = $metadata['insert'] ?? null;
        if ($info === null
            || $metadata === null
            || $target === null
            || $metadata['ambiguous_insert']
            || $this->database->changes() === 0
        ) {
            return null;
        }

        if (!$this->isCachedOrdinaryRowIdTable($target)) {
            return null;
        }

        $after = $this->database->lastInsertRowID();
        if ($metadata['update'] && $after === $before) {
            // An UPSERT may have updated an existing row without inserting one. If
            // SQLite's connection-global value did not change, the branch is ambiguous.
            return null;
        }

        return $after;
    }

    /**
     * @param SqliteInsertTarget $target
     */
    private function isCachedOrdinaryRowIdTable(array $target): bool
    {
        $key = $target['database'] . "\0" . $target['table'];
        /** @var int $schemaVersion */
        $schemaVersion = $this->queryInternal('PRAGMA "' . \str_replace('"', '""', $target['database']) . '".schema_version');
        $cached = $this->ordinaryRowIdTables[$key] ?? null;
        if ($cached !== null && $cached['schema_version'] === $schemaVersion) {
            return $cached['ordinary'];
        }

        $ordinary = $this->isOrdinaryRowIdTable($target);
        $this->ordinaryRowIdTables[$key] = ['schema_version' => $schemaVersion, 'ordinary' => $ordinary];

        return $ordinary;
    }

    /**
     * Virtual tables have no root page, and the primary key index of a WITHOUT ROWID table is the table itself, so it
     * has no schema entry of its own.
     *
     * @param SqliteInsertTarget $target
     */
    private function isOrdinaryRowIdTable(array $target): bool
    {
        // sqlite_schema only exists since SQLite 3.33
        $schema = '"' . \str_replace('"', '""', $target['database']) . '".sqlite_master';
        $statement = $this->database->prepare(<<<SQL
            SELECT 1 FROM {$schema} AS t
            WHERE t.name = :name AND t.type = 'table' AND t.rootpage <> 0
                AND NOT EXISTS (
                    SELECT 1 FROM pragma_index_list(t.name, :database) AS i
                    WHERE i.origin = 'pk' AND i.name NOT IN (SELECT name FROM {$schema} WHERE type = 'index')
                )
            SQL);
        if (!$statement) {
            return false;
        }

        try {
            $statement->bindValue(':name', $target['table'], SQLITE3_TEXT);
            $statement->bindValue(':database', $target['database'], SQLITE3_TEXT);
            $result = $statement->execute();
            if ($result === false) {
                return false;
            }

            try {
                return $result->fetchArray(SQLITE3_NUM) !== false;
            } finally {
                $result->finalize();
            }
        } finally {
            $statement->close();
        }
    }

    private function applyPragma(string $name, #[\SensitiveParameter] bool|int|float|string $value): null|bool|int|float|string
    {
        $encoded = match (true) {
            \is_bool($value) => $value ? '1' : '0',
            \is_int($value), \is_float($value) => (string) $value,
            default => "'" . $this->database->escapeString($value) . "'",
        };

        try {
            /** @var null|bool|int|float|string */
            return $this->database->querySingle("PRAGMA {$name} = {$encoded}");
        } catch (\SQLite3Exception $exception) {
            // The native frame's SQL argument contains the value, so it must not reach the trace
            throw new \SQLite3Exception($exception->getMessage(), $exception->getCode());
        }
    }

    /**
     * @param SqliteOpenConfig $open
     */
    private function applyJournalMode(#[\SensitiveParameter] array $open): void
    {
        if ($open['journal_mode'] !== SqliteJournalMode::Automatic->value) {
            $effective = $open['journal_mode'] === SqliteJournalMode::Wal->value
                ? $this->enableWal($open['busy_timeout'])
                : $this->applyPragma('journal_mode', $open['journal_mode']);
            if (\strtolower((string) $effective) !== $open['journal_mode']) {
                throw new \RuntimeException("Could not enable requested journal mode '{$open['journal_mode']}'");
            }
        } elseif ($open['path'] !== ':memory:' && $open['open_mode'] !== SqliteOpenMode::ReadOnly->name) {
            $effective = $this->enableWal($open['busy_timeout']);
            if (\strtolower((string) $effective) !== 'wal') {
                throw new \RuntimeException("Could not enable WAL journal mode, SQLite selected '{$effective}'");
            }
        }
    }

    private function enableWal(int $busyTimeout): null|bool|int|float|string
    {
        $deadline = \hrtime(true) + $busyTimeout * 1_000_000;

        do {
            try {
                return $this->applyPragma('journal_mode', 'wal');
            } catch (\SQLite3Exception $exception) {
                if (($exception->getCode() & 0xFF) !== self::SQLITE_BUSY || \hrtime(true) >= $deadline) {
                    throw $exception;
                }
            }

            \usleep((int) \min(1_000, \max(0, ($deadline - \hrtime(true)) / 1_000)));
        } while (true);
    }

    /**
     * @param SqliteOpenConfig $open
     */
    private function applySynchronousMode(#[\SensitiveParameter] array $open): void
    {
        if ($open['synchronous_mode'] !== SqliteSynchronousMode::Automatic->value) {
            $this->applyPragma('synchronous', $open['synchronous_mode']);
        } elseif ($open['path'] !== ':memory:'
            && $open['open_mode'] !== SqliteOpenMode::ReadOnly->name
            && ($open['journal_mode'] === SqliteJournalMode::Automatic->value || $open['journal_mode'] === SqliteJournalMode::Wal->value)
        ) {
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
