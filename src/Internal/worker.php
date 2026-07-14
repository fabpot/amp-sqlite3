<?php

declare(strict_types=1);

use Amp\Sync\Channel;
use Fabpot\Amp\Sqlite\Internal\ProtocolError;
use Fabpot\Amp\Sqlite\Internal\SqlStatementBoundary;
use Fabpot\Amp\Sqlite\SqliteBlob;
use Fabpot\Amp\Sqlite\SqliteBlobMode;
use Fabpot\Amp\Sqlite\SqliteJournalMode;
use Fabpot\Amp\Sqlite\SqliteOpenMode;
use Fabpot\Amp\Sqlite\SqliteSynchronousMode;

return static function (Channel $channel): null {
    /** @var array{
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
    $open = $channel->receive();

    if (!extension_loaded('sqlite3')) {
        throw new RuntimeException('The sqlite3 extension is not loaded');
    }

    $version = SQLite3::version()['versionString'];
    if (version_compare($version, '3.31.0', '<')) {
        throw new RuntimeException("SQLite 3.31.0 or newer is required, {$version} is installed");
    }

    $flags = match ($open['open_mode']) {
        SqliteOpenMode::ReadOnly->name => SQLITE3_OPEN_READONLY,
        SqliteOpenMode::ReadWrite->name => SQLITE3_OPEN_READWRITE,
        SqliteOpenMode::ReadWriteCreate->name => SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE,
    };

    $database = new SQLite3($open['path'], $flags);
    $database->enableExceptions(true);
    $database->enableExtendedResultCodes($open['extended_result_codes']);
    $database->busyTimeout($open['busy_timeout']);

    $pragma = static function (string $name, null|bool|int|float|string $value) use ($database): null|bool|int|float|string {
        $encoded = match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value) => (string) $value,
            default => "'" . $database->escapeString($value) . "'",
        };

        /** @var null|bool|int|float|string */
        return $database->querySingle("PRAGMA {$name} = {$encoded}");
    };

    $pragma('trusted_schema', $open['trusted_schema']);
    $pragma('foreign_keys', $open['foreign_keys']);

    if ($open['journal_mode'] !== SqliteJournalMode::Automatic->value) {
        $effective = $pragma('journal_mode', $open['journal_mode']);
        if (strtolower((string) $effective) !== $open['journal_mode']) {
            throw new RuntimeException("Could not enable requested journal mode '{$open['journal_mode']}'");
        }
    } elseif ($open['path'] !== ':memory:' && $open['open_mode'] !== SqliteOpenMode::ReadOnly->name) {
        $effective = $pragma('journal_mode', 'wal');
        if (strtolower((string) $effective) !== 'wal') {
            throw new RuntimeException("Could not enable WAL journal mode, SQLite selected '{$effective}'");
        }
    }

    if ($open['synchronous_mode'] !== SqliteSynchronousMode::Automatic->value) {
        $pragma('synchronous', $open['synchronous_mode']);
    } elseif ($open['path'] !== ':memory:' && $open['open_mode'] !== SqliteOpenMode::ReadOnly->name) {
        $pragma('synchronous', 'normal');
    }

    foreach ($open['pragmas'] as $name => $value) {
        $pragma($name, $value);
    }

    foreach ($open['functions'] as $name => $function) {
        if (!is_callable($function['callback'])) {
            throw new RuntimeException("Custom SQL function '{$name}' does not resolve to a callable in the child process");
        }
        $flags = $function['deterministic'] ? SQLITE3_DETERMINISTIC : 0;
        if (!$database->createFunction($name, $function['callback'], $function['arg_count'], $flags)) {
            throw new RuntimeException("Could not register custom SQL function '{$name}'");
        }
    }

    foreach ($open['aggregates'] as $name => $aggregate) {
        if (!is_callable($aggregate['step']) || !is_callable($aggregate['final'])) {
            throw new RuntimeException("Custom SQL aggregate '{$name}' does not resolve to callables in the child process");
        }
        if (!$database->createAggregate($name, $aggregate['step'], $aggregate['final'], $aggregate['arg_count'])) {
            throw new RuntimeException("Could not register custom SQL aggregate '{$name}'");
        }
    }

    foreach ($open['collations'] as $name => $callback) {
        if (!is_callable($callback)) {
            throw new RuntimeException("Custom collation '{$name}' does not resolve to a callable in the child process");
        }
        if (!$database->createCollation($name, $callback)) {
            throw new RuntimeException("Could not register custom collation '{$name}'");
        }
    }

    $channel->send(['ready' => true]);

    $nextBlobId = 1;
    $nextResultId = 1;
    $nextStatementId = 1;
    /** @var array<int, true> $knownBlobIds */
    $knownBlobIds = [];
    /** @var array<int, resource> $blobs */
    $blobs = [];
    /** @var array<int, true> $knownStatementIds */
    $knownStatementIds = [];
    /** @var array<int, SQLite3Stmt> $statements */
    $statements = [];
    /** @var array<int, true> $knownResultIds */
    $knownResultIds = [];
    /** @var array<int, array{result: SQLite3Result, statement: SQLite3Stmt, statement_id: int|null, pending: array<string, mixed>|null}> $results */
    $results = [];

    $convertRow = static function (SQLite3Result $result, array $row): array {
        $columnTypes = [];
        for ($column = 0, $columns = $result->numColumns(); $column < $columns; ++$column) {
            $columnTypes[$result->columnName($column)] = $result->columnType($column);
        }

        foreach ($row as $name => $value) {
            if ($columnTypes[$name] === SQLITE3_BLOB && is_string($value)) {
                $row[$name] = new SqliteBlob($value);
            }
        }

        return $row;
    };

    $fetchBatch = static function (array &$resource) use ($convertRow, $open): array {
        $rows = [];
        if ($resource['pending'] !== null) {
            $rows[] = $resource['pending'];
            $resource['pending'] = null;
        }

        while (count($rows) < $open['batch_size']) {
            $row = $resource['result']->fetchArray(SQLITE3_ASSOC);
            if ($row === false) {
                return ['rows' => $rows, 'exhausted' => true];
            }
            $rows[] = $convertRow($resource['result'], $row);
        }

        $row = $resource['result']->fetchArray(SQLITE3_ASSOC);
        if ($row === false) {
            return ['rows' => $rows, 'exhausted' => true];
        }

        $resource['pending'] = $convertRow($resource['result'], $row);

        return ['rows' => $rows, 'exhausted' => false];
    };

    $closeResult = static function (array $resource): void {
        $resource['result']->finalize();
        if ($resource['statement_id'] === null) {
            $resource['statement']->close();
        }
    };

    $prepareSingleStatement = static function (string $sql, string $error) use ($database): SQLite3Stmt {
        $statement = $database->prepare($sql);
        if (!$statement) {
            throw new RuntimeException('SQL must contain an executable statement');
        }
        try {
            $consumedSql = $statement->getSQL();
        } catch (Error $previous) {
            throw new RuntimeException('SQL must contain an executable statement', previous: $previous);
        }
        if (SqlStatementBoundary::hasSecondStatement(substr($sql, strlen($consumedSql)))) {
            $statement->close();
            throw new RuntimeException($error);
        }

        return $statement;
    };

    while (($request = $channel->receive()) !== null) {
        try {
            if ($request['operation'] === 'close') {
                foreach ($blobs as $blob) {
                    fclose($blob);
                }
                foreach ($results as $resource) {
                    $closeResult($resource);
                }
                foreach ($statements as $statement) {
                    $statement->close();
                }
                $database->close();
                $channel->send(['id' => $request['id'], 'value' => null]);

                return null;
            }

            if ($request['operation'] === 'backup') {
                $destination = new SQLite3($request['path'], SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
                $destination->enableExceptions(true);
                try {
                    if (!$database->backup($destination, $request['database'])) {
                        throw new RuntimeException('Could not back up the SQLite database');
                    }
                } finally {
                    $destination->close();
                }
                $channel->send(['id' => $request['id'], 'value' => null]);
                continue;
            }

            if ($request['operation'] === 'restore') {
                $source = new SQLite3($request['path'], SQLITE3_OPEN_READONLY);
                $source->enableExceptions(true);
                try {
                    if (!$source->backup($database, 'main', $request['database'])) {
                        throw new RuntimeException('Could not restore the SQLite database');
                    }
                } finally {
                    $source->close();
                }
                $channel->send(['id' => $request['id'], 'value' => null]);
                continue;
            }

            if ($request['operation'] === 'openBlob') {
                $flags = $request['mode'] === SqliteBlobMode::ReadWrite->name
                    ? SQLITE3_OPEN_READWRITE
                    : SQLITE3_OPEN_READONLY;
                $blob = $database->openBlob(
                    $request['table'],
                    $request['column'],
                    $request['row_id'],
                    $request['database'],
                    $flags,
                );
                $blobId = $nextBlobId++;
                $knownBlobIds[$blobId] = true;
                $blobs[$blobId] = $blob;
                $stat = fstat($blob);
                if ($stat === false) {
                    throw new RuntimeException('Could not determine SQLite BLOB length');
                }
                $channel->send([
                    'id' => $request['id'],
                    'value' => ['blob_id' => $blobId, 'length' => $stat['size']],
                ]);
                continue;
            }

            if ($request['operation'] === 'readBlob') {
                if (!isset($blobs[$request['blob_id']])) {
                    throw new ProtocolError("Unknown BLOB ID '{$request['blob_id']}'");
                }
                $bytes = fread($blobs[$request['blob_id']], $request['length']);
                if ($bytes === false) {
                    throw new RuntimeException('Could not read from SQLite BLOB');
                }
                $channel->send(['id' => $request['id'], 'value' => ['bytes' => $bytes]]);
                continue;
            }

            if ($request['operation'] === 'writeBlob') {
                if (!isset($blobs[$request['blob_id']])) {
                    throw new ProtocolError("Unknown BLOB ID '{$request['blob_id']}'");
                }
                $written = fwrite($blobs[$request['blob_id']], $request['bytes']);
                if ($written !== strlen($request['bytes'])) {
                    throw new RuntimeException('Could not write to SQLite BLOB');
                }
                $channel->send(['id' => $request['id'], 'value' => null]);
                continue;
            }

            if ($request['operation'] === 'closeBlob') {
                if (!isset($knownBlobIds[$request['blob_id']])) {
                    throw new ProtocolError("Unknown BLOB ID '{$request['blob_id']}'");
                }
                if (isset($blobs[$request['blob_id']])) {
                    fclose($blobs[$request['blob_id']]);
                    unset($blobs[$request['blob_id']]);
                }
                $channel->send(['id' => $request['id'], 'value' => null]);
                continue;
            }

            if ($request['operation'] === 'prepare') {
                $statement = $prepareSingleStatement($request['sql'], 'Only one SQL statement may be prepared at a time');
                $statementId = $nextStatementId++;
                $knownStatementIds[$statementId] = true;
                $statements[$statementId] = $statement;
                $channel->send(['id' => $request['id'], 'value' => ['statement_id' => $statementId]]);
                continue;
            }

            if ($request['operation'] === 'closeStatement') {
                if (!isset($knownStatementIds[$request['statement_id']])) {
                    throw new ProtocolError("Unknown statement ID '{$request['statement_id']}'");
                }

                if (isset($statements[$request['statement_id']])) {
                    foreach ($results as $resultId => $resource) {
                        if ($resource['statement_id'] === $request['statement_id']) {
                            $closeResult($resource);
                            unset($results[$resultId]);
                        }
                    }
                    $statements[$request['statement_id']]->close();
                    unset($statements[$request['statement_id']]);
                }
                $channel->send(['id' => $request['id'], 'value' => null]);
                continue;
            }

            if ($request['operation'] === 'fetch') {
                if (!isset($results[$request['result_id']])) {
                    throw new ProtocolError("Unknown result ID '{$request['result_id']}'");
                }

                $batch = $fetchBatch($results[$request['result_id']]);
                if ($batch['exhausted']) {
                    $closeResult($results[$request['result_id']]);
                    unset($results[$request['result_id']]);
                }
                $channel->send(['id' => $request['id'], 'value' => $batch]);
                continue;
            }

            if ($request['operation'] === 'closeResult') {
                if (!isset($knownResultIds[$request['result_id']])) {
                    throw new ProtocolError("Unknown result ID '{$request['result_id']}'");
                }

                if (isset($results[$request['result_id']])) {
                    $closeResult($results[$request['result_id']]);
                    unset($results[$request['result_id']]);
                }
                $channel->send(['id' => $request['id'], 'value' => null]);
                continue;
            }

            if ($request['operation'] !== 'execute' && $request['operation'] !== 'executeStatement') {
                throw new ProtocolError("Unknown operation '{$request['operation']}'");
            }

            /** @var int $before */
            $before = $database->querySingle('SELECT total_changes()');
            $statementId = $request['statement_id'] ?? null;
            if ($statementId !== null) {
                if (!isset($statements[$statementId])) {
                    throw new ProtocolError("Unknown statement ID '{$statementId}'");
                }
                $statement = $statements[$statementId];
                $statement->clear();
                try {
                    $statement->reset();
                } catch (Throwable) {
                    $statement->reset();
                }
            } else {
                $statement = $prepareSingleStatement($request['sql'], 'Only one SQL statement may be executed at a time');
            }

            /** @var bool $bindParameters */
            $bindParameters = $request['bind_parameters'] ?? true;
            if ($bindParameters === false && $statement->paramCount() > 0) {
                throw new RuntimeException('Parameters are not allowed in direct queries');
            }

            if ($bindParameters && count($request['params']) !== $statement->paramCount()) {
                throw new RuntimeException(sprintf(
                    'Expected %d parameters, got %d',
                    $statement->paramCount(),
                    count($request['params']),
                ));
            }

            foreach ($request['params'] as $key => $value) {
                $position = is_int($key) ? $key + 1 : $key;
                $type = match (true) {
                    $value === null => SQLITE3_NULL,
                    is_bool($value), is_int($value) => SQLITE3_INTEGER,
                    is_float($value) => SQLITE3_FLOAT,
                    $value instanceof SqliteBlob => SQLITE3_BLOB,
                    default => SQLITE3_TEXT,
                };
                if (!$statement->bindValue($position, $value instanceof SqliteBlob ? $value->getBytes() : $value, $type)) {
                    throw new RuntimeException("Invalid parameter '{$position}'");
                }
            }

            $nativeResult = $statement->execute();
            $columns = $nativeResult->numColumns();
            /** @var int $after */
            $after = $database->querySingle('SELECT total_changes()');
            $value = [
                'result_id' => null,
                'rows' => [],
                'exhausted' => true,
                'row_count' => $columns > 0 ? null : $after - $before,
                'column_count' => $columns ?: null,
                'column_names' => null,
                'last_insert_id' => $database->lastInsertRowID(),
            ];

            if ($columns > 0) {
                $columnNames = [];
                for ($column = 0; $column < $columns; ++$column) {
                    $columnNames[] = $nativeResult->columnName($column);
                }
                $value['column_names'] = $columnNames;
                $resultId = $nextResultId++;
                $knownResultIds[$resultId] = true;
                $results[$resultId] = ['result' => $nativeResult, 'statement' => $statement, 'statement_id' => $statementId, 'pending' => null];
                $batch = $fetchBatch($results[$resultId]);
                $value['result_id'] = $resultId;
                $value['rows'] = $batch['rows'];
                $value['exhausted'] = $batch['exhausted'];
                if ($batch['exhausted']) {
                    $closeResult($results[$resultId]);
                    unset($results[$resultId]);
                }
            } else {
                $nativeResult->finalize();
                if ($statementId === null) {
                    $statement->close();
                }
            }

            $channel->send(['id' => $request['id'], 'value' => $value]);
        } catch (ProtocolError $error) {
            $channel->send([
                'id' => $request['id'],
                'protocol_error' => ['message' => $error->getMessage()],
            ]);

            break;
        } catch (SQLite3Exception $exception) {
            $channel->send([
                'id' => $request['id'],
                'query_error' => [
                    'message' => $exception->getMessage(),
                    'code' => $exception->getCode() & 0xFF,
                    'extended_code' => $database->lastExtendedErrorCode(),
                ],
            ]);
        } catch (Throwable $exception) {
            $channel->send([
                'id' => $request['id'],
                'query_error' => [
                    'message' => $exception->getMessage(),
                    'code' => 0,
                    'extended_code' => 0,
                ],
            ]);
        }
    }

    foreach ($blobs as $blob) {
        fclose($blob);
    }
    foreach ($results as $resource) {
        $closeResult($resource);
    }
    foreach ($statements as $statement) {
        $statement->close();
    }
    $database->close();

    return null;
};
