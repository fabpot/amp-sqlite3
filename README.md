# AMPHP SQLite3

An asynchronous SQLite driver for AMPHP. Every logical connection owns a dedicated child process and a persistent native `SQLite3` connection, so blocking SQLite operations do not block the event loop and connection-local state is preserved.

## Installation

```bash
composer require fabpot/amphp-sqlite3
```

PHP 8.4 or newer, `ext-sqlite3`, and SQLite 3.31.0 or newer are required.

Classes in the `Fabpot\Amp\Sqlite\Internal` namespace are not part of the public API and may change without notice.

## Connecting

```php
use Fabpot\Amp\Sqlite\SqliteConfig;
use Fabpot\Amp\Sqlite\SqliteConnector;

$config = (new SqliteConfig(__DIR__ . '/database.sqlite'))
    ->withBusyTimeout(5_000)
    ->withBatchSize(100);

$connection = (new SqliteConnector())->connect($config);
```

Writable file databases use WAL and `NORMAL` synchronous mode by default. This is safe against application crashes, but a power loss can roll back the most recent committed transaction. Select `SqliteSynchronousMode::Full` when every commit must survive a power loss. WAL creates `-wal` and `-shm` files and requires filesystem locking and shared memory, so select a rollback journal such as `SqliteJournalMode::Delete` on network filesystems or filesystems without reliable WAL support. `Off` journal or synchronous modes trade durability for speed and can corrupt a database after a crash.

When an explicit rollback journal mode is selected, automatic synchronous mode preserves SQLite's default. Foreign keys are enabled and trusted schema is disabled. `:memory:` databases retain SQLite's non-durable memory journal behavior.

Always close connections when they are no longer needed:

```php
try {
    // Use the connection.
} finally {
    $connection->close();
}
```

## Configuration

All options, with their defaults:

```php
use Fabpot\Amp\Sqlite\SqliteConfig;
use Fabpot\Amp\Sqlite\SqliteJournalMode;
use Fabpot\Amp\Sqlite\SqliteOpenMode;
use Fabpot\Amp\Sqlite\SqliteSynchronousMode;
use Fabpot\Amp\Sqlite\SqliteTransactionMode;

$config = (new SqliteConfig(__DIR__ . '/database.sqlite'))
    ->withOpenMode(SqliteOpenMode::ReadWriteCreate)
    ->withJournalMode(SqliteJournalMode::Automatic)         // WAL for writable files
    ->withSynchronousMode(SqliteSynchronousMode::Automatic) // NORMAL with WAL
    ->withForeignKeys(true)
    ->withBusyTimeout(5_000)                                // milliseconds
    ->withTrustedSchema(false)
    ->withBatchSize(100)                                    // rows fetched per IPC round trip
    ->withTransactionMode(SqliteTransactionMode::Deferred)
    ->withExtendedResultCodes(true);
```

Additional pragmas default to none and can be configured as needed, for example with `->withPragma('cache_size', -8_000)`. `SqliteConfig` is immutable; every `with*()` method returns a new instance. Invalid combinations (e.g. an explicit journal mode on a read-only database) are rejected. Pragmas with a dedicated option (`journal_mode`, `synchronous`, `foreign_keys`, `busy_timeout`, `trusted_schema`) cannot be set through `withPragma()`.

Connection pools use the configured transaction mode by default. Pass `transactionIsolation` to the pool constructor or call `setTransactionIsolation()` to override it. `Deferred`, `Immediate`, and `Exclusive` are SQLite `BEGIN` locking modes, not portable SQL isolation levels. Consequently, SQLite connections only accept `SqliteTransactionMode`; passing another `Amp\Sql\SqlTransactionIsolation` implementation to `setTransactionIsolation()` throws `InvalidArgumentException`. APIs declared directly with a `SqliteTransactionMode` parameter use PHP's normal `TypeError` behavior.

Use `getPath()` and `withPath()` to read or change the database path. The inherited `getDatabase()` and `withDatabase()` methods are supported aliases required by AMPHP's SQL configuration API, and their values are revalidated before connecting. The inherited host, port, user, and password options are not supported and cause `InvalidArgumentException` when connecting.

Relative paths are resolved against the current working directory of the parent process. SQLite URI filenames (`file:...`) are not supported.

To customize how the child process is started, inject an `Amp\Parallel\Context\ContextFactory` into `SqliteConnector`. The factory must create process contexts.

## Concurrency

A connection serializes its operations: concurrent fibers sharing one connection wait for each other. For parallelism, open multiple connections to the same file database. With WAL, readers never block and see a consistent snapshot while a writer transaction is open:

