# RFP 0001: Initial AMPHP SQLite Driver

- Status: Proposed
- Package: `fabpot/amphp-sqlite`
- Namespace: `Fabpot\Amp\Sqlite`
- License: MIT

## Summary

This RFP proposes an asynchronous SQLite driver for the AMPHP ecosystem. The driver implements the `amphp/sql` interfaces while executing all blocking `ext-sqlite3` operations in a dedicated child process.

Each logical connection owns one child process and one persistent native `SQLite3` connection. This affinity preserves SQLite connection state, including transactions, prepared statements, temporary tables, pragmas, result cursors, and `:memory:` databases.

The initial release focuses on the standard AMPHP SQL surface. It includes reusable prepared statements, batched result streaming, nested transactions, explicit BLOB values, and modern SQLite defaults. It does not include a connection pool or advanced SQLite extension APIs.

## Motivation

`ext-sqlite3` is synchronous. Calling it in the event-loop process blocks every fiber until the operation completes. Moving each operation into an arbitrary worker avoids blocking, but does not preserve SQLite semantics because many SQLite resources belong to a specific native connection.

A correct asynchronous driver must preserve these invariants:

1. All operations on a logical connection use the same native connection.
2. Native prepared statements and result cursors remain in the process that created them.
3. Transaction operations execute on the connection that began the transaction.
4. A `:memory:` database remains available for the lifetime of its logical connection.
5. Slow SQLite operations do not block the parent event loop.
6. Result streaming has bounded parent and child memory usage.

Existing community implementations provide useful prior art, but their execution models either target Amp 2 or open new native connections across logical operations. The implementation described here therefore starts from a clean architecture while following current AMPHP package conventions.

## Goals

The initial release will:

- Implement the `amphp/sql` 2.x connection, executor, result, statement, and transaction contracts.
- Use `amphp/parallel` 2.x to run blocking SQLite work outside the event-loop process.
- Preserve native connection affinity for all connection-scoped state.
- Support Linux, macOS, and Windows without requiring `ext-pcntl`.
- Support file-backed and `:memory:` databases.
- Stream row-producing results in configurable batches.
- Support reusable native prepared statements.
- Support top-level and nested transactions.
- Preserve SQLite value types, including distinguishing BLOB values from text.
- Provide secure, opinionated defaults suitable for new applications.
- Follow AMPHP conventions for public interfaces, generics, resource lifecycles, coding style, static analysis, tests, and CI.

## Non-goals

The initial release will not provide:

- A connection pool.
- Global connector state or convenience functions.
- PDO or interchangeable SQLite backends.
- Multiple SQL statements in a single call.
- Multiple result sets.
- Per-query cancellation APIs.
- SQLite URI filenames.
- Online backup APIs.
- Incremental BLOB I/O.
- Custom SQL functions or aggregates.
- Custom collations.
- Authorizers.
- Runtime extension loading.
- Manual WAL checkpoint APIs.
- Helpers for `ATTACH`, although applications may execute `ATTACH` directly.
- Automatic reconnection after child-process failure.
- Automatic conversion to booleans, dates, JSON, or application types.
- SQLite-specific column metadata beyond column count and associative row keys.

## Requirements

The package requires:

- PHP 8.1 or newer.
- SQLite 3.31.0 or newer.
- `ext-sqlite3`.
- `amphp/amp` 3.x.
- `amphp/parallel` 2.x.
- `amphp/sql` 2.x.
- `amphp/sql-common` 2.x.

The native SQLite version is checked in the child process during connection startup. Connection creation fails if the version is too old.

`pdo_sqlite` is neither required nor used.

## Public API

The package uses the `Fabpot\Amp\Sqlite` namespace. It follows AMPHP SQL package conventions by exposing database-specific interfaces with covariant return types and keeping process-backed implementations internal.

The expected public interfaces are:

- `SqliteExecutor`
- `SqliteLink`
- `SqliteConnection`
- `SqliteStatement`
- `SqliteResult`
- `SqliteTransaction`

