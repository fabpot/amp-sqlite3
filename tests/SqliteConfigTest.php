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

use Fabpot\Amp\Sqlite\SqliteConfig;
use Fabpot\Amp\Sqlite\SqliteJournalMode;
use Fabpot\Amp\Sqlite\SqliteOpenMode;
use Fabpot\Amp\Sqlite\SqliteSynchronousMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SqliteConfigTest extends TestCase
{
    #[DataProvider('provideInvalidPaths')]
    public function testRejectsInvalidPath(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SqliteConfig($path);
    }

    /** @return iterable<string, array{string}> */
    public static function provideInvalidPaths(): iterable
    {
        yield 'empty' => [''];
        yield 'URI' => ['file:database.sqlite'];
        yield 'URI with uppercase scheme' => ['FILE:database.sqlite'];
    }

    public function testExposesPathSpecificAccessors(): void
    {
        $config = new SqliteConfig('database.sqlite');
        $changed = $config->withPath('other.sqlite');

        self::assertSame('database.sqlite', $config->getPath());
        self::assertSame('other.sqlite', $changed->getPath());
        self::assertSame('other.sqlite', $changed->getDatabase());
    }

    #[DataProvider('provideInvalidPaths')]
    public function testPathMutationRevalidatesPath(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SqliteConfig(':memory:'))->withPath($path);
    }

    public function testInheritedDatabaseMutationIsRevalidatedByPathAccessor(): void
    {
        $config = (new SqliteConfig(':memory:'))->withDatabase(null);

        $this->expectException(\InvalidArgumentException::class);

        $config->getPath();
    }

    #[DataProvider('provideInvalidBusyTimeouts')]
    public function testRejectsNegativeBusyTimeout(int $busyTimeout): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SqliteConfig(':memory:'))->withBusyTimeout($busyTimeout);
    }

    /** @return iterable<array{int}> */
    public static function provideInvalidBusyTimeouts(): iterable
    {
        yield [-1];
        yield [PHP_INT_MIN];
    }

    #[DataProvider('provideInvalidBatchSizes')]
    public function testRejectsInvalidBatchSize(int $batchSize): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SqliteConfig(':memory:'))->withBatchSize($batchSize);
    }

    /** @return iterable<array{int}> */
    public static function provideInvalidBatchSizes(): iterable
    {
        yield [0];
        yield [-1];
    }

    #[DataProvider('provideInvalidPragmaNames')]
    public function testRejectsInvalidPragmaName(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SqliteConfig(':memory:'))->withPragma($name, 1);
    }

    /** @return iterable<array{string}> */
    public static function provideInvalidPragmaNames(): iterable
    {
        yield [''];
        yield ['1cache_size'];
        yield ['cache-size'];
        yield ['cache_size; VACUUM'];
    }

    #[DataProvider('provideReservedPragmaNames')]
    public function testRejectsReservedPragma(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SqliteConfig(':memory:'))->withPragma($name, 1);
    }

    /** @return iterable<array{string}> */
    public static function provideReservedPragmaNames(): iterable
    {
        yield ['journal_mode'];
        yield ['SYNCHRONOUS'];
        yield ['foreign_keys'];
        yield ['trusted_schema'];
        yield ['busy_timeout'];
    }

    #[DataProvider('provideUnsupportedPragmaValues')]
    public function testRejectsUnsupportedPragmaValue(mixed $value): void
    {
        $this->expectException(\TypeError::class);

        (new SqliteConfig(':memory:'))->withPragma('cache_size', $value); // @phpstan-ignore argument.type
    }

    /** @return iterable<array{null|array<mixed>}> */
    public static function provideUnsupportedPragmaValues(): iterable
    {
        yield [null];
        yield [[]];
    }

    public function testRejectsWalForReadOnlyConnections(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SqliteConfig('database.sqlite'))
            ->withOpenMode(SqliteOpenMode::ReadOnly)
            ->withJournalMode(SqliteJournalMode::Wal);
    }

    public function testRejectsReadOnlyConnectionsWhenWalWasSelectedFirst(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SqliteConfig('database.sqlite'))
            ->withJournalMode(SqliteJournalMode::Wal)
            ->withOpenMode(SqliteOpenMode::ReadOnly);
    }

    public function testRejectsExplicitSynchronousModeForReadOnlyConnections(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SqliteConfig('database.sqlite'))
            ->withOpenMode(SqliteOpenMode::ReadOnly)
            ->withSynchronousMode(SqliteSynchronousMode::Full);
    }

    public function testAdditionalPragmasAreReplacedImmutably(): void
    {
        $config = (new SqliteConfig(':memory:'))->withPragma('cache_size', 100);
        $changed = $config->withPragma('cache_size', 200);

        self::assertSame(['cache_size' => 100], $config->getPragmas());
        self::assertSame(['cache_size' => 200], $changed->getPragmas());
    }
}
