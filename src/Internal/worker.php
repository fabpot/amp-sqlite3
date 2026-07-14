<?php

declare(strict_types=1);

use Amp\Sync\Channel;
use Fabpot\Amp\Sqlite\Internal\ProtocolError;
use Fabpot\Amp\Sqlite\Internal\SqlScanner;
use Fabpot\Amp\Sqlite\SqliteBlob;
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
     *     pragmas: array<string, null|bool|int|float|string>
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

    $channel->send(['ready' => true]);

    $nextResultId = 1;
    $nextStatementId = 1;
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

    while (($request = $channel->receive()) !== null) {
        try {
            if ($request['operation'] === 'close') {
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

            if ($request['operation'] === 'prepare') {
                $statement = $database->prepare($request['sql']);
                $consumedSql = $statement->getSQL();
                if (SqlScanner::hasSecondStatement(substr($request['sql'], strlen($consumedSql)))) {
                    $statement->close();
                    throw new RuntimeException('Only one SQL statement may be prepared at a time');
                }
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
                $statement->reset();
                $statement->clear();
            } else {
                $statement = $database->prepare($request['sql']);
                $consumedSql = $statement->getSQL();
                if (SqlScanner::hasSecondStatement(substr($request['sql'], strlen($consumedSql)))) {
                    throw new RuntimeException('Only one SQL statement may be executed at a time');
                }
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
                $statement->bindValue($position, $value instanceof SqliteBlob ? $value->getBytes() : $value, $type);
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
                'last_insert_id' => $database->lastInsertRowID(),
            ];

            if ($columns > 0) {
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
        } catch (Throwable $exception) {
            $channel->send([
                'id' => $request['id'],
                'query_error' => [
                    'message' => $exception->getMessage(),
                    'code' => $database->lastErrorCode(),
                    'extended_code' => $database->lastExtendedErrorCode(),
                ],
            ]);
        }
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
