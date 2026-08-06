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
    $worker = new WorkerProcess($channel->receive());

    $channel->send(['ready' => true]);

    while (($message = $channel->receive()) !== null) {
        if (!\is_array($message) || !\is_int($message['id'] ?? null) || $message['id'] < 1) {
            break;
        }

        $id = (int) $message['id'];
        /** @var array<string, mixed> $request */
        $request = $message;
        $isQueryOperation = \is_string($request['operation'] ?? null) && \in_array($request['operation'], [
            'prepare',
            'fetch',
            'execute',
            'executeStatement',
            'executeScript',
        ], true);

        try {
            $channel->send(['id' => $id, 'value' => $worker->handle($request)]);

            if ($worker->isClosed()) {
                return null;
            }
        } catch (ProtocolError $error) {
            $channel->send([
                'id' => $id,
                'protocol_error' => ['message' => $error->getMessage()],
            ]);

            break;
        } catch (\SQLite3Exception $exception) {
            if ($isQueryOperation) {
                $channel->send([
                    'id' => $id,
                    'query_error' => [
                        'message' => $exception->getMessage(),
                        'code' => $exception->getCode() & 0xFF,
                        'extended_code' => $worker->getLastExtendedErrorCode(),
                    ],
                ]);
            } else {
                $channel->send([
                    'id' => $id,
                    'operation_error' => [
                        'message' => $exception->getMessage(),
                        'code' => $exception->getCode() & 0xFF,
                    ],
                ]);
            }
        } catch (\Throwable $exception) {
            if ($isQueryOperation) {
                $channel->send([
                    'id' => $id,
                    'query_error' => [
                        'message' => $exception->getMessage(),
                        'code' => null,
                        'extended_code' => null,
                    ],
                ]);
            } else {
                $channel->send([
                    'id' => $id,
                    'operation_error' => [
                        'message' => $exception->getMessage(),
                        'code' => null,
                    ],
                ]);
            }
        }
    }

    $worker->shutdown();

    return null;
};
