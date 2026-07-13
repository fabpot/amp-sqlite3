<?php

declare(strict_types=1);

namespace Fabpot\Amp\Sqlite;

enum SqliteOpenMode
{
    case ReadOnly;
    case ReadWrite;
    case ReadWriteCreate;
}
