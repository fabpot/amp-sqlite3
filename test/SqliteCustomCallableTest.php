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
use Fabpot\Amp\Sqlite\SqliteConnector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SqliteCustomCallableTest extends TestCase
{
    public function testCustomFunction(): void
    {
        $config = (new SqliteConfig(':memory:'))
            ->withFunction('rot13', 'str_rot13', argCount: 1, deterministic: true);
        $connection = (new SqliteConnector())->connect($config);

        try {
            self::assertSame(
                ['value' => 'uryyb'],
                $connection->query("SELECT rot13('hello') AS value")->fetchRow(),
            );
        } finally {
            $connection->close();
        }
    }

    public function testCustomStaticMethodFunction(): void
    {
        $config = (new SqliteConfig(':memory:'))
            ->withFunction('slug', [SqlCallables::class, 'slugify'], argCount: 1);
        $connection = (new SqliteConnector())->connect($config);

        try {
            self::assertSame(
                ['value' => 'hello-world'],
                $connection->query("SELECT slug('Hello World') AS value")->fetchRow(),
            );
        } finally {
            $connection->close();
        }
    }

    public function testCustomAggregate(): void
    {
        $config = (new SqliteConfig(':memory:'))
            ->withAggregate('longest', [SqlCallables::class, 'longestStep'], [SqlCallables::class, 'longestFinal'], argCount: 1);
        $connection = (new SqliteConnector())->connect($config);

        try {
            $connection->query('CREATE TABLE words (word TEXT)');
            $connection->execute('INSERT INTO words VALUES (?), (?), (?)', ['a', 'ccc', 'bb']);

            self::assertSame(
                ['value' => 'ccc'],
                $connection->query('SELECT longest(word) AS value FROM words')->fetchRow(),
            );
        } finally {
            $connection->close();
        }
    }

    public function testCustomCollation(): void
    {
        $config = (new SqliteConfig(':memory:'))
            ->withCollation('by_length', [SqlCallables::class, 'compareByLength']);
        $connection = (new SqliteConnector())->connect($config);

        try {
            $connection->query('CREATE TABLE words (word TEXT)');
            $connection->execute('INSERT INTO words VALUES (?), (?), (?)', ['ccc', 'a', 'bb']);

            self::assertSame(
                [['word' => 'a'], ['word' => 'bb'], ['word' => 'ccc']],
                \iterator_to_array($connection->query('SELECT word FROM words ORDER BY word COLLATE by_length')),
            );
        } finally {
            $connection->close();
        }
    }

    #[DataProvider('provideInvalidRegistrations')]
    public function testRejectsInvalidRegistrations(\Closure $register): void
    {
        $this->expectException(\ValueError::class);

        $register(new SqliteConfig(':memory:'));
    }

    public static function provideInvalidRegistrations(): iterable
    {
        yield 'unknown callback' => [static fn (SqliteConfig $c) => $c->withFunction('f', 'NonExistent::method')];
        yield 'unknown array callback' => [static fn (SqliteConfig $c) => $c->withFunction('f', ['NonExistent', 'method'])];
        yield 'non-static method' => [static fn (SqliteConfig $c) => $c->withFunction('f', [SqlCallables::class, 'instanceMethod'])];
        yield 'malformed array callback' => [static fn (SqliteConfig $c) => $c->withFunction('f', ['strrev'])];
        yield 'invalid function name' => [static fn (SqliteConfig $c) => $c->withFunction('bad name!', 'strrev')];
        yield 'invalid collation name' => [static fn (SqliteConfig $c) => $c->withCollation('bad name!', 'strcmp')];
        yield 'unknown aggregate step' => [static fn (SqliteConfig $c) => $c->withAggregate('a', ['NonExistent', 'step'], 'strrev')];
    }
}