The expected public classes and enums are:

- `SqliteConnector`
- `SqliteConfig`
- `SqliteBlob`
- `SqliteOpenMode`
- `SqliteJournalMode`
- `SqliteSynchronousMode`
- `SqliteTransactionMode`
- `SqliteException`
- `SqliteConnectionException`
- `SqliteQueryError`
- `SqliteTransactionError`

Concrete connection, statement, result, transaction, protocol, and worker classes remain under `Internal`.

### Class-only connection API

Connection creation is available only through `SqliteConnector`. The package does not autoload functions, provide a global connector, or expose a static connection factory.

```php
use Fabpot\Amp\Sqlite\SqliteConfig;
use Fabpot\Amp\Sqlite\SqliteConnector;

$config = new SqliteConfig(__DIR__ . '/database.sqlite');
$connector = new SqliteConnector();
$connection = $connector->connect($config);

try {
    $result = $connection->execute(
        'SELECT * FROM users WHERE id = :id',
        [':id' => 42],
    );

    foreach ($result as $row) {
        printf("%s\n", $row['name']);
    }
} finally {
    $connection->close();
}
```

`SqliteConnector` implements `Amp\Sql\SqlConnector`. Its contract-level argument is therefore `SqlConfig`, but it accepts only `SqliteConfig` instances and rejects other configurations with a `TypeError`. This matches the runtime type check used by the AMPHP MySQL and PostgreSQL connectors.

### Interfaces

`SqliteExecutor` extends `Amp\Sql\SqlExecutor` and narrows result and statement return types.

`SqliteLink` extends `Amp\Sql\SqlLink` and `SqliteExecutor`.

`SqliteConnection` extends `Amp\Sql\SqlConnection` and `SqliteLink`. `getConfig()` returns `SqliteConfig`.

`SqliteStatement` extends `Amp\Sql\SqlStatement` and returns `SqliteResult` from `execute()`.

`SqliteResult` extends `Amp\Sql\SqlResult` and `Amp\Closable`. It adds:

```php
public function getLastInsertId(): int;
```

`SqliteTransaction` extends `Amp\Sql\SqlTransaction` and `SqliteLink`.

### Connector injection

`SqliteConnector` accepts an `Amp\Parallel\Context\ContextFactory` and defaults to `ProcessContextFactory`. Any injected factory must create a distinct process context. A context that does not provide process isolation is rejected.

This permits custom PHP binaries, process environments, and application-specific process startup without relying on AMPHP's global context factory.

## Configuration

`SqliteConfig` extends `Amp\Sql\SqlConfig` and is immutable. Its constructor requires a database path. Additional settings are changed through cloning `with*()` methods.

```php
use Fabpot\Amp\Sqlite\SqliteConfig;
use Fabpot\Amp\Sqlite\SqliteJournalMode;
use Fabpot\Amp\Sqlite\SqliteOpenMode;

$config = (new SqliteConfig(__DIR__ . '/database.sqlite'))
    ->withOpenMode(SqliteOpenMode::ReadWriteCreate)
    ->withBusyTimeout(5_000)
    ->withBatchSize(250)
    ->withForeignKeys(true)
    ->withJournalMode(SqliteJournalMode::Automatic)
    ->withPragma('cache_size', -8_000);
```

Configuration is validated when it is created or cloned.

### Inherited generic settings

`Amp\Sql\SqlConfig` models host-based databases and exposes final `getHost()`, `getPort()`, `getUser()`, `getPassword()`, and `getDatabase()` accessors with matching `with*()` methods. SQLite has no server, so `SqliteConfig` fixes the parent state at construction: an empty host, port `0`, and `null` user and password.

The database path is stored as the parent database value. `getDatabase()` therefore returns the configured path, and `withDatabase()` changes it.

The inherited `with*()` methods are declared `final` upstream, so `SqliteConfig` cannot make them throw when they are called. Invalid mutations are rejected at connection time instead:

