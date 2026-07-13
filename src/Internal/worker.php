<?php

declare(strict_types=1);

use Amp\Sync\Channel;
use Fabpot\Amp\Sqlite\Internal\SqlScanner;
use Fabpot\Amp\Sqlite\SqliteBlob;
use Fabpot\Amp\Sqlite\SqliteJournalMode;
use Fabpot\Amp\Sqlite\SqliteOpenMode;
use Fabpot\Amp\Sqlite\SqliteSynchronousMode;

return static function (Channel $channel): null {
    /** @var array{
     *     path: string,
     *     openMode: string,
     *     journalMode: string,
     *     synchronousMode: string,
     *     foreignKeys: bool,
     *     busyTimeout: int,
     *     batchSize: positive-int,
     *     trustedSchema: bool,
     *     extendedResultCodes: bool,
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

    $flags = match ($open['openMode']) {
        SqliteOpenMode::ReadOnly->name => SQLITE3_OPEN_READONLY,
        SqliteOpenMode::ReadWrite->name => SQLITE3_OPEN_READWRITE,
        SqliteOpenMode::ReadWriteCreate->name => SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE,
    };

    $database = new SQLite3($open['path'], $flags);
    $database->enableExceptions(true);
    $database->enableExtendedResultCodes($open['extendedResultCodes']);
    $database->busyTimeout($open['busyTimeout']);

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

    $pragma('trusted_schema', $open['trustedSchema']);
    $pragma('foreign_keys', $open['foreignKeys']);

    if ($open['journalMode'] !== SqliteJournalMode::Automatic->value) {
        $effective = $pragma('journal_mode', $open['journalMode']);
        if (strtolower((string) $effective) !== $open['journalMode']) {
            throw new RuntimeException("Could not enable requested journal mode '{$open['journalMode']}'");
        }
    } elseif ($open['path'] !== ':memory:' && $open['openMode'] !== SqliteOpenMode::ReadOnly->name) {
        $effective = $pragma('journal_mode', 'wal');
        if (strtolower((string) $effective) !== 'wal') {
            throw new RuntimeException("Could not enable WAL journal mode, SQLite selected '{$effective}'");
        }
    }

    if ($open['synchronousMode'] !== SqliteSynchronousMode::Automatic->value) {
        $pragma('synchronous', $open['synchronousMode']);
    } elseif ($open['path'] !== ':memory:' && $open['openMode'] !== SqliteOpenMode::ReadOnly->name) {
        $pragma('synchronous', 'normal');
    }

    foreach ($open['pragmas'] as $name => $value) {
        $pragma($name, $value);
    }

    $channel->send(['ready' => true]);

    $nextResultId = 1;
    $nextStatementId = 1;
    /** @var array<int, SQLite3Stmt> $statements */
    $statements = [];
    /** @var array<int, array{result: SQLite3Result, statement: SQLite3Stmt, statementId: int|null, pending: array<string, mixed>|null}> $results */
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

        while (count($rows) < $open['batchSize']) {
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
        if ($resource['statementId'] === null) {
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
                $statements[$statementId] = $statement;
                $channel->send(['id' => $request['id'], 'value' => ['statementId' => $statementId]]);
                continue;
            }

            if ($request['operation'] === 'closeStatement') {
                if (isset($statements[$request['statementId']])) {
                    foreach ($results as $resultId => $resource) {
                        if ($resource['statementId'] === $request['statementId']) {
                            $closeResult($resource);
                            unset($results[$resultId]);
                        }
                    }
                    $statements[$request['statementId']]->close();
                    unset($statements[$request['statementId']]);
                }
                $channel->send(['id' => $request['id'], 'value' => null]);
                continue;
            }

            if ($request['operation'] === 'fetch') {
                if (!isset($results[$request['resultId']])) {
                    throw new RuntimeException("Unknown result ID '{$request['resultId']}'");
                }

                $batch = $fetchBatch($results[$request['resultId']]);
                if ($batch['exhausted']) {
                    $closeResult($results[$request['resultId']]);
                    unset($results[$request['resultId']]);
                }
                $channel->send(['id' => $request['id'], 'value' => $batch]);
                continue;
            }

            if ($request['operation'] === 'closeResult') {
                if (isset($results[$request['resultId']])) {
                    $closeResult($results[$request['resultId']]);
                    unset($results[$request['resultId']]);
                }
                $channel->send(['id' => $request['id'], 'value' => null]);
                continue;
            }

            if ($request['operation'] !== 'execute' && $request['operation'] !== 'executeStatement') {
                throw new RuntimeException("Unknown operation '{$request['operation']}'");
            }

            /** @var int $before */
            $before = $database->querySingle('SELECT total_changes()');
            $statementId = $request['statementId'] ?? null;
            if ($statementId !== null) {
                if (!isset($statements[$statementId])) {
                    throw new RuntimeException("Unknown statement ID '{$statementId}'");
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
                'resultId' => null,
                'rows' => [],
                'exhausted' => true,
                'rowCount' => $columns > 0 ? null : $after - $before,
                'columnCount' => $columns ?: null,
                'lastInsertId' => $database->lastInsertRowID(),
            ];

            if ($columns > 0) {
                $resultId = $nextResultId++;
                $results[$resultId] = ['result' => $nativeResult, 'statement' => $statement, 'statementId' => $statementId, 'pending' => null];
                $batch = $fetchBatch($results[$resultId]);
                $value['resultId'] = $resultId;
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
        } catch (Throwable $exception) {
            $channel->send([
                'id' => $request['id'],
                'error' => [
                    'message' => $exception->getMessage(),
                    'code' => $database->lastErrorCode(),
                    'extendedCode' => $database->lastExtendedErrorCode(),
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
