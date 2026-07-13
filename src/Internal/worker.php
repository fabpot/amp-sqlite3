<?php

declare(strict_types=1);

use Amp\Sync\Channel;
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
     *     trustedSchema: bool,
     *     extendedResultCodes: bool,
     *     pragmas: array<string, null|bool|int|float|string>
     * } $open
     */
    $open = $channel->receive();

    if (!extension_loaded('sqlite3')) {
        throw new \RuntimeException('The sqlite3 extension is not loaded');
    }

    $version = SQLite3::version()['versionString'];
    if (version_compare($version, '3.31.0', '<')) {
        throw new \RuntimeException("SQLite 3.31.0 or newer is required, {$version} is installed");
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
            throw new \RuntimeException("Could not enable requested journal mode '{$open['journalMode']}'");
        }
    } elseif ($open['path'] !== ':memory:' && $open['openMode'] !== SqliteOpenMode::ReadOnly->name) {
        $effective = $pragma('journal_mode', 'wal');
        if (strtolower((string) $effective) !== 'wal') {
            throw new \RuntimeException("Could not enable WAL journal mode, SQLite selected '{$effective}'");
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

    while (($request = $channel->receive()) !== null) {
        try {
            if ($request['operation'] === 'close') {
                $database->close();
                $channel->send(['id' => $request['id'], 'value' => null]);

                return null;
            }

            if ($request['operation'] !== 'execute') {
                throw new \RuntimeException("Unknown operation '{$request['operation']}'");
            }

            /** @var int $before */
            $before = $database->querySingle('SELECT total_changes()');
            $statement = $database->prepare($request['sql']);
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
            $rows = [];
            $columnTypes = [];
            for ($column = 0; $column < $columns; ++$column) {
                $columnTypes[$nativeResult->columnName($column)] = $nativeResult->columnType($column);
            }

            if ($columns > 0) {
                while ($row = $nativeResult->fetchArray(SQLITE3_ASSOC)) {
                    foreach ($row as $name => $value) {
                        if ($columnTypes[$name] === SQLITE3_BLOB && is_string($value)) {
                            $row[$name] = new SqliteBlob($value);
                        }
                    }
                    $rows[] = $row;
                }
            }

            $nativeResult->finalize();
            $statement->close();
            /** @var int $after */
            $after = $database->querySingle('SELECT total_changes()');

            $channel->send([
                'id' => $request['id'],
                'value' => [
                    'rows' => $rows,
                    'rowCount' => $columns > 0 ? null : $after - $before,
                    'columnCount' => $columns ?: null,
                    'lastInsertId' => $database->lastInsertRowID(),
                ],
            ]);
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

    $database->close();

    return null;
};
