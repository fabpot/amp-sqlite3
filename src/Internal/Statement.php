<?php

declare(strict_types=1);

/*
 * This file is part of the fabpot/amphp-sqlite3 package.
 *
 * (c) Fabien Potencier <fabien@potencier.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Fabpot\Amp\Sqlite\Internal;

use Amp\DeferredFuture;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Fabpot\Amp\Sqlite\SqliteBlob;
use Fabpot\Amp\Sqlite\SqliteException;
use Fabpot\Amp\Sqlite\SqliteResult;
use Fabpot\Amp\Sqlite\SqliteStatement;
use Fabpot\Amp\Sqlite\SqliteTransactionError;
use Revolt\EventLoop;

/** @internal */
final class Statement implements SqliteStatement
{
    use ForbidCloning;
    use ForbidSerialization;

    /** @var DeferredFuture<null> */
    private readonly DeferredFuture $onClose;
    private bool $closed = false;
    private int $lastUsedAt;
    /** @var \WeakReference<SqliteResult>|null */
    private ?\WeakReference $activeResult = null;
    /** @var \WeakReference<Transaction>|null */
    private readonly ?\WeakReference $transaction;

    public function __construct(
        private readonly Connection $connection,
        private readonly int $statementId,
        private readonly string $query,
        ?Transaction $transaction,
    ) {
        $this->onClose = new DeferredFuture();
        $this->lastUsedAt = \time();
        $this->transaction = $transaction !== null ? \WeakReference::create($transaction) : null;
    }

    public function __destruct()
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        EventLoop::queue(self::dispose(...), $this->connection, $this->statementId, $this->query, $this->activeResult, $this->onClose);
    }

    /**
     * @param array<array-key, null|bool|int|float|string|SqliteBlob> $params
     */
    public function execute(#[\SensitiveParameter] array $params = []): SqliteResult
    {
        if ($this->closed) {
            throw new SqliteException('The SQLite statement is closed');
        }

        $transaction = $this->transaction?->get();
        if ($this->transaction !== null && $transaction === null) {
            throw new SqliteTransactionError('The transaction has been committed or rolled back');
        }

        $transactionLock = $transaction?->acquireOperation();

        try {
            $this->activeResult?->get()?->close();
            $result = $this->connection->executeStatement($this->statementId, $this->query, $params, $transaction !== null, $this);
            if ($this->isClosed()) {
                $result->close();

                throw new SqliteException('The SQLite statement is closed');
            }

            $this->lastUsedAt = \time();
            $this->activeResult = null;
            if (!$result->isClosed()) {
                $this->activeResult = \WeakReference::create($result);
                // Closing a statement frees its results in the worker, so the result keeps the statement alive
                $result->onClose(function (): void {
                    $this->lastUsedAt = \time();
                });
            }

            return $result;
        } finally {
            $transactionLock?->release();
        }
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getLastUsedAt(): int
    {
        return $this->lastUsedAt;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        self::dispose($this->connection, $this->statementId, $this->query, $this->activeResult, $this->onClose);
    }

    public function closeOnConnectionClose(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        self::dispose($this->connection, $this->statementId, $this->query, null, $this->onClose);
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function onClose(\Closure $onClose): void
    {
        $this->onClose->getFuture()->finally($onClose);
    }

    /**
     * @param \WeakReference<SqliteResult>|null $activeResult
     * @param DeferredFuture<null> $onClose
     */
    private static function dispose(Connection $connection, int $statementId, string $query, ?\WeakReference $activeResult, DeferredFuture $onClose): void
    {
        try {
            $activeResult?->get()?->close();
        } finally {
            try {
                $connection->closeStatement($statementId, $query);
            } finally {
                $onClose->complete();
            }
        }
    }
}