```php
$writer = (new SqliteConnector())->connect(new SqliteConfig($path));
$reader = (new SqliteConnector())->connect(new SqliteConfig($path));

$transaction = $writer->beginTransaction();
$transaction->execute('INSERT INTO events VALUES (?)', ['pending']);

// Runs immediately; sees the pre-transaction snapshot.
$reader->query('SELECT COUNT(*) FROM events');

$transaction->commit();
```

SQLite allows one writer per database at a time; concurrent writers wait up to the configured busy timeout.

### Connection pool

`SqliteConnectionPool` manages a set of connections to one file database and dispatches queries to idle ones, so concurrent fibers do not wait for each other:

```php
use Fabpot\Amp\Sqlite\SqliteConnectionPool;

$pool = new SqliteConnectionPool(new SqliteConfig($path), maxConnections: 10);

$result = $pool->query('SELECT ...'); // runs on an idle connection
$transaction = $pool->beginTransaction(); // owns its connection until committed or rolled back

$pool->close();
```

The pool implements `SqliteConnection`, so it is a drop-in replacement for a single connection. Prepared statements created on the pool transparently re-prepare on whichever connection executes them. Idle connections are closed after `idleTimeout` seconds (60 by default).

Pools reject `:memory:` databases, since every pooled connection would open a separate empty database. Keep `maxConnections` moderate: SQLite still allows only one writer at a time, so extra connections only help read concurrency.

## Queries

Use `query()` for SQL without parameters and `execute()` for parameterized SQL:

```php
$connection->query('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');

$result = $connection->execute(
    'INSERT INTO users (name) VALUES (:name)',
    [':name' => 'Fabien'],
);

$userId = $result->getLastInsertId()
    ?? throw new \RuntimeException('SQLite did not report the inserted row ID.');
```

The driver accepts SQLite's native anonymous (`?`), numbered (`?NNN`), and named (`:name`, `@name`, and `$name`) parameters, including mixed forms. Integer array keys are zero-based; string keys are passed to `SQLite3Stmt::bindValue()` unchanged. Parameters that PHP cannot bind by name can be bound by position. Placeholders that are not bound retain SQLite's native `NULL` value.

Parameter values must be `null`, `bool`, `int`, `float`, `string`, or `SqliteBlob`; anything else throws a `TypeError`. Booleans are bound as integers.

The driver accepts one SQL statement per `query()` or `execute()` operation. Empty SQL and multiple statements are rejected. Row-producing DML is also rejected before execution because PHP's `SQLite3` extension can execute it twice while fetching result rows. This includes DML with a `RETURNING` clause and DML made row-producing by `PRAGMA count_changes`.

Use `executeScript()` for parameterless schema or migration scripts containing multiple statements:

```php
$connection->executeScript(<<<'SQL'
    CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL);
    CREATE INDEX users_name ON users (name);
    SQL);
```

The complete script executes atomically using the connection's configured transaction mode. A failed statement rolls back the entire script. Transaction-control statements such as `BEGIN`, `COMMIT`, and `ROLLBACK` are not supported inside scripts, nor are statements such as `VACUUM` that SQLite cannot execute in a transaction.

## Results

Rows are fetched from the child process in configured batches:

```php
$result = $connection->query('SELECT id, name FROM users ORDER BY id');

foreach ($result as $row) {
    echo $row['name'] . "\n";
}
```

Row values keep their SQLite types: `null`, `int`, `float`, `string`, or `SqliteBlob`.

```php
$insert = $connection->execute('INSERT INTO users (name) VALUES (?)', ['Alice']);

$insert->getRowCount();     // changed rows, including trigger changes; 0 for DDL; null for row-producing SQL
$insert->getLastInsertId(); // inserted row ID when unambiguous; otherwise null
$insert->getColumnCount();  // null for commands, column count for row-producing SQL
$insert->getColumnNames();  // null for commands, list of column names for row-producing SQL
```

`getLastInsertId()` is result-specific rather than a direct exposure of SQLite's connection-global `last_insert_rowid()`. It returns the final row ID for an unambiguous insert into an ordinary rowid table. It returns `null` for non-insert statements, ignored inserts, `WITHOUT ROWID` tables, views, virtual tables, the update branch of an UPSERT, and any other ambiguous case.

SQLite converts numeric column names to integer PHP array keys; other column names remain strings.

An active row-producing result owns its connection until it is exhausted or closed. Close a result explicitly when abandoning unread rows:

```php
$result->close();
```

After natural exhaustion, `fetchRow()` continues returning `null`. Calling it after an explicit `close()` throws `SqliteException`, for both direct and pooled results.

