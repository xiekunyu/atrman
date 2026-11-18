<?php
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

// 文本行格式 handler（原有默认，参数保持不变）
$textHandler = [
    'class' => Monolog\Handler\RotatingFileHandler::class,
    'constructor' => [
        runtime_path() . '/logs/webman.log',
        7, //$maxFiles
        Monolog\Logger::DEBUG,
    ],
    'formatter' => [
        'class' => Monolog\Formatter\LineFormatter::class,
        'constructor' => [null, 'Y-m-d H:i:s', true],
    ],
];

// 单行 JSON handler：message/level/context 随 JSON 自然结构化，供 ELK/Loki 等采集；
// JsonFormatter 默认 appendNewline=true，一条日志一行
$jsonHandler = [
    'class' => Monolog\Handler\RotatingFileHandler::class,
    'constructor' => [
        runtime_path() . '/logs/webman.log',
        7, //$maxFiles
        Monolog\Logger::DEBUG,
    ],
    'formatter' => [
        'class' => Monolog\Formatter\JsonFormatter::class,
        'constructor' => [],
    ],
];

return [
    // LOG_JSON=true 时 default 通道切到 JSON handler，现有 Log::xxx() 调用零改动；
    // 缺省/false 维持原文本格式
    'default' => [
        'handlers' => [
            filter_var(env('LOG_JSON', 'false'), FILTER_VALIDATE_BOOLEAN) ? $jsonHandler : $textHandler,
        ],
    ],
    // 文本文件命名通道：JSON 模式下仍可通过 Log::channel('file') 显式写文本
    'file' => [
        'handlers' => [
            $textHandler,
        ],
    ],
];
