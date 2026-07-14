<?php

declare(strict_types=1);

use Amp\Sync\Channel;
use Fabpot\Amp\Sqlite\Internal\ProtocolError;
use Fabpot\Amp\Sqlite\Internal\WorkerProcess;

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
        } catch (SQLite3Exception $exception) {
            $channel->send([
                'id' => $request['id'],
                'query_error' => [
                    'message' => $exception->getMessage(),
                    'code' => $exception->getCode() & 0xFF,
                    'extended_code' => $worker->getLastExtendedErrorCode(),
                ],
            ]);
        } catch (Throwable $exception) {
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