## Prepared statements

Prepared statements are retained and reused in the child process:

```php
$statement = $connection->prepare('INSERT INTO users (name) VALUES (?)');

$statement->execute(['Fabien']);
$statement->execute(['Alice']);
$statement->close();
```

Executing a statement again closes its previous unread result. A statement prepared through a transaction is scoped to that transaction and closes automatically when the transaction commits, rolls back, or loses its connection. Statements prepared directly on a connection or pool remain reusable until explicitly closed or their owner closes.

## BLOB values

Use `SqliteBlob` to distinguish binary data from text:

```php
use Fabpot\Amp\Sqlite\SqliteBlob;

$connection->execute(
    'INSERT INTO files (contents) VALUES (?)',
    [new SqliteBlob($bytes)],
);
```

BLOB columns are returned as `SqliteBlob` instances.

For large BLOBs, use incremental I/O after allocating the desired size with SQLite's `zeroblob()` function:

```php
use Fabpot\Amp\Sqlite\SqliteBlobMode;

$result = $connection->query(
    'INSERT INTO files (contents) VALUES (zeroblob(1048576))',
);

$rowId = $result->getLastInsertId()
    ?? throw new \RuntimeException('SQLite did not report the inserted row ID.');

$blob = $connection->openBlob(
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

Reading is incremental too; `SqliteBlobStream` implements AMPHP's `ReadableStream` and `WritableStream`:

```php
use function Amp\ByteStream\buffer;

$blob = $connection->openBlob('files', 'contents', $rowId);

foreach ($blob as $chunk) {
    // Process 8 KiB chunks.
}

// Or read everything at once:
$bytes = buffer($connection->openBlob('files', 'contents', $rowId));
```

A BLOB's length is fixed when opened; writing past that length fails. `openBlob()` defaults to the `main` database and read-only mode; pass its `database` and `mode` arguments for attached databases or writes. Only one `read()` may be pending at a time, as required by AMPHP's stream contract. A concurrent read throws `PendingReadError`. If cancellation arrives after an IPC read was sent, the response is drained, the BLOB is closed to keep its position consistent, and `CancelledException` is thrown.

An open BLOB owns its connection until it is closed, so always close it explicitly when abandoning a read or write. Transactions expose the same `openBlob()` method; BLOB writes made inside a transaction roll back with it.

## Custom functions, aggregates, and collations

Register custom SQL callables on the configuration. Because they run in the child process, callbacks must be named functions (`'strrev'`) or public static methods (`[SqlFunctions::class, 'slugify']`); closures are not supported:

```php
final class SqlFunctions
{
    public static function slugify(string $value): string { /* ... */ }

    public static function longestStep(?string $context, int $rowNumber, string $value): string { /* ... */ }

    public static function longestFinal(?string $context, int $rowCount): ?string { /* ... */ }

    public static function compareNaturally(string $a, string $b): int { /* ... */ }
}

$config = (new SqliteConfig($path))
    ->withFunction('slug', [SqlFunctions::class, 'slugify'], argCount: 1, deterministic: true)
    ->withAggregate('longest', [SqlFunctions::class, 'longestStep'], [SqlFunctions::class, 'longestFinal'], argCount: 1)
    ->withCollation('natural', [SqlFunctions::class, 'compareNaturally']);

$connection = (new SqliteConnector())->connect($config);

$connection->query("SELECT slug(title) FROM posts ORDER BY title COLLATE natural");
```

Callables are validated when registered and resolved again in the child process through the Composer autoloader. Mark functions `deterministic` when they always return the same output for the same input so SQLite can optimize repeated calls. The default `trusted_schema=false` prevents application-defined functions from being used in schema expressions such as indexes, generated columns, or constraints. Explicitly enable `withTrustedSchema(true)` only when the database schema is trusted and such use is required.

## Backup and restore

`backup()` copies the entire database to a file using SQLite's online backup API, replacing any existing contents. `restore()` does the reverse. Both default to the `main` database; pass their optional `database` argument to copy an attached database instead. Both work for `:memory:` databases, which makes them the way to persist and reload an in-memory database:

```php
$connection->backup(__DIR__ . '/snapshot.sqlite');

