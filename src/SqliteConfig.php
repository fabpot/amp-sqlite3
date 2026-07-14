<?php

declare(strict_types=1);

namespace Fabpot\Amp\Sqlite;

use Amp\Sql\SqlConfig;

final class SqliteConfig extends SqlConfig
{
    private const RESERVED_PRAGMAS = [
        'busy_timeout' => true,
        'foreign_keys' => true,
        'journal_mode' => true,
        'synchronous' => true,
        'trusted_schema' => true,
    ];

    private SqliteOpenMode $openMode = SqliteOpenMode::ReadWriteCreate;
    private SqliteJournalMode $journalMode = SqliteJournalMode::Automatic;
    private SqliteSynchronousMode $synchronousMode = SqliteSynchronousMode::Automatic;
    private bool $foreignKeys = true;
    private int $busyTimeout = 5000;
    private bool $trustedSchema = false;
    private int $batchSize = 100;
    private SqliteTransactionMode $transactionMode = SqliteTransactionMode::Deferred;
    private bool $extendedResultCodes = true;

    /** @var array<string, null|bool|int|float|string> */
    private array $pragmas = [];

    /** @var array<string, array{callback: string, arg_count: int, deterministic: bool}> */
    private array $functions = [];

    /** @var array<string, array{step: string, final: string, arg_count: int}> */
    private array $aggregates = [];

    /** @var array<string, string> */
    private array $collations = [];

    public function __construct(string $path)
    {
        self::validatePath($path);

        parent::__construct('', 0, database: $path);
    }

    public function getOpenMode(): SqliteOpenMode
    {
        return $this->openMode;
    }

    public function withOpenMode(SqliteOpenMode $openMode): self
    {
        $config = clone $this;
        $config->openMode = $openMode;
        $config->validateModeCombination();

        return $config;
    }

    public function getJournalMode(): SqliteJournalMode
    {
        return $this->journalMode;
    }

    public function withJournalMode(SqliteJournalMode $journalMode): self
    {
        $config = clone $this;
        $config->journalMode = $journalMode;
        $config->validateModeCombination();

        return $config;
    }

    public function getSynchronousMode(): SqliteSynchronousMode
    {
        return $this->synchronousMode;
    }

    public function withSynchronousMode(SqliteSynchronousMode $synchronousMode): self
    {
        $config = clone $this;
        $config->synchronousMode = $synchronousMode;
        $config->validateModeCombination();

        return $config;
    }

    public function hasForeignKeys(): bool
    {
        return $this->foreignKeys;
    }

    public function withForeignKeys(bool $foreignKeys): self
    {
        $config = clone $this;
        $config->foreignKeys = $foreignKeys;

        return $config;
    }

    public function getBusyTimeout(): int
    {
        return $this->busyTimeout;
    }

    public function withBusyTimeout(int $busyTimeout): self
    {
        if ($busyTimeout < 0) {
            throw new \ValueError('Busy timeout must not be negative');
        }

        $config = clone $this;
        $config->busyTimeout = $busyTimeout;

        return $config;
    }

    public function hasTrustedSchema(): bool
    {
        return $this->trustedSchema;
    }

    public function withTrustedSchema(bool $trustedSchema): self
    {
        $config = clone $this;
        $config->trustedSchema = $trustedSchema;

        return $config;
    }

    public function getBatchSize(): int
    {
        return $this->batchSize;
    }

    public function withBatchSize(int $batchSize): self
    {
        if ($batchSize < 1) {
            throw new \ValueError('Batch size must be greater than zero');
        }

        $config = clone $this;
        $config->batchSize = $batchSize;

        return $config;
    }

    public function getTransactionMode(): SqliteTransactionMode
    {
        return $this->transactionMode;
    }

    public function withTransactionMode(SqliteTransactionMode $transactionMode): self
    {
        $config = clone $this;
        $config->transactionMode = $transactionMode;

        return $config;
    }