- `SqliteConnector::connect()` throws a `ValueError` when the host, port, user, or password differ from the fixed construction values, which can only result from calling `withHost()`, `withPort()`, `withUser()`, or `withPassword()`.
- `withDatabase()` bypasses SQLite-specific path validation at call time, so `SqliteConnector::connect()` re-validates the effective path with the same rules as the constructor before starting the child process.

### Open modes

`SqliteOpenMode` provides exactly these modes:

- `ReadOnly`
- `ReadWrite`
- `ReadWriteCreate`

`ReadWriteCreate` is the default. Missing database files are created. Missing parent directories are not created.

The driver translates the enum into native `SQLITE3_OPEN_*` flags. Raw bitmasks are not part of the public API.

### Paths

`:memory:` is passed through unchanged.

A relative file path is resolved against the parent process's current working directory before the child starts. The target file does not need to exist. The public configuration retains the user-provided path, while the resolved path is an internal connection detail.

SQLite URI filenames are rejected in the initial release.

### Modern defaults

The default configuration is:

- Open mode: `ReadWriteCreate`
- Journal mode: `Automatic`
- Synchronous mode: `Automatic`
- Foreign-key enforcement: enabled
- Busy timeout: 5,000 milliseconds
- Trusted schema: disabled
- Result batch size: 100 rows
- Transaction mode: `Deferred`
- Extended result codes: enabled

`Automatic` journal mode behaves as follows:

- Writable file-backed databases must use WAL.
- `:memory:` databases retain SQLite's memory journal behavior.
- Read-only databases retain their existing journal mode.

Automatic WAL activation is verified. If SQLite cannot activate WAL, connection creation fails. The driver does not silently fall back to a rollback journal. Applications using an incompatible filesystem must explicitly select another journal mode.

`SqliteJournalMode` also exposes explicit SQLite modes such as `Wal`, `Delete`, `Truncate`, `Persist`, `Memory`, and `Off`. Configuration rejects mode and open-mode combinations that cannot work.

`Automatic` synchronous mode selects `NORMAL` when WAL is selected for a writable database. Otherwise, it preserves SQLite's applicable default. Explicit synchronous modes remain available.

The driver calls `SQLite3::enableExtendedResultCodes()` and applies `PRAGMA trusted_schema = OFF` during startup.

The process-wide `sqlite3.defensive` INI setting is not part of the driver contract. Enabling it in the PHP environment is recommended.

### Busy timeout

Busy timeout is expressed as a non-negative integer number of milliseconds and maps directly to `SQLite3::busyTimeout()`.

A value of zero disables waiting. Negative values are rejected.

### Batch size

Batch size is a positive integer number of rows. It is configured per connection, not per query or result.

The default is 100 rows. The limit bounds row prefetching, but not total bytes. A single large row or BLOB must still fit in one IPC message.

### Additional startup pragmas

`SqliteConfig` accepts additional startup pragmas for settings that do not warrant dedicated API methods, such as `cache_size`, `temp_store`, and `mmap_size`.

Pragma names must match `[A-Za-z_][A-Za-z0-9_]*`. Values are limited to `null`, `bool`, `int`, `float`, and `string`. Strings are always encoded as quoted values. The API never accepts SQL fragments as pragma names or values.

These typed settings are reserved and cannot also be supplied as additional pragmas:

- `journal_mode`
- `synchronous`
- `foreign_keys`
- `trusted_schema`
- `busy_timeout`

Driver-managed settings that the selected configuration actively sets are applied and read back before the connection is returned. An `Automatic` mode that preserves an existing SQLite value is not compared against a requested value. A mismatch for an actively set value fails connection creation and reports the requested and effective values. Additional pragmas fail startup when execution fails and are read back when they produce a comparable scalar value.

## Execution architecture

### Dedicated child process

Every `SqliteConnection` owns exactly one long-lived child process created through `amphp/parallel`.

The child process owns:

