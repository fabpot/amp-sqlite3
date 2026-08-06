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
use Fabpot\Amp\Sqlite\SqliteBlob;
use Fabpot\Amp\Sqlite\SqliteException;
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

    /** @var \stdClass&object{count: int} */
    private readonly \stdClass $references;

    /**
     * @param \Closure():void $release
     * @param (\Closure():void)|null $awaitBusyResource
     */
    public function __construct(
        private readonly SqliteStatement $statement,
        \Closure $release,
        private readonly ?\Closure $awaitBusyResource = null,
    ) {
        $references = new \stdClass();
        $references->count = 1;
        /** @var \stdClass&object{count: int} $references */
        $this->references = $references;
        $this->release = static function () use ($references, $release): void {
            if (--$references->count === 0) {
                $release();
            }
        };
        $reference = \WeakReference::create($this);
        $this->statement->onClose(static fn () => $reference->get()?->dispose());
    }

    public function __destruct()
    {
        $this->dispose();
    }

    /**
     * @param array<array-key, null|bool|int|float|string|SqliteBlob> $params
     */
    public function execute(#[\SensitiveParameter] array $params = []): SqliteResult
    {
        $release = $this->release;
        if ($release === null) {
            throw new SqliteException('The statement has been closed');
        }

        if ($this->awaitBusyResource !== null) {
            ($this->awaitBusyResource)();
        }

        $result = $this->statement->execute($params);
        ++$this->references->count;

        return new PooledResult($result, $release);
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
