<?php

declare(strict_types=1);

namespace Fabpot\Amp\Sqlite;

enum SqliteBlobMode
{
    case ReadOnly;
    case ReadWrite;
}
