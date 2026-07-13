<?php

declare(strict_types=1);

namespace Fabpot\Amp\Sqlite;

enum SqliteSynchronousMode: string
{
    case Automatic = 'automatic';
    case Off = 'off';
    case Normal = 'normal';
    case Full = 'full';
    case Extra = 'extra';
}
