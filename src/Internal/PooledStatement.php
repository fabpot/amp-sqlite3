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
use Amp\Sql\SqlException;
use Fabpot\Amp\Sqlite\SqliteResult;
use Fabpot\Amp\Sqlite\SqliteStatement;
use Revolt\EventLoop;

/** @internal */
final class PooledStatement implements SqliteStatement
{
    use ForbidCloning;
    use ForbidSerialization;

    /** @var null|\Closure():void */
    private ?\Closure $release;

    /** @var object{count: int} */
    private readonly object $references;

    /**
     * @param \Closure():void $release
     * @param (\Closure():void)|null $awaitBusyResource
     */
    public function __construct(
        private readonly SqliteStatement $statement,
        \Closure $release,
        private readonly ?\Closure $awaitBusyResource = null,
    ) {
        $this->references = $references = new class {
            public int $count = 1;
        };
        $this->release = static function () use ($references, $release): void {
            if (--$references->count === 0) {
                $release();
            }
        };
    }

    public function __destruct()
    {
        $this->dispose();
    }

    public function execute(#[\SensitiveParameter] array $params = []): SqliteResult
    {
        if ($this->release === null) {
            throw new SqlException('The statement has been closed');
        }

        if ($this->awaitBusyResource !== null) {
            ($this->awaitBusyResource)();
        }

        $result = $this->statement->execute($params);
        ++$this->references->count;

        return new PooledResult($result, $this->release);
    }

    public function getQuery(): string
    {
        return $this->statement->getQuery();
    }

    public function getLastUsedAt(): int
    {
        return $this->statement->getLastUsedAt();
    }

    public function close(): void
    {
        $this->dispose();
        $this->statement->close();
    }

    public function isClosed(): bool
    {
        return $this->statement->isClosed();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->statement->onClose($onClose);
    }

    private function dispose(): void
    {
        if ($this->release !== null) {
            EventLoop::queue($this->release);
            $this->release = null;
        }
    }
}
