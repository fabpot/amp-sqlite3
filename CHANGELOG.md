# Changelog

## 1.1.0

- Fix pooled results losing their last row and holding their connection when read with fetchRow()
- Roll back abandoned nested transactions instead of blocking their parent transaction
- Roll back abandoned transactions even while their prepared statements are still referenced
- Apply additional pragmas before enabling WAL so settings such as page_size take effect on new databases
- Ignore empty SQL statements so they no longer break scripts or hide transaction control from executeScript()
- Reject SQL containing NUL bytes instead of letting SQLite silently ignore the rest of the text
- Make beginTransaction() wait for the active transaction to finish instead of throwing, like other connection operations
- Throw instead of deadlocking when a fiber finishes a transaction while holding its unread results or BLOB streams
- Keep pragma values out of child-process stack traces when a connection fails to start
- Fail reads of results and BLOB streams interrupted by a connection close instead of silently ending them
- Speed up repeated executions of prepared write statements by reusing their metadata until SQLite recompiles them
- Fix INSERT statements failing on SQLite versions older than 3.33, which lack the sqlite_schema table
- Read result rows in constant time so larger batch sizes no longer slow down iteration
- Speed up direct INSERT queries by caching whether each target table has rowids until its schema changes
- Speed up direct INSERT, UPDATE, and DELETE queries by caching whether their SQL produces rows
- Return pooled connections and prepared statements to the pool as soon as their result is read or closed
- Fix a fatal error at shutdown when connections, results, statements, or transactions were still alive
- Clean up dropped connections, results, statements, BLOB streams, and transactions from the event loop instead of blocking in their destructor

## 1.0.0

- Promote the asynchronous SQLite driver to a stable release
- Prevent concurrent result closure from invalidating the connection

## 0.3

- Make last-insert IDs result-specific and nullable
- Scope transaction-prepared statements to their transaction
- Normalize direct and pooled closure behavior and errors
- Distinguish SQL query failures from non-SQL SQLite operation failures
- Enforce AMPHP's pending-read and cancellation contracts for BLOB streams
- Add path-specific configuration accessors and SQLite-specific transaction mode types
- Reject row-producing DML before PHP's SQLite3 result handling can execute it twice
- Prevent connection shutdown and child-process failures from deadlocking queued operations
- Validate SQLite child-process requests and responses at the IPC boundary
- Preserve SQLite's synchronous default with explicit rollback journal modes
- Retry explicit WAL activation during concurrent database initialization
- Prevent concurrent statement closure from invalidating the connection
- Throw SqliteException instead of plain Error for closed statements and results
- Fix a hang when beginning a transaction on a closed connection
- Surface the child-process error when a connection fails to start
- Resolve Windows drive-relative paths against the working directory
- Execute multi-statement SQL scripts atomically

## 0.2

- Fix concurrent initialization of new WAL databases

## 0.1

- Add the initial preview release