    public function hasExtendedResultCodes(): bool
    {
        return $this->extendedResultCodes;
    }

    public function withExtendedResultCodes(bool $extendedResultCodes): self
    {
        $config = clone $this;
        $config->extendedResultCodes = $extendedResultCodes;

        return $config;
    }

    public function withPragma(string $name, #[\SensitiveParameter] null|bool|int|float|string $value): self
    {
        if (!\preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name)) {
            throw new \ValueError("Invalid pragma name '{$name}'");
        }

        $name = \strtolower($name);
        if (isset(self::RESERVED_PRAGMAS[$name])) {
            throw new \ValueError("Pragma '{$name}' has a dedicated configuration option");
        }

        $config = clone $this;
        $config->pragmas[$name] = $value;

        return $config;
    }

    /** @return array<string, null|bool|int|float|string> */
    public function getPragmas(): array
    {
        return $this->pragmas;
    }

    /**
     * Registers a custom SQL function implemented by a named PHP function or static method.
     * The callable is registered in the child process, so it must be a string like
     * 'strrev' or 'App\Sql\Functions::reverse'; closures are not supported.
     */
    public function withFunction(string $name, string $callback, int $argCount = -1, bool $deterministic = false): self
    {
        self::validateCallableName($name);
        self::validateCallable($callback);

        $config = clone $this;
        $config->functions[\strtolower($name)] = ['callback' => $callback, 'arg_count' => $argCount, 'deterministic' => $deterministic];

        return $config;
    }

    /** @return array<string, array{callback: string, arg_count: int, deterministic: bool}> */
    public function getFunctions(): array
    {
        return $this->functions;
    }

    /**
     * Registers a custom aggregate implemented by named PHP functions or static methods.
     */
    public function withAggregate(string $name, string $stepCallback, string $finalCallback, int $argCount = -1): self
    {
        self::validateCallableName($name);
        self::validateCallable($stepCallback);
        self::validateCallable($finalCallback);

        $config = clone $this;
        $config->aggregates[\strtolower($name)] = ['step' => $stepCallback, 'final' => $finalCallback, 'arg_count' => $argCount];

        return $config;
    }

    /** @return array<string, array{step: string, final: string, arg_count: int}> */
    public function getAggregates(): array
    {
        return $this->aggregates;
    }

    /**
     * Registers a custom collation implemented by a named PHP function or static method.
     */
    public function withCollation(string $name, string $callback): self
    {
        self::validateCallableName($name);
        self::validateCallable($callback);

        $config = clone $this;
        $config->collations[\strtolower($name)] = $callback;

        return $config;
    }

    /** @return array<string, string> */
    public function getCollations(): array
    {
        return $this->collations;
    }

    public static function validatePath(?string $path): void
    {
        if ($path === null || $path === '') {
            throw new \ValueError('SQLite database path must not be empty');
        }

        if (\strncasecmp($path, 'file:', 5) === 0) {
            throw new \ValueError('SQLite URI filenames are not supported');
        }
    }

    private static function validateCallableName(string $name): void
    {
        if (!\preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name)) {
            throw new \ValueError("Invalid SQL function or collation name '{$name}'");
        }
    }

    private static function validateCallable(string $callback): void
    {
        if (!\is_callable($callback)) {
            throw new \ValueError("'{$callback}' is not a named function or public static method");
        }
    }

    private function validateModeCombination(): void
    {
        if ($this->openMode === SqliteOpenMode::ReadOnly && $this->journalMode !== SqliteJournalMode::Automatic) {
            throw new \ValueError('An explicit journal mode cannot be used with a read-only database');
        }

        if ($this->openMode === SqliteOpenMode::ReadOnly && $this->synchronousMode !== SqliteSynchronousMode::Automatic) {
            throw new \ValueError('An explicit synchronous mode cannot be used with a read-only database');
        }
    }
}
