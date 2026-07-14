<?php

declare(strict_types=1);

namespace Fabpot\Amp\Sqlite;

use Amp\Cancellation;
use Amp\Parallel\Context\ContextFactory;
use Amp\Parallel\Context\ProcessContext;
use Amp\Parallel\Context\ProcessContextFactory;
use Amp\Sql\SqlConfig;
use Amp\Sql\SqlConnection;
use Amp\Sql\SqlConnector;
use Fabpot\Amp\Sqlite\Internal\Connection;

/**
 * @implements SqlConnector<SqliteConfig, SqliteConnection>
 */
final class SqliteConnector implements SqlConnector
{
    public function __construct(
        private readonly ContextFactory $contextFactory = new ProcessContextFactory(),
    ) {
    }

    public function connect(SqlConfig $config, ?Cancellation $cancellation = null): SqlConnection
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
        $path = $this->resolvePath($database);
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

    private function resolvePath(string $path): string
    {
        if ($path === ':memory:' || $this->isAbsolutePath($path)) {
            return $path;
        }

        $workingDirectory = \getcwd();
        if ($workingDirectory === false) {
            throw new SqliteConnectionException('Could not determine the current working directory');
        }

        return $workingDirectory . \DIRECTORY_SEPARATOR . $path;
    }

    private function isAbsolutePath(string $path): bool
    {
        return $path[0] === '/' || $path[0] === '\\' || (isset($path[2]) && \ctype_alpha($path[0]) && $path[1] === ':');
    }
}
