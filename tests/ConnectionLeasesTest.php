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

namespace Fabpot\Amp\Sqlite\Test;

use Amp\Closable;
use Fabpot\Amp\Sqlite\Internal\ConnectionLeases;
use Fabpot\Amp\Sqlite\SqliteTransactionError;
use PHPUnit\Framework\TestCase;
use function Amp\async;
use function Amp\delay;
use function Amp\Future\await;

final class ConnectionLeasesTest extends TestCase
{
    public function testTransactionLeasesWaitForRetainedResourceWithoutLosingWakeups(): void
    {
        $leases = new ConnectionLeases();
        $leases->holdTransactionLock($leases->acquireConnection());
        $leases->acquireTransactionLease();
        $resource = $leases->retain(null, true);
        $operation = static function () use ($leases): void {
            $leases->acquireTransactionLease();
            delay(0);
            $leases->release(null, true);
        };
        $waiters = [async($operation), async($operation), async($operation)];
        delay(0);

        self::assertFalse($waiters[0]->isComplete());
        $resource->release();
        await($waiters);

        self::assertTrue($leases->isBusy());
        $leases->releaseTransactionLock();
        self::assertFalse($leases->isBusy());
    }

    public function testTransactionLeaseRequiresActiveTransaction(): void
    {
        $this->expectException(SqliteTransactionError::class);
        $this->expectExceptionMessage('The transaction is no longer active');

        (new ConnectionLeases())->acquireTransactionLease();
    }

    public function testTransactionResourceBelongsToTheTaskThatOpenedItUntilClosed(): void
    {
        $leases = new ConnectionLeases();
        $resource = self::createResource();
        $leases->trackTransactionResource($resource);

        self::assertTrue($leases->currentTaskHoldsTransactionLease());
        self::assertFalse(async($leases->currentTaskHoldsTransactionLease(...))->await());

        $resource->close();

        self::assertFalse($leases->currentTaskHoldsTransactionLease());
    }

    public function testDroppedTransactionResourceNoLongerBelongsToItsTask(): void
    {
        $leases = new ConnectionLeases();
        $resource = self::createResource();
        $leases->trackTransactionResource($resource);

        unset($resource);

        self::assertFalse($leases->currentTaskHoldsTransactionLease());
    }

    public function testRetainedConnectionLockIsReleasedOnce(): void
    {
        $leases = new ConnectionLeases();
        $resource = $leases->retain($leases->acquireConnection(), false);
        $next = async($leases->acquireConnection(...));
        delay(0);

        self::assertTrue($leases->isBusy());
        self::assertFalse($next->isComplete());

        $resource->release();
        $resource->release();
        $next->await()->release();

        self::assertFalse($leases->isBusy());
    }

    public function testResetRejectsOperationsWaitingForTheTransaction(): void
    {
        $leases = new ConnectionLeases();
        $leases->holdTransactionLock($leases->acquireConnection());
        $leases->acquireTransactionLease();
        // Dropping the returned lock would release the lease
        $resource = $leases->retain(null, true);
        $waiter = async($leases->acquireTransactionLease(...));
        delay(0);

        $leases->reset();

        self::assertFalse($leases->isBusy());
        $this->expectException(SqliteTransactionError::class);
        $waiter->await();
    }

    private static function createResource(): Closable
    {
        return new class implements Closable {
            private bool $closed = false;

            public function close(): void
            {
                $this->closed = true;
            }

            public function isClosed(): bool
            {
                return $this->closed;
            }

            public function onClose(\Closure $onClose): void
            {
            }
        };
    }
}
