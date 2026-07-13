<?php

declare(strict_types=1);

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
