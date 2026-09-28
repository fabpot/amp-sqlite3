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

use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Parallel\Context\Context;
use Amp\Sync\LocalMutex;
use Fabpot\Amp\Sqlite\SqliteException;
use Fabpot\Amp\Sqlite\SqliteQueryError;

/**
 * Sends requests to the SQLite child process one at a time.
 *
 * @internal
 */
final class WorkerChannel
{
    use ForbidCloning;
    use ForbidSerialization;

    private readonly LocalMutex $mutex;
    private int $nextRequestId = 1;
    private bool $busy = false;
    private int $lastUsedAt;

    /**
     * @param Context<null, mixed, array<string, mixed>> $context
     */
    public function __construct(
        private readonly Context $context,
    ) {
        $this->mutex = new LocalMutex();
        $this->lastUsedAt = \time();
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws SqliteQueryError If SQLite rejected the SQL
     * @throws SqliteException  If another SQLite operation failed
     * @throws WorkerFailure    If the child process stopped or broke the protocol
     */
    public function request(string $operation, string $sql, #[\SensitiveParameter] array $data): mixed
    {
        $lock = $this->mutex->acquire();
        $id = $this->nextRequestId++;
        $this->busy = true;

        try {
            $this->context->send(['id' => $id, 'operation' => $operation, ...$data]);
            $response = $this->context->receive();
        } catch (\Throwable $exception) {
            throw new WorkerFailure('The SQLite child process stopped unexpectedly', previous: $exception);
        } finally {
            $this->busy = false;
            $lock->release();
        }

        $this->lastUsedAt = \time();

        return WorkerResponse::unwrap($response, $id, $sql);
    }

    public function isBusy(): bool
    {
        return $this->busy;
    }

    public function isClosed(): bool
    {
        return $this->context->isClosed();
    }

    public function getLastUsedAt(): int
    {
        return $this->lastUsedAt;
    }

    /**
     * Asks the child process to close the database and waits for it to exit.
     *
     * @throws WorkerFailure If the child process did not shut down cleanly
     */
    public function close(): void
    {
        $lock = $this->mutex->acquire();

        try {
            if ($this->context->isClosed()) {
                return;
            }

            $this->context->send(['id' => $this->nextRequestId++, 'operation' => 'close']);
            $this->context->receive();
            $this->context->join();
        } catch (\Throwable $exception) {
            throw new WorkerFailure('The SQLite child process stopped unexpectedly', previous: $exception);
        } finally {
            $lock->release();
        }

        $this->context->close();
    }

    public function kill(): void
    {
        if ($this->context->isClosed()) {
            return;
        }

        $this->context->close();
        try {
            $this->context->join();
        } catch (\Throwable) {
        }
    }
}
