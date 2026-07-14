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
use Amp\Sql\SqlException;
use Fabpot\Amp\Sqlite\SqliteConnectionPool;
use Fabpot\Amp\Sqlite\SqliteResult;
use Fabpot\Amp\Sqlite\SqliteStatement;
use Revolt\EventLoop;

final class StatementPool implements SqliteStatement
{
    use ForbidCloning;
    use ForbidSerialization;

    /** @var \SplQueue<SqliteStatement> */
    private readonly \SplQueue $statements;
    private readonly DeferredFuture $onClose;
    private int $lastUsedAt;

    /**
     * @param \Closure(string):SqliteStatement $prepare
     */
    public function __construct(
        private readonly SqliteConnectionPool $pool,
        private readonly string $sql,
        private readonly \Closure $prepare,
    ) {
        $this->lastUsedAt = \time();
        $this->statements = $statements = new \SplQueue();
        $this->onClose = new DeferredFuture();

        $timeoutWatcher = EventLoop::repeat(1, static function () use ($pool, $statements): void {
            $now = \time();
            $idleTimeout = ((int) ($pool->getIdleTimeout() / 10)) ?: 1;

            while (!$statements->isEmpty()) {
                $statement = $statements->bottom();
                if ($statement->getLastUsedAt() + $idleTimeout > $now) {
                    return;
                }

                $statements->shift();
            }
        });

        EventLoop::unreference($timeoutWatcher);
        $this->onClose(static fn () => EventLoop::cancel($timeoutWatcher));
    }

    public function __destruct()
    {
        $this->close();
    }

    public function execute(#[\SensitiveParameter] array $params = []): SqliteResult
    {
        if ($this->isClosed()) {
            throw new SqlException('The statement has been closed or the connection pool has been closed');
        }

        $this->lastUsedAt = \time();
        $statement = $this->pop();

        try {
            $result = $statement->execute($params);
        } catch (\Throwable $exception) {
            $this->push($statement);
            throw $exception;
        }

        return new PooledResult($result, fn () => $this->push($statement));
    }

    public function getQuery(): string
    {
        return $this->sql;
    }

    public function getLastUsedAt(): int
    {
        return $this->lastUsedAt;
    }

    public function close(): void
    {
        if (!$this->onClose->isComplete()) {
            $this->onClose->complete();
        }
    }

    public function isClosed(): bool
    {
        return $this->onClose->isComplete();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->onClose->getFuture()->finally($onClose);
    }

    private function push(SqliteStatement $statement): void
    {
        $maxConnections = $this->pool->getConnectionLimit();
        if ($this->statements->count() > $maxConnections / 10) {
            return;
        }

        if ($maxConnections === $this->pool->getConnectionCount() && $this->pool->getIdleConnectionCount() === 0) {
            return;
        }

        $this->statements->enqueue($statement);
    }

    private function pop(): SqliteStatement
    {
        while (!$this->statements->isEmpty()) {
            $statement = $this->statements->dequeue();
            if (!$statement->isClosed()) {
                return $statement;
            }
        }

        return ($this->prepare)($this->sql);
    }
}
