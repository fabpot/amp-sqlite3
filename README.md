# AMPHP SQLite

An asynchronous SQLite driver for AMPHP. Every logical connection owns a dedicated child process and a persistent native `SQLite3` connection, so blocking SQLite operations do not block the event loop and connection-local state is preserved.

## Installation

```bash
composer require fabpot/amphp-sqlite
```

PHP 8.1 or newer, `ext-sqlite3`, and SQLite 3.31.0 or newer are required.

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

Positional placeholders use anonymous `?` placeholders and zero-based lists. Named placeholders use `:name` and require the exact prefixed key. Placeholder styles cannot be mixed.

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
