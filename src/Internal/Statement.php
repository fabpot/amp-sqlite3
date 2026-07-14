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
use Fabpot\Amp\Sqlite\SqliteResult;
use Fabpot\Amp\Sqlite\SqliteStatement;

final class Statement implements SqliteStatement
{
    use ForbidCloning;
    use ForbidSerialization;

    private readonly DeferredFuture $onClose;
    private bool $closed = false;
    private int $lastUsedAt;
    private ?SqliteResult $activeResult = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly int $statementId,
        private readonly string $query,
        private readonly ?Transaction $transaction,
    ) {
        $this->onClose = new DeferredFuture();
        $this->lastUsedAt = \time();
    }

    public function __destruct()
    {
        $this->close();
    }

    public function execute(#[\SensitiveParameter] array $params = []): SqliteResult
    {
        if ($this->closed) {
            throw new \Error('The SQLite statement is closed');
        }

        $this->transaction?->awaitAvailable();
        $this->activeResult?->close();
        $result = $this->connection->executeStatement($this->statementId, $this->query, $params, $this->transaction);
        $this->lastUsedAt = \time();
        if (!$result->isClosed()) {
            $this->activeResult = $result;
            $result->onClose(function (): void {
                $this->activeResult = null;
            });
        }

        return $result;
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
        $this->activeResult?->close();
        $this->connection->closeStatement($this->statementId, $this->query);
        $this->onClose->complete();
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function onClose(\Closure $onClose): void
    {
        $this->onClose->getFuture()->finally($onClose);
    }
}
