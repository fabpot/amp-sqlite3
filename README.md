# AMPHP SQLite3

An asynchronous SQLite client for [Amp](https://amphp.org/) applications. It keeps database work from blocking the event loop and supports connection pools, transactions, prepared statements, incremental BLOB I/O, backups, and custom SQL functions.

## Installation

```bash
composer require fabpot/amphp-sqlite3
```

PHP 8.4 or newer, `ext-sqlite3`, and SQLite 3.31.0 or newer are required.

## Quick start

Create a configuration with the path to your database, then connect with `SqliteConnector`:

```php
use Fabpot\Amp\Sqlite\SqliteConfig;
use Fabpot\Amp\Sqlite\SqliteConnector;

$config = new SqliteConfig(__DIR__ . '/app.sqlite');
$connection = (new SqliteConnector())->connect($config);

try {
    $connection->query(<<<'SQL'
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL
        )
        SQL);

    $result = $connection->execute(
        'INSERT INTO users (name) VALUES (:name)',
        [':name' => 'Fabien'],
    );

    $userId = $result->getLastInsertId();

    foreach ($connection->query('SELECT id, name FROM users ORDER BY id') as $user) {
        echo $user['name'] . "\n";
    }
} finally {
    $connection->close();
}
```

Use `:memory:` instead of a file path for a temporary in-memory database. Relative paths are resolved from the current working directory. SQLite URI filenames such as `file:database.sqlite` are not supported.

## Configuration

`SqliteConfig` is immutable: every `with*()` method returns a new configuration. The following example shows all available connection options and their defaults:

```php
use Fabpot\Amp\Sqlite\SqliteConfig;
use Fabpot\Amp\Sqlite\SqliteJournalMode;
use Fabpot\Amp\Sqlite\SqliteOpenMode;
use Fabpot\Amp\Sqlite\SqliteSynchronousMode;
use Fabpot\Amp\Sqlite\SqliteTransactionMode;

$config = (new SqliteConfig(__DIR__ . '/app.sqlite'))
    ->withOpenMode(SqliteOpenMode::ReadWriteCreate)
    ->withJournalMode(SqliteJournalMode::Automatic)
    ->withSynchronousMode(SqliteSynchronousMode::Automatic)
    ->withForeignKeys(true)
    ->withBusyTimeout(5_000) // milliseconds
    ->withTrustedSchema(false)
    ->withBatchSize(100)
    ->withTransactionMode(SqliteTransactionMode::Deferred)
    ->withExtendedResultCodes(true);
```

| Option | Default | When to change it |
| --- | --- | --- |
| Open mode | `ReadWriteCreate` | Use `ReadOnly` or `ReadWrite` when the application must not create the database. |
| Journal mode | `Automatic` | Choose an explicit rollback journal for filesystems that do not support WAL reliably. |
| Synchronous mode | `Automatic` | Use `Full` when commits must survive a power loss, or a less durable mode when performance matters more. |
| Foreign keys | Enabled | Disable only for databases that intentionally do not enforce foreign keys. |
| Busy timeout | 5,000 ms | Increase when writes may wait longer for another writer. |
| Trusted schema | Disabled | Enable only when trusted schema expressions must call application-defined functions. |
| Batch size | 100 rows | Increase to reduce round trips for large results, or decrease to reduce buffered data. |
| Transaction mode | `Deferred` | Use `Immediate` or `Exclusive` when a transaction should acquire a write lock when it begins. |
| Extended result codes | Enabled | Disable only when base SQLite result codes are sufficient. |

Writable file databases use WAL and `NORMAL` synchronous mode by default. This allows readers to continue while a writer transaction is open. A completed commit survives an application crash, but a power loss can roll back the most recent commit. Select `SqliteSynchronousMode::Full` when every commit must survive a power loss.

WAL requires filesystem locking and shared memory. Select a rollback journal such as `SqliteJournalMode::Delete` on network filesystems or other filesystems without reliable WAL support. `Off` journal or synchronous modes trade durability for speed and can corrupt a database after a crash.

Additional SQLite pragmas can be configured with `withPragma()`:

```php
$config = $config->withPragma('cache_size', -8_000);
```

Options with dedicated methods, such as `journal_mode`, `synchronous`, and `foreign_keys`, must be configured through those methods.

## Connections and pools

A single connection is a good fit for sequential work and is required for `:memory:` databases. Operations on one connection run one at a time.

Use `SqliteConnectionPool` when independent fibers need to query the same file database concurrently:

```php
use Fabpot\Amp\Sqlite\SqliteConnectionPool;

$pool = new SqliteConnectionPool(
    new SqliteConfig(__DIR__ . '/app.sqlite'),
    maxConnections: 10,
    idleTimeout: 60,
);

try {
    foreach ($pool->query('SELECT id, name FROM users') as $user) {
        // Process the user
    }
} finally {
    $pool->close();
}
```

SQLite still permits only one writer per database. Concurrent writers wait up to the configured busy timeout. Extra pooled connections mainly improve read concurrency, so keep the pool size moderate.

Pools cannot use `:memory:` because each connection would have a different database. An unread result, open BLOB stream, or active transaction keeps its pooled connection busy; exhaust or close these resources promptly.

## Running queries

Use `query()` for SQL without parameters and `execute()` for parameterized SQL:

```php
$database->query('CREATE INDEX users_name ON users (name)');

$result = $database->execute(
    'SELECT id, name FROM users WHERE name = :name',
    [':name' => 'Fabien'],
);
```

The remaining examples use `$database` for either a connection or a pool; both provide the same query API. Parameters may use SQLite's anonymous (`?`), numbered (`?NNN`), or named (`:name`, `@name`, and `$name`) forms. Parameter values must be `null`, `bool`, `int`, `float`, `string`, or `SqliteBlob`.

Each `query()` or `execute()` call accepts one SQL statement. Use `executeScript()` for parameterless schema or migration scripts:

```php
$database->executeScript(<<<'SQL'
    CREATE TABLE projects (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL
    );
    CREATE INDEX projects_name ON projects (name);
    SQL);
```

The complete script runs atomically. If one statement fails, every change made by the script is rolled back. Do not include transaction-control statements such as `BEGIN`, `COMMIT`, or `ROLLBACK`, or commands such as `VACUUM` that cannot run in a transaction.

SQLite statements that change data and return rows, including statements with `RETURNING`, are not supported. Run a separate query when you need to read the changed data.

## Reading results

Iterate over a result or fetch one row at a time:

```php
$result = $database->query('SELECT id, name FROM users ORDER BY id');

while ($user = $result->fetchRow()) {
    echo $user['name'] . "\n";
}
```

Column values are returned as `null`, `int`, `float`, `string`, or `SqliteBlob`. SQLite converts numeric column names to integer array keys.

Command results expose useful metadata:

```php
$result = $database->execute(
    'INSERT INTO users (name) VALUES (?)',
    ['Alice'],
);

$result->getRowCount();     // changed rows, including trigger changes
$result->getLastInsertId(); // inserted row ID, or null when no ID can be attributed
$result->getColumnCount();  // null for commands
$result->getColumnNames();  // null for commands
```

Always check `getLastInsertId()` for `null`. It only returns an ID when the statement unambiguously inserts into an ordinary rowid table; ignored inserts, views, virtual tables, `WITHOUT ROWID` tables, and the update branch of an UPSERT do not produce one.

Results are fetched in batches. When you stop reading before a result is exhausted, call `close()` so the connection can be used by another operation:

```php
$result->close();
```

## Prepared statements

Prepare statements that are executed repeatedly:

```php
$statement = $database->prepare('INSERT INTO users (name) VALUES (?)');

try {
    $statement->execute(['Fabien']);
    $statement->execute(['Alice']);
} finally {
    $statement->close();
}
```

Executing a statement again closes its previous unread result. A statement prepared through a transaction is closed when that transaction finishes and must not be reused afterward.

## Transactions

Use a transaction when several changes must succeed or fail together:

```php
$transaction = $database->beginTransaction();

try {
    $transaction->execute(
        'INSERT INTO users (name) VALUES (?)',
        ['Bob'],
    );
    $transaction->commit();
} catch (\Throwable $error) {
    $transaction->rollback();

    throw $error;
}
```

The configured transaction mode controls how SQLite begins top-level transactions. `Deferred` waits until the first read or write to acquire a lock. `Immediate` acquires a write reservation when the transaction begins. `Exclusive` requests the strongest locking mode available for the selected journal mode.

Nested transactions use SQLite savepoints:

```php
$transaction = $database->beginTransaction();
$transaction->execute('INSERT INTO users (name) VALUES (?)', ['kept']);

$nested = $transaction->beginTransaction();
$nested->execute('INSERT INTO users (name) VALUES (?)', ['discarded']);
$nested->rollback();

$transaction->commit();
```

Close unread results and BLOB streams before committing or rolling back. Use `onCommit()` and `onRollback()` to register callbacks that should run after the top-level outcome is known.

## BLOB values

Use `SqliteBlob` to store binary strings without treating them as text:

```php
use Fabpot\Amp\Sqlite\SqliteBlob;

$database->execute(
    'INSERT INTO files (contents) VALUES (?)',
    [new SqliteBlob($bytes)],
);
```

BLOB columns are returned as `SqliteBlob` objects. Call `getBytes()` to access their contents.

For large BLOBs, allocate the final size with SQLite's `zeroblob()` function and use `openBlob()` to write incrementally:

```php
use Fabpot\Amp\Sqlite\SqliteBlobMode;

$result = $database->query(
    'INSERT INTO files (contents) VALUES (zeroblob(1048576))',
);

$rowId = $result->getLastInsertId()
    ?? throw new \RuntimeException('SQLite did not report the inserted row ID.');

$blob = $database->openBlob(
    'files',
    'contents',
    $rowId,
    mode: SqliteBlobMode::ReadWrite,
);

try {
    while (($chunk = fread($source, 8192)) !== false && $chunk !== '') {
        $blob->write($chunk);
    }
} finally {
    $blob->close();
}
```

Incremental BLOBs can also be read as Amp byte streams:

```php
use function Amp\ByteStream\buffer;

$blob = $database->openBlob('files', 'contents', $rowId);

try {
    $bytes = buffer($blob);
} finally {
    $blob->close();
}
```

An incremental BLOB has a fixed length. Writing past that length fails. `openBlob()` reads from the `main` database in read-only mode by default; pass the optional database and mode arguments when needed. A BLOB opened through a transaction participates in that transaction and is rolled back with it.

## Custom SQL functions

Register named PHP functions or public static methods on the configuration:

```php
final class SqliteFunctions
{
    public static function reverse(string $value): string
    {
        return strrev($value);
    }
}

$config = (new SqliteConfig(__DIR__ . '/app.sqlite'))
    ->withFunction(
        'reverse',
        [SqliteFunctions::class, 'reverse'],
        argCount: 1,
        deterministic: true,
    );
```

The registered function can then be called from SQL:

```php
$row = $database->query("SELECT reverse('Amp') AS value")->fetchRow();
// ['value' => 'pmA']
```

Use `withAggregate()` for custom aggregates and `withCollation()` for custom sort orders. Callbacks must be named functions or public static methods that Composer can autoload; closures are not supported. Mark a function deterministic only when it always returns the same output for the same input.

Trusted schema is disabled by default, which prevents application-defined functions from being used in schema expressions such as indexes, generated columns, and constraints. Enable it only when the database schema is trusted and requires those functions.

## Backup and restore

Copy a database to or from a file with `backup()` and `restore()`:

```php
$database->backup(__DIR__ . '/snapshot.sqlite');

// Restore the snapshot later
$database->restore(__DIR__ . '/snapshot.sqlite');
```

`backup()` replaces the destination file and produces a consistent copy while other connections are writing. The destination must not be open as a database. `restore()` replaces the current database contents. Both methods also work with `:memory:` databases.

Run restores while the application has exclusive access when every concurrent operation must switch to the restored contents together.

## WAL checkpoints

SQLite checkpoints WAL databases automatically. To reclaim the WAL file immediately or checkpoint before copying database files manually, run the checkpoint pragma:

```php
$row = $database->query('PRAGMA wal_checkpoint(TRUNCATE)')->fetchRow();
// ['busy' => 0, 'log' => 0, 'checkpointed' => 0]
```

Use `backup()` instead of copying a live database file whenever possible.

## Error handling

Catch `SqliteExceptionInterface` when all driver errors should be handled together, or catch a more specific exception:

```php
use Fabpot\Amp\Sqlite\SqliteQueryError;

try {
    $database->execute(
        'INSERT INTO users (id, name) VALUES (1, ?)',
        ['Duplicate'],
    );
} catch (SqliteQueryError $error) {
    $error->getResultCode();         // 19 (SQLITE_CONSTRAINT)
    $error->getExtendedResultCode(); // 1555 (SQLITE_CONSTRAINT_PRIMARYKEY)
    $error->getQuery();              // the failed SQL
}
```

- `SqliteQueryError` reports SQL preparation and execution failures.
- `SqliteConnectionException` reports startup, connection, and pool failures.
- `SqliteTransactionError` reports operations on inactive or invalid transactions.
- `SqliteException` reports other SQLite operations, including backup, restore, BLOB I/O, and use of closed statements or results.

Invalid arguments and configuration combinations throw `TypeError` or `InvalidArgumentException`. Bound parameter values are not included in errors created by the driver. Exceptions thrown by custom SQL callbacks retain the callback's own message, which may contain sensitive values.
