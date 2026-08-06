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

namespace Fabpot\Amp\Sqlite;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Parallel\Context\ContextException;
use Amp\Parallel\Context\ContextFactory;
use Amp\Parallel\Context\ContextPanicError;
use Amp\Parallel\Context\ProcessContext;
use Amp\Parallel\Context\ProcessContextFactory;
use Amp\Sql\SqlConfig;
use Amp\Sql\SqlConnector;
use Amp\TimeoutCancellation;
use Fabpot\Amp\Sqlite\Internal\Connection;
use Fabpot\Amp\Sqlite\Internal\Path;

/**
 * @implements SqlConnector<SqliteConfig, SqliteConnection>
 */
final class SqliteConnector implements SqlConnector
{
    public function __construct(
        private readonly ContextFactory $contextFactory = new ProcessContextFactory(),
    ) {
    }

    /**
     * @param SqliteConfig $config
     */
    public function connect(SqlConfig $config, ?Cancellation $cancellation = null): SqliteConnection
    {
        if (!$config instanceof SqliteConfig) {
            throw new \TypeError('SqliteConnector expects an instance of SqliteConfig');
        }
        $path = $config->getPath();
        if ($config->getHost() !== '' || $config->getPort() !== 0 || $config->getUser() !== null || $config->getPassword() !== null) {
            throw new \InvalidArgumentException('SQLite configurations cannot contain server connection settings');
        }
        $path = Path::resolve($path);
        $context = null;

        try {
            $context = $this->contextFactory->start(__DIR__ . '/Internal/worker.php', $cancellation);
            if (!$context instanceof ProcessContext) {
                $context->close();

                throw new \RuntimeException('SQLite connections require process isolation');
            }

            /** @var ProcessContext<null, mixed, array<string, mixed>> $context */
            $context->send([
                'path' => $path,
                'open_mode' => $config->getOpenMode()->name,
                'journal_mode' => $config->getJournalMode()->value,
                'synchronous_mode' => $config->getSynchronousMode()->value,
                'foreign_keys' => $config->hasForeignKeys(),
                'busy_timeout' => $config->getBusyTimeout(),
                'batch_size' => $config->getBatchSize(),
                'trusted_schema' => $config->hasTrustedSchema(),
                'extended_result_codes' => $config->hasExtendedResultCodes(),
                'pragmas' => $config->getPragmas(),
                'functions' => $config->getFunctions(),
                'aggregates' => $config->getAggregates(),
                'collations' => $config->getCollations(),
            ]);
            $ready = $context->receive($cancellation);

            if (!\is_array($ready) || $ready !== ['ready' => true]) {
                throw new SqliteConnectionException('The SQLite child process sent an invalid startup response');
            }
        } catch (\Throwable $exception) {
            if ($context instanceof ProcessContext && $exception instanceof ContextException) {
                $exception = self::findChildFailure($context, $exception);
            }
            $context?->close();
            $cancellation?->throwIfRequested();

            throw new SqliteConnectionException('Could not start the SQLite child process: ' . self::describeStartupFailure($exception), previous: $exception);
        }

        return new Connection($config, $context);
    }

    /**
     * @param ProcessContext<null, mixed, array<string, mixed>> $context
     */
    private static function findChildFailure(ProcessContext $context, ContextException $exception): \Throwable
    {
        try {
            $context->join(new TimeoutCancellation(1));
        } catch (CancelledException) {
            // The child process is still running; keep the original failure.
        } catch (\Throwable $failure) {
            return $failure;
        }

        return $exception;
    }

    private static function describeStartupFailure(\Throwable $exception): string
    {
        for ($failure = $exception; $failure !== null; $failure = $failure->getPrevious()) {
            if ($failure instanceof ContextPanicError) {
                return $failure->getOriginalMessage();
            }
        }

        return $exception->getMessage();
    }
}
