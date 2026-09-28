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
use Amp\Sync\LocalMutex;
use Amp\Sync\Lock;
use Fabpot\Amp\Sqlite\SqliteTransactionError;
use Revolt\EventLoop\FiberLocal;

/**
 * Tracks who may use a connection: an exclusive connection lock for non-transactional work and the active
 * transaction, and shared leases for operations inside that transaction.
 *
 * @internal
 */
final class ConnectionLeases
{
    use ForbidCloning;
    use ForbidSerialization;

    private readonly LocalMutex $mutex;
    private int $connectionLocks = 0;
    private int $retainedLeases = 0;
    private ?Lock $transactionLock = null;
    private int $transactionLeases = 0;
    /** @var DeferredFuture<null>|null */
    private ?DeferredFuture $transactionIdle = null;
    /** @var \WeakMap<\stdClass, int> */
    private \WeakMap $transactionLeaseOwners;
    /** @var FiberLocal<\stdClass> */
    private readonly FiberLocal $task;

    public function __construct()
    {
        $this->mutex = new LocalMutex();
        /** @var \WeakMap<\stdClass, int> $transactionLeaseOwners */
        $transactionLeaseOwners = new \WeakMap();
        $this->transactionLeaseOwners = $transactionLeaseOwners;
        $this->task = new FiberLocal(static fn (): \stdClass => new \stdClass());
    }

    public function acquireConnection(): Lock
    {
        $lock = $this->mutex->acquire();
        ++$this->connectionLocks;

        return new Lock(function () use ($lock): void {
            --$this->connectionLocks;
            $lock->release();
        });
    }

    /**
     * @throws SqliteTransactionError If no transaction is active anymore
     */
    public function acquireTransactionLease(): void
    {
        $this->awaitTransactionIdle();
        if ($this->transactionLock === null) {
            throw new SqliteTransactionError('The transaction is no longer active');
        }
        ++$this->transactionLeases;
    }

    /**
     * Releases the connection lock or the transaction lease once a request that retains nothing is done.
     */
    public function release(?Lock $lock, bool $transactional): void
    {
        $lock?->release();
        if ($transactional) {
            $this->releaseTransactionLease();
        }
    }

    /**
     * Keeps an acquired lock or lease for a resource that outlives the request, such as an unread result.
     */
    public function retain(?Lock $lock, bool $transactional): Lock
    {
        ++$this->retainedLeases;
        $owner = null;
        if ($transactional) {
            $owner = $this->task->get();
            $this->transactionLeaseOwners[$owner] = ($this->transactionLeaseOwners[$owner] ?? 0) + 1;
        }

        return new Lock(function () use ($lock, $transactional, $owner): void {
            if ($this->retainedLeases > 0) {
                --$this->retainedLeases;
            }
            $lock?->release();
            if ($transactional) {
                $this->releaseTransactionLease($owner);
            }
        });
    }

    public function holdTransactionLock(Lock $lock): void
    {
        $this->transactionLock = $lock;
    }

    public function releaseTransactionLock(): void
    {
        $this->transactionLock?->release();
        $this->transactionLock = null;
    }

    public function awaitTransactionIdle(): void
    {
        while ($this->transactionLeases > 0) {
            ($this->transactionIdle ??= new DeferredFuture())->getFuture()->await();
        }
    }

    /**
     * Whether the current task retains a transaction resource, so waiting for the transaction to become idle would
     * deadlock it.
     */
    public function currentTaskHoldsTransactionLease(): bool
    {
        return isset($this->transactionLeaseOwners[$this->task->get()]);
    }

    public function isBusy(): bool
    {
        return $this->connectionLocks > 0 || $this->retainedLeases > 0 || $this->transactionLock !== null;
    }

    /**
     * Releases the transaction and wakes up every waiting operation after the connection was closed.
     */
    public function reset(): void
    {
        $this->releaseTransactionLock();
        $this->transactionLeases = 0;
        /** @var \WeakMap<\stdClass, int> $transactionLeaseOwners */
        $transactionLeaseOwners = new \WeakMap();
        $this->transactionLeaseOwners = $transactionLeaseOwners;
        $this->transactionIdle?->complete();
        $this->transactionIdle = null;
        $this->retainedLeases = 0;
    }

    private function releaseTransactionLease(?\stdClass $owner = null): void
    {
        if ($owner !== null && isset($this->transactionLeaseOwners[$owner]) && --$this->transactionLeaseOwners[$owner] === 0) {
            unset($this->transactionLeaseOwners[$owner]);
        }

        if ($this->transactionLeases === 0) {
            return;
        }

        if (--$this->transactionLeases === 0) {
            $this->transactionIdle?->complete();
            $this->transactionIdle = null;
        }
    }
}