- One native `SQLite3` connection.
- All native `SQLite3Stmt` objects for the logical connection.
- All native `SQLite3Result` objects for the logical connection.
- The transaction and savepoint state of the connection.
- Integer resource identifiers used by the parent process.

The parent process never calls a blocking `ext-sqlite3` operation.

The child runs a command loop over an AMPHP channel. Requests and responses are serializable value objects or arrays. Native SQLite resources never cross the process boundary.

The process lifetime is the connection lifetime. Closing the connection closes the IPC channel and terminates the process after orderly cleanup. If orderly cleanup cannot proceed because SQLite is blocked, closing the context kills the process.

The driver always uses process contexts. It does not automatically select thread contexts, even when `ext-parallel` is installed.

### Startup handshake

Connection startup performs these steps in the child:

1. Verify `ext-sqlite3` is loaded.
2. Verify the native SQLite version.
3. Open the native connection with the resolved path and configured mode.
4. Enable exceptions and extended result codes.
5. Apply the busy timeout and typed startup settings.
6. Apply additional startup pragmas.
7. Read back and verify managed settings.
8. Return a ready response to the parent.

Any failure closes the native connection, terminates the child, and causes `SqliteConnector::connect()` to throw `SqliteConnectionException`.

The `Cancellation` passed to `SqliteConnector::connect()` applies to process startup and the startup handshake. Cancellation terminates the child and releases all startup resources.

### Internal protocol

The protocol supports operations equivalent to:

- Open connection
- Execute direct query
- Prepare statement
- Execute temporary statement
- Execute retained statement
- Fetch result batch
- Close result
- Close statement
- Begin transaction
- Commit transaction
- Roll back transaction
- Create savepoint
- Release savepoint
- Roll back to savepoint
- Close connection

Statements and row-producing results are referenced by monotonically assigned integer IDs. Closing a result or statement is idempotent in both processes: a child-side close request for an ID that was allocated with the same resource type and has already been released is a benign no-op. A close request for a never-allocated or mismatched ID remains a protocol error. The parent does not send cleanup requests after connection-level invalidation. Every non-close operation using an unknown, stale, or mismatched ID is also a protocol error and closes the logical connection.

Every response identifies the request it completes. The initial implementation serializes operations and allows only one in-flight request, but request identity keeps protocol failures detectable and leaves room for internal evolution.

### Operation queue

Operations on one connection are queued in invocation order.

Only one SQLite operation is active at a time. A row-producing result retains ownership of the connection while it can request more batches. Other queries, preparations, statement executions, and transaction operations wait until that result is exhausted or explicitly closed. Calling another operation from the same fiber before consuming or closing its result would wait indefinitely, so this usage is explicitly unsupported and documented.

Closing the connection fails the active and queued operations. Reentrant calls receive no special treatment and follow the same queue.

Applications needing actual SQLite concurrency must create multiple connections explicitly. Connection pooling is outside the initial scope.

### Unexpected process termination

If the child exits unexpectedly, the driver:

- Permanently marks the logical connection closed.
- Fails the active and queued operations with `SqliteConnectionException`.
- Invalidates all statements, results, and transactions associated with the connection.
- Runs close and rollback callbacks exactly once where applicable.
- Does not reconnect or replace the process.

A replacement process would create a new SQLite session and could not preserve transactions, temporary tables, pragmas, prepared statements, cursors, or `:memory:` contents.

## SQL execution

### Single-statement rule

`query()`, `execute()`, and `prepare()` accept exactly one SQL statement. Empty, whitespace-only, comment-only, and semicolon-only input is rejected with `SqliteQueryError`. Trailing whitespace and comments are allowed. A second statement is rejected with `SqliteQueryError`.

The child prepares the SQL with SQLite and uses `SQLite3Stmt::getSQL()` to determine how much input the native parser consumed. Any remaining input must contain only whitespace or comments. This supports valid compound statements such as trigger definitions without implementing a partial SQL parser, while still rejecting a second statement. Phase 3 must verify this consumed-prefix behavior across supported PHP versions with both the minimum and current SQLite versions before the implementation relies on it; if it is not stable, the implementation must use another SQLite-aware boundary mechanism without weakening the single-statement rule.

