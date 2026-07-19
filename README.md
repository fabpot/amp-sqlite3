# AMPHP SQLite

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

Writable file databases use WAL and `NORMAL` synchronous mode by default. Foreign keys are enabled and trusted schema is disabled. `:memory:` databases retain SQLite's memory journal behavior.

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
    ->withExtendedResultCodes(true)
    ->withPragma('cache_size', -8_000);
```

`SqliteConfig` is immutable; every `with*()` method returns a new instance. Invalid combinations (e.g. an explicit journal mode on a read-only database) are rejected. Pragmas with a dedicated option (`journal_mode`, `synchronous`, `foreign_keys`, `busy_timeout`, `trusted_schema`) cannot be set through `withPragma()`.

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

echo $result->getLastInsertId();
```

The driver accepts SQLite's native anonymous (`?`), numbered (`?NNN`), and named (`:name`, `@name`, and `$name`) parameters, including mixed forms. Integer array keys are zero-based; string keys are passed to `SQLite3Stmt::bindValue()` unchanged. Parameters that PHP cannot bind by name can be bound by position.

Parameter values must be `null`, `bool`, `int`, `float`, `string`, or `SqliteBlob`; anything else throws a `TypeError`. Booleans are bound as integers.

The driver accepts one SQL statement per operation. Empty SQL and multiple statements are rejected.

## Results

Rows are fetched from the child process in configured batches:

```php
$result = $connection->query('SELECT id, name FROM users ORDER BY id');

foreach ($result as $row) {
    echo $row['name'], "\n";
}
```

Row values keep their SQLite types: `null`, `int`, `float`, `string`, or `SqliteBlob`.

```php
$insert = $connection->execute('INSERT INTO users (name) VALUES (?)', ['Alice']);

$insert->getRowCount();     // changed rows, including trigger changes; 0 for DDL
$insert->getLastInsertId(); // last inserted row ID
$insert->getColumnCount();  // null for commands, column count for row-producing SQL
$insert->getColumnNames();  // null for commands, list of column names for row-producing SQL
```

An active row-producing result owns its connection until it is exhausted or closed. Close a result explicitly when abandoning unread rows:

```php
$result->close();
```

## Prepared statements

Prepared statements are retained and reused in the child process:

```php
$statement = $connection->prepare('INSERT INTO users (name) VALUES (?)');

$statement->execute(['Fabien']);
$statement->execute(['Alice']);
$statement->close();
```

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

$blob = $connection->openBlob(
    'files',
    'contents',
    $result->getLastInsertId(),
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

A BLOB's length is fixed when opened; writing past that length fails. An open BLOB owns its connection until it is closed, so always close it explicitly when abandoning a read or write. Transactions expose the same `openBlob()` method; BLOB writes made inside a transaction roll back with it.

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

Callables are validated when registered and resolved again in the child process through the Composer autoloader. Mark functions `deterministic` when they always return the same output for the same input; SQLite can then use them in indexes and optimize repeated calls.

## Backup and restore

`backup()` copies the entire database to a file using SQLite's online backup API, replacing any existing contents. `restore()` does the reverse. Both work for `:memory:` databases, which makes them the way to persist and reload an in-memory database:

```php
$connection->backup(__DIR__ . '/snapshot.sqlite');

// Later, or on another connection:
$connection->restore(__DIR__ . '/snapshot.sqlite');
```

A backup waits for the connection to be free, so it cannot run while a transaction is open on the same connection. Backing up a file database that other connections are writing to is safe: the backup API retries and produces a consistent copy.

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

Nested transactions use SQLite savepoints:

```php
$transaction = $connection->beginTransaction();
$transaction->execute('INSERT INTO users (name) VALUES (?)', ['kept']);

$nested = $transaction->beginTransaction();
$nested->execute('INSERT INTO users (name) VALUES (?)', ['discarded']);
$nested->rollback();

$transaction->commit();
```

Register lifecycle callbacks with `onCommit()` and `onRollback()`. Callbacks on a nested transaction run once the outcome is final, i.e. when the top-level transaction commits or rolls back.

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

- `SqliteQueryError`: SQL preparation and execution failures, with SQLite result codes.
- `SqliteConnectionException`: startup, IPC, and unexpected child-process failures.
- `SqliteTransactionError`: operations on finished transactions.
- All extend the corresponding `Amp\Sql` exceptions.

Exception messages and traces never contain bound parameter values.
