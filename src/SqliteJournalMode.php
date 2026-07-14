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

enum SqliteJournalMode: string
{
    case Automatic = 'automatic';
    case Wal = 'wal';
    case Delete = 'delete';
    case Truncate = 'truncate';
    case Persist = 'persist';
    case Memory = 'memory';
    case Off = 'off';
}
