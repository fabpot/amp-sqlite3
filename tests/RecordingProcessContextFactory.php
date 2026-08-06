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

use Amp\Cancellation;
use Amp\Parallel\Context\Context;
use Amp\Parallel\Context\ContextFactory;
use Amp\Parallel\Context\ProcessContext;
use Amp\Parallel\Context\ProcessContextFactory;

final class RecordingProcessContextFactory implements ContextFactory
{
    /** @var ProcessContext<null, mixed, array<string, mixed>> */
    public ProcessContext $context;

    /** @return Context<null, mixed, array<string, mixed>> */
    public function start(string|array $script, ?Cancellation $cancellation = null): Context
    {
        return $this->context = (new ProcessContextFactory())->start($script, $cancellation);
    }
}