// Later, or on another connection:
$connection->restore(__DIR__ . '/snapshot.sqlite');
```

A backup waits for the connection to be free, so it cannot run while a transaction is open on the same connection. Backing up a file database that other connections are writing to is safe: the backup API retries and produces a consistent copy.

On a pool, `backup()` and `restore()` run on one acquired connection. A transaction that was already active on another pooled connection retains its existing SQLite snapshot; later pool operations see the restored database. Perform restores while the application has exclusive ownership if every concurrent operation must switch snapshots together.

## WAL checkpoints

WAL checkpoints need no dedicated API; run the pragma directly:

```php
$row = $connection->query('PRAGMA wal_checkpoint(TRUNCATE)')->fetchRow();
// ['busy' => 0, 'log' => 0, 'checkpointed' => 0]
```

SQLite checkpoints automatically when the WAL reaches 1000 pages; an explicit `TRUNCATE` checkpoint is useful before backups or to bound WAL file size on write-heavy workloads.

## Transactions

```php
$transaction = $connection->beginTransaction();

try {
    $transaction->execute('INSERT INTO users (name) VALUES (?)', ['Bob']);
    $transaction->commit();
} catch (Throwable $error) {
    $transaction->rollback();
    throw $error;
}
```

A transaction owns its connection until committed or rolled back. An abandoned transaction is rolled back automatically. Configure the top-level mode with `SqliteTransactionMode::Deferred`, `Immediate`, or `Exclusive`.

Close any unread results and open BLOB streams before committing or rolling back: these own the transaction's connection while active, so `commit()` and `rollback()` wait for them. Prepared statements do not block completion; they close automatically when their transaction finishes.

Nested transactions use SQLite savepoints:

```php
$transaction = $connection->beginTransaction();
$transaction->execute('INSERT INTO users (name) VALUES (?)', ['kept']);

$nested = $transaction->beginTransaction();
$nested->execute('INSERT INTO users (name) VALUES (?)', ['discarded']);
$nested->rollback();

$transaction->commit();
```

Register lifecycle callbacks with `onCommit()` and `onRollback()`. Committing a nested transaction defers its commit or rollback callback until the top-level outcome is known. Rolling back a nested transaction is already final, so its rollback callbacks run immediately.

## Upgrading from 0.2

Version 1.0 freezes several contracts that were implicit or inconsistent in the preview releases:

- `query()` and `execute()` accept exactly one statement. Use atomic `executeScript()` for multi-statement, parameterless scripts. Row-producing DML, including `RETURNING`, is rejected before execution.
- `getLastInsertId()` now returns `?int` and only reports an ID attributable to that result; check for `null` before using it.
- Results and statements throw `SqliteException` after explicit closure. Direct and pooled results now behave identically.
- Statements prepared through a transaction close when that transaction finishes. Do not retain them for later execution.
- Closed pools and queued operations interrupted by pool closure throw `SqliteConnectionException`.
- `SqliteQueryError` result-code getters now return `?int`. Non-SQL SQLite operations use `SqliteException` instead of `SqliteQueryError`.
- SQLite transaction getters return `SqliteTransactionMode`; `setTransactionIsolation()` rejects other AMPHP isolation implementations with `InvalidArgumentException`.
- Prefer `SqliteConfig::getPath()` and `withPath()`. The former public `validatePath()` helper was implementation detail and has been removed. Invalid configuration combinations now consistently throw `InvalidArgumentException`.
- SQLite result rows contain `null`, `int`, `float`, `string`, or `SqliteBlob`; numeric column names become integer PHP keys.

Review the WAL durability and filesystem requirements, trusted-schema behavior, and callback secrecy caveat above before deploying the new defaults in an existing application.

## Errors

```php
use Fabpot\Amp\Sqlite\SqliteQueryError;

try {
    $connection->execute('INSERT INTO users (id, name) VALUES (1, ?)', ['Dup']);
} catch (SqliteQueryError $error) {
    $error->getResultCode();         // 19 (SQLITE_CONSTRAINT)
    $error->getExtendedResultCode(); // 1555 (SQLITE_CONSTRAINT_PRIMARYKEY)
    $error->getQuery();              // the failed SQL
}
```

- `SqliteQueryError`: SQL preparation and execution failures. `getResultCode()` and `getExtendedResultCode()` return integers for native SQLite failures and `null` for driver or callback failures without native codes.
- `SqliteConnectionException`: startup, IPC, closed-pool, and unexpected child-process failures.
- `SqliteTransactionError`: operations on finished transactions or invalid transaction state.
- `SqliteException`: non-SQL SQLite operations such as backup, restore, and BLOB I/O, as well as operations on closed statements or results.
- Invalid public arguments and configuration combinations throw `TypeError` or `InvalidArgumentException`.
- All SQLite domain errors implement `SqliteExceptionInterface` and extend the corresponding `Amp\Sql` errors or exceptions.

The driver never includes bound parameter values in errors it creates. Custom SQL functions, aggregates, and collations execute application code, so an exception message created by a callback can expose the callback arguments.