Multiple-result behavior is not supported. `SqliteResult::getNextResult()` always returns `null`.

### `query()`

`query(string $sql)` executes a statement without bound parameters. It rejects statements with native SQLite parameters rather than allowing SQLite to execute with unbound values.

It may return either a row-producing result or a command result.

### `execute()`

`execute(string $sql, array $params = [])` prepares and executes a temporary native statement, even when the parameter array is empty.

The temporary native statement remains alive while its streamed result is active and is released when the result closes. A command result releases it immediately.

### `prepare()`

`prepare(string $sql)` creates and retains one native `SQLite3Stmt` in the child. The returned `SqliteStatement` refers to it by ID.

A prepared statement is reusable. Each execution:

1. Waits until the statement and connection are available.
2. Resets the native statement.
3. Clears previous bindings.
4. Validates and binds the supplied values.
5. Executes the native statement.
6. Keeps the statement occupied until its result is exhausted or closed.

Concurrent executions of the same statement queue in invocation order. Closing a statement with an active result closes the result first and then releases the native statement.

Closing a statement is idempotent. Executing a closed statement fails clearly.

### Parameters

The driver delegates parameter syntax and binding to SQLite through `SQLite3Stmt::paramCount()` and `SQLite3Stmt::bindValue()`. It supports SQLite's native anonymous (`?`), numbered (`?NNN`), and named (`:name`, `@name`, and `$name`) parameters, including statements that mix forms.

Integer array keys are zero-based and bind to SQLite positions beginning at 1. String keys are passed to `SQLite3Stmt::bindValue()` unchanged. PHP accepts `:name` with or without its prefix and accepts `@name` with its prefix; parameters unsupported by PHP's string binding, including `$name`, can be bound by position. Repeated named parameters occupy one SQLite binding position.

The supplied parameter count must equal `SQLite3Stmt::paramCount()`. Every binding must be accepted by SQLite. Missing parameters, extra parameters, and invalid names or positions are rejected before execution. The driver does not parse SQL to discover parameters.

Accepted values and native bindings are:

| PHP value | SQLite binding |
| --- | --- |
| `null` | `SQLITE3_NULL` |
| `bool` | `SQLITE3_INTEGER` |
| `int` | `SQLITE3_INTEGER` |
| `float` | `SQLITE3_FLOAT` |
| `string` | `SQLITE3_TEXT` |
| `SqliteBlob` | `SQLITE3_BLOB` |

Other values, including general `Stringable` objects, are rejected with `TypeError`. Applications must convert domain values explicitly.

## BLOB values

PHP strings cannot distinguish SQLite `TEXT` from `BLOB`. `SqliteBlob` preserves that distinction across binding, result streaming, and reuse as a later parameter.

```php
use Fabpot\Amp\Sqlite\SqliteBlob;

$statement = $connection->prepare(
    'INSERT INTO files (name, contents) VALUES (:name, :contents)',
);

$statement->execute([
    ':name' => 'document.pdf',
    ':contents' => new SqliteBlob($bytes),
]);
```

`SqliteBlob` is immutable and serializable. It exposes raw bytes through `getBytes(): string` and their length through `getLength(): int`. It does not implement `Stringable` and does not perform implicit text conversion.

Returned row values preserve native SQLite runtime types:

| SQLite value | PHP value |
| --- | --- |
| `INTEGER` | `int` |
| `REAL` | `float` |
| `TEXT` | `string` |
| `BLOB` | `SqliteBlob` |
| `NULL` | `null` |

No conversion is based on a column's declared type.

## Result streaming

### Batched protocol

A row-producing statement leaves its native `SQLite3Result` in the child and returns an opaque result ID plus result metadata.

The parent requests up to the configured number of rows at a time. A batch response contains:

