<?php

$config = new class extends Amp\CodeStyle\Config {
    public function getRules(): array
    {
        return [
            ...parent::getRules(),
            'header_comment' => [
                'header' => <<<'EOF'
                    This file is part of the fabpot/amphp-sqlite3 package.

                    (c) Fabien Potencier <fabien@potencier.org>

                    For the full copyright and license information, please view the LICENSE
                    file that was distributed with this source code.
                    EOF,
            ],
        ];
    }
};
$config->getFinder()
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/test');

$config->setCacheFile(__DIR__ . '/.php_cs.cache');

return $config;
