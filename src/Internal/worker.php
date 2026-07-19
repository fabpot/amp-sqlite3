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

namespace Fabpot\Amp\Sqlite\Internal;

use Amp\Sync\Channel;

return static function (Channel $channel): null {
    /** @var array<string, mixed> $open */
    $open = $channel->receive();

    /** @psalm-suppress ArgumentTypeCoercion */
    $worker = new WorkerProcess($open);

    $channel->send(['ready' => true]);

    while (($request = $channel->receive()) !== null) {
        try {
            $value = $worker->handle($request);
            $channel->send(['id' => $request['id'], 'value' => $value]);

            if ($worker->isClosed()) {
                return null;
            }
        } catch (ProtocolError $error) {
            $channel->send([
                'id' => $request['id'],
                'protocol_error' => ['message' => $error->getMessage()],
            ]);

            break;
        } catch (\SQLite3Exception $exception) {
            $channel->send([
                'id' => $request['id'],
                'query_error' => [
                    'message' => $exception->getMessage(),
                    'code' => $exception->getCode() & 0xFF,
                    'extended_code' => $worker->getLastExtendedErrorCode(),
                ],
            ]);
        } catch (\Throwable $exception) {
            $channel->send([
                'id' => $request['id'],
                'query_error' => [
                    'message' => $exception->getMessage(),
                    'code' => 0,
                    'extended_code' => 0,
                ],
            ]);
        }
    }

    $worker->shutdown();

    return null;
};