- A list of associative rows.
- Whether the native cursor is exhausted.

`fetchRow()` consumes rows from the current local batch and requests another batch only when necessary. Iteration uses the same path.

When the final batch is received, the result closes automatically, releases its native cursor and statement lease, and allows the next queued operation to run.

### Explicit closure

`SqliteResult` is closable even though the generic `SqlResult` contract is not. Calling `close()`:

- Discards unread rows.
- Releases the worker-side cursor.
- Releases any temporary statement.
- Makes the connection available to the next queued operation.
- Is idempotent.

Destroying an unconsumed result schedules best-effort asynchronous closure. Explicit closure is the deterministic way to abandon a result.

Fetching or iterating after closure fails clearly.

### Result metadata

For row-producing statements:

- `getRowCount()` returns `null`, including after exhaustion.
- `getColumnCount()` returns the number of result columns.

For command statements:

- `getRowCount()` returns the difference in SQLite's `total_changes()` value immediately before and after execution.
- `getColumnCount()` returns `null`.

The delta is zero for DDL instead of leaking a stale count from an earlier DML statement. It includes rows changed by triggers, matching SQLite's native `total_changes()` semantics.

`getLastInsertId()` always returns the value of `SQLite3::lastInsertRowID()` captured immediately after the statement executes. It returns an integer, including `0` when no insert has occurred on the connection. This is SQLite connection state captured after the statement, not a guarantee that the statement itself inserted a row.

`getNextResult()` always returns `null`.

## Transactions

### Transaction modes

SQLite transaction modes do not match standard SQL isolation levels. `SqliteTransactionMode` implements `Amp\Sql\SqlTransactionIsolation` and provides:

- `Deferred`, the default
- `Immediate`
- `Exclusive`

The connection's `setTransactionIsolation()` accepts only `SqliteTransactionMode`. Other `SqlTransactionIsolation` implementations are rejected.

A top-level transaction starts with the corresponding `BEGIN DEFERRED`, `BEGIN IMMEDIATE`, or `BEGIN EXCLUSIVE` statement.

### Nested transactions

Nested transactions use savepoints:

- Begin: `SAVEPOINT <generated identifier>`
- Commit: `RELEASE SAVEPOINT <identifier>`
- Rollback: `ROLLBACK TO SAVEPOINT <identifier>`, followed by release

Identifiers are generated internally and never include user input.

While a nested transaction is active, its parent waits before performing another operation.

### Lifecycle

Transaction lifecycle behavior is built on `amphp/sql-common`, including:

- Active and closed state.
- Nested transaction ownership.
- Commit and rollback callbacks.
- Waiting for nested transactions and active results.
- Automatic rollback of abandoned active transactions.

Calling `close()` on an active transaction rolls it back. Destroying an active transaction schedules rollback on the event loop. The connection remains unavailable until rollback cleanup completes.

If the child has already died, local resources close and rollback callbacks still run, but no native rollback can occur.

## Cancellation

The initial release supports cancellation only during connection creation through `SqliteConnector::connect()`.

The standard `amphp/sql` query and statement APIs do not accept a `Cancellation`, and an arbitrary blocking SQLite call cannot be interrupted through the same command channel while the child is executing it. The package therefore does not expose driver-specific cancellable query methods initially.

Lock contention is bounded by the configured busy timeout. Calling `SqliteConnection::close()` terminates the child if necessary, aborting active work and invalidating all resources.

A later release may add cooperative interruption only if it can provide reliable semantics through a separate control mechanism.

## Errors

Errors map onto the AMPHP SQL hierarchy:

- `SqliteQueryError` extends `Amp\Sql\SqlQueryError` for preparation, constraint, locking, type, and execution failures.
- `SqliteConnectionException` extends `Amp\Sql\SqlConnectionException` for startup, IPC, and unexpected process failures.
- `SqliteException` extends `Amp\Sql\SqlException` for other driver failures.
- `SqliteTransactionError` extends `Amp\Sql\SqlTransactionError` for invalid transaction lifecycle operations.

