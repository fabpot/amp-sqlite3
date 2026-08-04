# Changelog

## 1.0

- Preserve SQLite's synchronous default with explicit rollback journal modes.
- Retry explicit WAL activation during concurrent database initialization.
- Prevent concurrent statement closure from invalidating the connection.
- Throw SqliteException instead of plain Error for closed statements and results.
- Fix a hang when beginning a transaction on a closed connection.
- Surface the child-process error when a connection fails to start.
- Resolve Windows drive-relative paths against the working directory.
- Execute multi-statement SQL scripts atomically.
- Add a stable asynchronous SQLite driver with pooling, transactions, incremental BLOB I/O, backups, and custom SQL callables.

## 0.2

- Fix concurrent initialization of new WAL databases.

## 0.1

- Add the initial preview release.
