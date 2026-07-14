# AMPHP SQLite

An asynchronous SQLite driver for AMPHP. Every logical connection owns a dedicated child process and a persistent native `SQLite3` connection, so blocking SQLite operations do not block the event loop and connection-local state is preserved.

## Installation

```bash
composer require fabpot/amphp-sqlite
```

PHP 8.4 or newer, `ext-sqlite3`, and SQLite 3.31.0 or newer are required.

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

The driver accepts one SQL statement per operation. Empty SQL and multiple statements are rejected.

## Results

Rows are fetched from the child process in configured batches:

```php
$result = $connection->query('SELECT id, name FROM users ORDER BY id');

foreach ($result as $row) {
    echo $row['name'], "\n";
}
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

`SqliteBlobStream` implements AMPHP's `ReadableStream` and `WritableStream`. Its length is fixed when opened; writing past that length fails. An open BLOB owns its connection until it is closed, so always close it explicitly when abandoning a read or write.

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

Nested transactions use SQLite savepoints. Configure the top-level mode with `SqliteTransactionMode::Deferred`, `Immediate`, or `Exclusive`.