SQLite-originated exceptions expose the primary and extended SQLite result codes. Query errors retain the SQL string but never interpolate or include bound parameter values. If SQLite fails while stepping a live cursor for another batch, `fetchRow()` or iteration throws `SqliteQueryError`; the result then closes and releases its connection lease.

Malformed protocol responses, non-close operations on invalid resource IDs, and impossible state transitions are treated as connection failures because the parent can no longer trust the child state.

## Security considerations

The implementation must:

- Disable trusted schema by default.
- Enable and preserve foreign-key enforcement by default.
- Validate startup pragma names and values without accepting SQL fragments.
- Never include bound values in exception messages or logs.
- Never serialize native resources across IPC.
- Reject malformed and unexpected protocol messages.
- Generate savepoint identifiers internally.
- Reject multiple statements in one call.
- Avoid extension loading and worker-side callbacks in the initial API.
- Treat unexpected child termination as permanent connection failure.

Applications should enable the `sqlite3.defensive` INI setting in environments where it is available.

## Resource behavior

All transient resources implement AMPHP close semantics:

- `close()` is idempotent.
- `isClosed()` changes permanently after closure.
- `onClose()` callbacks run exactly once.
- Destructors schedule best-effort cleanup and do not block.
- Closing a parent resource invalidates its dependent resources.

The connection records the timestamp of its last completed operation for `getLastUsedAt()`. Statements and transactions expose timestamps consistent with AMPHP SQL conventions.

No resource is automatically recreated after failure.

## Platform support

The initial release supports:

- Linux
- macOS
- Windows

Process management is delegated to `amphp/parallel`. The package does not use `pcntl`, POSIX signals, or Unix-only IPC directly.

Path resolution, startup, orderly shutdown, forced termination, file-backed databases, and `:memory:` databases must be covered on all supported platforms in CI.

## Package conventions and quality

The repository will follow current AMPHP package conventions:

- PSR-4 source layout under `src/`.
- Internal implementations under `src/Internal/`.
- Tests under `test/`.
- Strict types in every PHP file.
- AMPHP's PHP CS Fixer configuration.
- Psalm configuration and generic annotations compatible with `amphp/sql`.
- PHPUnit and `amphp/phpunit-util` for tests.
- Composer scripts for coding style and tests.
- CI across supported PHP versions and operating systems.
- MIT license.

The public class-only API is an intentional exception to other AMPHP SQL packages, which commonly expose convenience functions and global connector accessors.

## Testing strategy

Tests must verify observable behavior rather than internal process details.

### Connection affinity

Integration tests must prove that successive operations use one native connection by covering:

- Creating, inserting, and selecting in `:memory:`.
- Creating and querying a temporary table.
- Applying and observing a connection-local pragma.
- Reusing a prepared statement.
- Holding and releasing a streamed cursor.

### Non-blocking behavior

Tests with two file-backed connections must hold a write lock in one connection while another waits according to its busy timeout. An event-loop timer must continue firing while the second child is blocked in SQLite.

### Streaming

Tests must cover:

- Results smaller than, equal to, and larger than the batch size.
- `fetchRow()` and iteration sharing the same local batch state.
- Empty results.
- Mid-stream SQLite errors.
- Early explicit closure.
- Races between automatic and best-effort closure.
- Abandoned-result cleanup.
- Large BLOB values.
- Connection queue release after exhaustion and closure.
- Stable `null` row counts for row-producing results.
- Zero affected rows for DDL executed after DML.
- Trigger changes included in command row counts.

### Parameters and types

Tests must cover:

- Anonymous, numbered, and named parameters.
- Missing and extra parameters.
- Mixed parameter styles.
- Repeated named parameters.
- Rejection of invalid names and positions.
- Rejection of empty, whitespace-only, comment-only, and semicolon-only SQL.
- Consumed-prefix behavior across supported PHP versions with the minimum and current SQLite versions.
- Null, boolean, integer, float, text, and BLOB bindings.
- BLOB round trips through `SqliteBlob`.
- Rejection of unsupported PHP values.

