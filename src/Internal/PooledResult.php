<?php

declare(strict_types=1);

namespace Fabpot\Amp\Sqlite\Internal;

use Amp\Sql\Common\SqlPooledResult;
use Amp\Sql\SqlResult;
use Fabpot\Amp\Sqlite\SqliteBlob;
use Fabpot\Amp\Sqlite\SqliteResult;

/**
 * @extends SqlPooledResult<null|bool|int|float|string|SqliteBlob, SqliteResult>
 */
final class PooledResult extends SqlPooledResult implements SqliteResult
{
    private readonly SqliteResult $result;

    /**
     * @param \Closure():void $release
     */
    public function __construct(SqliteResult $result, \Closure $release)
    {
        parent::__construct($result, $release);
        $this->result = $result;
    }

    public function getNextResult(): ?SqliteResult
    {
        return parent::getNextResult();
    }

    public function getLastInsertId(): int
    {
        return $this->result->getLastInsertId();
    }

    public function getColumnNames(): ?array
    {
        return $this->result->getColumnNames();
    }

    public function close(): void
    {
        $this->result->close();
        while ($this->fetchRow() !== null) {
        }
    }

    public function isClosed(): bool
    {
        return $this->result->isClosed();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->result->onClose($onClose);
    }

    protected static function newInstanceFrom(SqlResult $result, \Closure $release): self
    {
        \assert($result instanceof SqliteResult);

        return new self($result, $release);
    }
}
