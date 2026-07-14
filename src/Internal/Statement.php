<?php

declare(strict_types=1);

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

    public function execute(array $params = []): SqliteResult
    {
        if ($this->closed) {
            throw new \Error('The SQLite statement is closed');
        }

        $this->transaction?->awaitAvailable();
        $result = $this->connection->executeStatement($this->statementId, $this->query, $params, $this->transaction !== null);
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
        $this->connection->closeStatement($this->statementId, $this->query, $this->transaction?->isActive() ?? false);
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
