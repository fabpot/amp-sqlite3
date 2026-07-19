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
use Amp\Parallel\Context\ContextFactory;
use Amp\Parallel\Context\ProcessContext;
use Amp\Parallel\Context\ProcessContextFactory;
use Amp\Sql\SqlConfig;
use Amp\Sql\SqlConnector;
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

        if ($config->getHost() !== '' || $config->getPort() !== 0 || $config->getUser() !== null || $config->getPassword() !== null) {
            throw new \ValueError('SQLite configurations cannot contain server connection settings');
        }

        SqliteConfig::validatePath($config->getDatabase());
        if ($config->getOpenMode() === SqliteOpenMode::ReadOnly && $config->getSynchronousMode() !== SqliteSynchronousMode::Automatic) {
            throw new \ValueError('An explicit synchronous mode cannot be used with a read-only database');
        }
        /** @var string $database */
        $database = $config->getDatabase();
        $path = Path::resolve($database);
        $context = null;

        try {
            $context = $this->contextFactory->start(__DIR__ . '/Internal/worker.php', $cancellation);
            if (!$context instanceof ProcessContext) {
                $context->close();

                throw new \ValueError('SQLite connections require process isolation');
            }

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

            if (($ready['ready'] ?? false) !== true) {
                throw new SqliteConnectionException('The SQLite child process sent an invalid startup response');
            }
        } catch (\ValueError $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $context?->close();
            $cancellation?->throwIfRequested();

            throw new SqliteConnectionException('Could not start the SQLite child process: ' . $exception->getMessage(), previous: $exception);
        }

        return new Connection($config, $context);
    }

}