### Transactions

Tests must cover:

- Commit and rollback.
- Deferred, immediate, and exclusive modes.
- Nested commit and rollback through savepoints.
- Multiple nesting levels.
- Commit and rollback callbacks.
- Automatic rollback of abandoned transactions.
- Waiting while nested transactions or results are active.
- Transaction invalidation after process failure.

### Configuration

Tests must cover:

- Modern defaults on writable file databases.
- `:memory:` special handling.
- Read-only behavior.
- WAL activation failure.
- Startup setting verification.
- Additional pragma validation.
- Relative path resolution.
- Missing files and missing directories.
- Connect-time rejection of configurations mutated through inherited host, port, user, or password methods.
- Connect-time re-validation of paths changed through `withDatabase()`.
- Minimum SQLite version behavior where injectable startup metadata permits it.

### Failure handling

Tests must cover:

- Syntax and constraint errors with SQLite result codes.
- Busy timeout errors.
- Invalid transaction lifecycle operations.
- Child termination during an operation.
- Queued-operation failure after connection closure.
- Callback execution exactly once.
- No parameter values in errors.

## Implementation plan

### Phase 1: Package foundation

- Add Composer metadata, license, coding style, Psalm, PHPUnit, and CI.
- Add public interfaces, configuration types, enums, BLOB value object, and exceptions.
- Add configuration and value behavior tests.

### Phase 2: Process connection

- Add the process-only connector and injectable context factory.
- Implement startup handshake, version checks, path resolution, modern defaults, pragma validation, and shutdown.
- Implement connection lifecycle and the FIFO operation queue.
- Add connection affinity and process failure tests.

### Phase 3: Queries and streaming

- Implement native single-statement and parameter validation.
- Implement direct query and temporary statement execution.
- Verify native consumed-prefix behavior on all supported runtimes.
- Implement command results and batched row-producing results.
- Implement explicit result closure and abandoned-result cleanup.
- Add streaming, typing, and non-blocking tests.

### Phase 4: Prepared statements

- Implement retained worker-side statements and resource IDs.
- Implement parameter validation and native type binding.
- Implement statement reuse, serialization, closure, and BLOB round trips.

### Phase 5: Transactions

- Implement SQLite transaction modes and the nestable executor.
- Integrate `amphp/sql-common` transaction lifecycle classes.
- Implement savepoints, callbacks, and abandoned-transaction rollback.

### Phase 6: Hardening and documentation

- Complete cross-platform CI.
- Exercise forced process termination and malformed protocol states.
- Document installation, configuration, queries, streaming, prepared statements, BLOBs, and transactions.
- Benchmark batch sizes and IPC overhead without changing the specified default unless evidence justifies a follow-up proposal.

## Acceptance criteria

The initial release is complete when:

1. All public contracts described in this RFP are implemented.
2. No `ext-sqlite3` operation runs in the parent event-loop process.
3. `:memory:`, temporary tables, pragmas, statements, and transactions demonstrate connection affinity.
4. Large result sets stream in bounded row batches.
5. An active result deterministically owns and releases its connection.
6. Prepared statements are native, retained, reusable, and correctly typed.
7. Nested transactions behave correctly through savepoints.
8. Modern defaults are applied and verified, with WAL failure reported rather than hidden.
9. Process death invalidates the connection and all dependent resources without reconnection.
10. Linux, macOS, and Windows CI passes on all supported PHP versions.
11. Coding style, static analysis, and the full test suite pass.

## Future work

Potential follow-up proposals may cover:

- A SQLite-aware connection pool.
- Cooperative query interruption.
- Online backup.
- Incremental BLOB streams.
- Custom functions, aggregates, and collations.
- Explicit checkpoint management.
- SQLite URI filenames.
- Richer column metadata.
- Per-result batch tuning if a standard API can support it cleanly.
