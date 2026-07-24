# Changelog

## 1.0

- Fix a hang when beginning a transaction on a closed connection.
- Surface the child-process error when a connection fails to start.
- Resolve Windows drive-relative paths against the working directory.
- Add explicit execution for multi-statement SQL scripts.
- Add a stable asynchronous SQLite driver with pooling, transactions, incremental BLOB I/O, backups, and custom SQL callables.

## 0.2

- Fix concurrent initialization of new WAL databases.

## 0.1

- Add the initial preview release.
