<?php

namespace app\utils;

class ServerMonitor
{
    private const MAX_PROCESS_COUNT = 50;

    public static function monitor(): array
    {
        $diagnostics = [];
        try {
            $host = self::hostInfo($diagnostics);
            $cpu = self::cpuInfo($diagnostics);
            $memory = self::memoryInfo($diagnostics);
            $disk = self::diskInfo($diagnostics);
            $network = self::networkInfo($diagnostics);
            $php = self::phpRuntimeInfo($diagnostics);
            $processes = self::processInfo($diagnostics);
            self::resourceDiagnostics($diagnostics, $cpu, $memory, $disk, $php, $processes);
            $overview = self::overview($diagnostics, $host);

            return [
                'overview' => $overview,
                'cpu' => $cpu,
                'memory' => $memory,
                'disk' => $disk,
                'network' => $network,
                'host' => $host,
                'php' => $php,
                'processes' => $processes,
                'diagnostics' => $diagnostics,
            ];
        } catch (\Throwable $e) {
            return [
                'overview' => [
                    'status' => 'danger',
                    'statusText' => '采集异常',
                    'hostname' => gethostname() ?: php_uname('n'),
                    'platform' => PHP_OS_FAMILY,
                    'uptimeHuman' => '-',
                    'updatedAt' => date('Y-m-d H:i:s'),
                ],
                'cpu' => [],
                'memory' => [],
                'disk' => ['items' => []],
                'network' => ['interfaces' => []],
                'host' => [],
                'php' => self::phpRuntimeInfo($diagnostics),
                'processes' => self::fallbackProcessInfo(),
                'diagnostics' => [[
                    'level' => 'danger',
                    'title' => '采集异常',
                    'message' => $e->getMessage(),
                ]],
            ];
        }
    }

    private static function hostInfo(array &$diagnostics): array
    {
        $uptime = self::uptimeSeconds();
        $hostname = gethostname() ?: php_uname('n');
        $distribution = self::linuxDistribution();

        if (!self::procAvailable()) {
            self::addDiagnostic($diagnostics, 'warning', '部分指标不可用', '当前环境无法读取 /proc，CPU、内存、网络和进程指标可能不完整。');
        }

        return [
            'hostname' => $hostname,
            'os' => $distribution ?: php_uname('s'),
            'kernel' => php_uname('r'),
            'arch' => php_uname('m'),
            'uptime' => $uptime,
            'uptimeHuman' => $uptime !== null ? self::formatDuration($uptime) : '-',
            'bootTime' => $uptime !== null ? date('Y-m-d H:i:s', time() - $uptime) : null,
            'timezone' => date_default_timezone_get(),
            'serverTime' => date('Y-m-d H:i:s'),
        ];
    }

    private static function cpuInfo(array &$diagnostics): array
    {
        $cpuInfo = self::parseCpuInfo();
        $cores = $cpuInfo['cores'] ?: self::cpuCount();
        $loadAvg = self::loadAverage();
        $usage = self::cpuUsagePercent();
        if ($usage === null && self::procAvailable()) {
            self::addDiagnostic($diagnostics, 'info', 'CPU 使用率不可用', '无法从 /proc/stat 计算 CPU 使用率，已保留负载信息。');
        }

        return [
            'cores' => $cores,
            'model' => $cpuInfo['model'],
            'usagePercent' => $usage,
            'loadAvg' => $loadAvg,
            'loadPercent' => $cores > 0 && isset($loadAvg[0]) ? self::percent($loadAvg[0], $cores) : null,
        ];
    }

    private static function memoryInfo(array &$diagnostics): array
    {
        $data = self::parseMeminfo();
        if (!$data) {
            self::addDiagnostic($diagnostics, 'warning', '内存指标不可用', '无法读取 /proc/meminfo，内存使用率不可用。');
            return [
                'total' => null,
                'used' => null,
                'free' => null,
                'available' => null,
                'usedPercent' => null,
                'totalHuman' => '-',
                'usedHuman' => '-',
                'freeHuman' => '-',
                'availableHuman' => '-',
            ];
        }

        $total = $data['MemTotal'] ?? 0;
        $available = $data['MemAvailable'] ?? (($data['MemFree'] ?? 0) + ($data['Buffers'] ?? 0) + ($data['Cached'] ?? 0));
        $free = $data['MemFree'] ?? 0;
        $used = max(0, $total - $available);

        return [
            'total' => $total,
            'used' => $used,
            'free' => $free,
            'available' => $available,
            'usedPercent' => $total > 0 ? self::percent($used, $total) : null,
            'totalHuman' => self::formatBytes($total),
            'usedHuman' => self::formatBytes($used),
            'freeHuman' => self::formatBytes($free),
            'availableHuman' => self::formatBytes($available),
            'swapTotal' => $data['SwapTotal'] ?? 0,
            'swapUsed' => max(0, ($data['SwapTotal'] ?? 0) - ($data['SwapFree'] ?? 0)),
            'swapPercent' => ($data['SwapTotal'] ?? 0) > 0 ? self::percent(max(0, ($data['SwapTotal'] ?? 0) - ($data['SwapFree'] ?? 0)), $data['SwapTotal']) : 0,
        ];
    }

    private static function diskInfo(array &$diagnostics): array
    {
        $paths = self::diskPaths();
        $items = [];
        $seen = [];
        $total = 0;
        $free = 0;

        foreach ($paths as $item) {
            $path = $item['path'];
            if (!is_dir($path)) {
                continue;
            }
            $diskTotal = @disk_total_space($path);
            $diskFree = @disk_free_space($path);
            if ($diskTotal === false || $diskFree === false || $diskTotal <= 0) {
                continue;
            }
            $key = realpath($path) ?: $path;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $used = $diskTotal - $diskFree;
            $total += $diskTotal;
            $free += $diskFree;
            $items[] = [
                'path' => $path,
                'mount' => $item['mount'] ?? $path,
                'device' => $item['device'] ?? '',
                'filesystem' => $item['filesystem'] ?? '',
                'total' => (int)$diskTotal,
                'used' => (int)$used,
                'free' => (int)$diskFree,
                'usedPercent' => self::percent($used, $diskTotal),
                'totalHuman' => self::formatBytes($diskTotal),
                'usedHuman' => self::formatBytes($used),
                'freeHuman' => self::formatBytes($diskFree),
            ];
        }

        if (!$items) {
            self::addDiagnostic($diagnostics, 'warning', '磁盘指标不可用', '无法读取可用磁盘分区信息。');
        }

        $used = max(0, $total - $free);
        return [
            'total' => $total,
            'used' => $used,
            'free' => $free,
            'usedPercent' => $total > 0 ? self::percent($used, $total) : null,
            'totalHuman' => self::formatBytes($total),
            'usedHuman' => self::formatBytes($used),
            'freeHuman' => self::formatBytes($free),
            'items' => $items,
        ];
    }

    private static function networkInfo(array &$diagnostics): array
    {
        $content = self::readFile('/proc/net/dev');
        if ($content === null) {
            self::addDiagnostic($diagnostics, 'info', '网络指标不可用', '无法读取 /proc/net/dev，网络接口数据不可用。');
            return [
                'interfaces' => [],
                'totalRx' => 0,
                'totalTx' => 0,
                'totalRxHuman' => '0 B',
                'totalTxHuman' => '0 B',
            ];
        }

        $interfaces = [];
        $totalRx = 0;
        $totalTx = 0;
        foreach (explode("\n", $content) as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $stats] = explode(':', $line, 2);
            $name = trim($name);
            if ($name === '' || $name === 'lo') {
                continue;
            }
            $values = preg_split('/\s+/', trim($stats));
            if (count($values) < 16) {
                continue;
            }
            $rx = (int)$values[0];
            $tx = (int)$values[8];
            $totalRx += $rx;
            $totalTx += $tx;
            $interfaces[] = [
                'name' => $name,
                'rxBytes' => $rx,
                'txBytes' => $tx,
                'rxHuman' => self::formatBytes($rx),
                'txHuman' => self::formatBytes($tx),
                'rxPackets' => (int)$values[1],
                'txPackets' => (int)$values[9],
                'rxErrors' => (int)$values[2],
                'txErrors' => (int)$values[10],
                'rxDropped' => (int)$values[3],
                'txDropped' => (int)$values[11],
            ];
        }

        return [
            'interfaces' => $interfaces,
            'totalRx' => $totalRx,
            'totalTx' => $totalTx,
            'totalRxHuman' => self::formatBytes($totalRx),
            'totalTxHuman' => self::formatBytes($totalTx),
        ];
    }

    private static function phpRuntimeInfo(array &$diagnostics): array
    {
        $opcacheEnabled = null;
        if (function_exists('opcache_get_status')) {
            $status = @opcache_get_status(false);
            $opcacheEnabled = is_array($status) ? (bool)($status['opcache_enabled'] ?? false) : false;
        }
        if ($opcacheEnabled === false) {
            self::addDiagnostic($diagnostics, 'info', 'OPcache 未启用', '当前 PHP 运行环境未启用 OPcache 或 CLI 下不可用。');
        }

        return [
            'version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'binary' => PHP_BINARY,
            'iniLoadedFile' => php_ini_loaded_file() ?: '',
            'memoryLimit' => (string)ini_get('memory_limit'),
            'maxExecutionTime' => (string)ini_get('max_execution_time'),
            'uploadMaxFilesize' => (string)ini_get('upload_max_filesize'),
            'postMaxSize' => (string)ini_get('post_max_size'),
            'opcacheEnabled' => $opcacheEnabled,
            'extensionsCount' => count(get_loaded_extensions()),
            'currentMemory' => memory_get_usage(true),
            'currentMemoryHuman' => self::formatBytes(memory_get_usage(true)),
            'peakMemory' => memory_get_peak_usage(true),
            'peakMemoryHuman' => self::formatBytes(memory_get_peak_usage(true)),
        ];
    }

    private static function processInfo(array &$diagnostics): array
    {
        if (!self::procAvailable()) {
            self::addDiagnostic($diagnostics, 'warning', 'PHP 进程枚举不可用', '当前环境无法读取 /proc，仅返回当前 PHP 进程信息。');
            return self::fallbackProcessInfo();
        }

        $list = [];
        foreach (glob('/proc/[0-9]*') ?: [] as $dir) {
            $pid = (int)basename($dir);
            $cmdline = str_replace("\0", ' ', trim((string)self::readFile($dir . '/cmdline')));
            $comm = trim((string)self::readFile($dir . '/comm'));
            $haystack = strtolower($cmdline . ' ' . $comm);
            if (!str_contains($haystack, 'php') && !str_contains($haystack, 'start.php') && !str_contains($haystack, 'webman') && !str_contains($haystack, 'workerman')) {
                continue;
            }
            $status = self::parseStatusFile($dir . '/status');
            $isWebman = str_contains($haystack, 'start.php') || str_contains($haystack, 'webman') || str_contains($haystack, 'workerman');
            $rss = (int)($status['VmRSS'] ?? 0);
            $list[] = [
                'pid' => $pid,
                'name' => $comm ?: 'php',
                'type' => $pid === getmypid() ? 'current' : ($isWebman ? 'webman' : 'php'),
                'status' => $status['State'] ?? '',
                'user' => $status['Uid'] ?? null,
                'memoryRss' => $rss,
                'memoryRssHuman' => self::formatBytes($rss),
                'command' => self::sanitizeCommand($cmdline ?: $comm),
                'current' => $pid === getmypid(),
                'webman' => $isWebman,
            ];
        }

        usort($list, function ($a, $b) {
            if (($a['current'] ?? false) !== ($b['current'] ?? false)) {
                return ($a['current'] ?? false) ? -1 : 1;
            }
            if (($a['webman'] ?? false) !== ($b['webman'] ?? false)) {
                return ($a['webman'] ?? false) ? -1 : 1;
            }
            return ($b['memoryRss'] ?? 0) <=> ($a['memoryRss'] ?? 0);
        });

        $totalMemory = array_sum(array_column($list, 'memoryRss'));
        $webmanTotal = count(array_filter($list, fn($item) => !empty($item['webman'])));
        if (!$list) {
            self::addDiagnostic($diagnostics, 'warning', 'PHP 进程未识别', '未能从 /proc 识别到 PHP 进程，已返回当前进程兜底信息。');
            return self::fallbackProcessInfo();
        }

        return [
            'php' => [
                'total' => count($list),
                'running' => count(array_filter($list, fn($item) => str_contains((string)$item['status'], 'running') || str_contains((string)$item['status'], 'R'))),
                'memoryRss' => $totalMemory,
                'memoryRssHuman' => self::formatBytes($totalMemory),
            ],
            'webman' => [
                'total' => $webmanTotal,
            ],
            'current' => current(array_filter($list, fn($item) => !empty($item['current']))) ?: null,
            'list' => array_slice($list, 0, self::MAX_PROCESS_COUNT),
        ];
    }

    private static function fallbackProcessInfo(): array
    {
        $current = [
            'pid' => getmypid(),
            'name' => 'php',
            'type' => 'current',
            'status' => 'current',
            'user' => get_current_user(),
            'memoryRss' => memory_get_usage(true),
            'memoryRssHuman' => self::formatBytes(memory_get_usage(true)),
            'command' => PHP_BINARY,
            'current' => true,
            'webman' => false,
        ];

        return [
            'php' => [
                'total' => 1,
                'running' => 1,
                'memoryRss' => memory_get_usage(true),
                'memoryRssHuman' => self::formatBytes(memory_get_usage(true)),
            ],
            'webman' => ['total' => 0],
            'current' => $current,
            'list' => [$current],
        ];
    }

    private static function overview(array $diagnostics, array $host): array
    {
        $levels = array_column($diagnostics, 'level');
        if (in_array('danger', $levels, true) || in_array('error', $levels, true)) {
            $status = 'danger';
            $statusText = '资源紧张';
        } elseif (in_array('warning', $levels, true)) {
            $status = 'warning';
            $statusText = '需关注';
        } else {
            $status = 'healthy';
            $statusText = '运行正常';
        }

        return [
            'status' => $status,
            'statusText' => $statusText,
            'hostname' => $host['hostname'] ?? '',
            'platform' => PHP_OS_FAMILY,
            'uptimeHuman' => $host['uptimeHuman'] ?? '-',
            'updatedAt' => date('Y-m-d H:i:s'),
        ];
    }

    private static function resourceDiagnostics(array &$diagnostics, array $cpu, array $memory, array $disk, array $php, array $processes): void
    {
        self::thresholdDiagnostic($diagnostics, 'CPU 使用率', $cpu['usagePercent'] ?? null, 70, 85);
        self::thresholdDiagnostic($diagnostics, '内存使用率', $memory['usedPercent'] ?? null, 80, 90);
        self::thresholdDiagnostic($diagnostics, '磁盘使用率', $disk['usedPercent'] ?? null, 80, 90);
        if (($processes['php']['total'] ?? 0) <= 0) {
            self::addDiagnostic($diagnostics, 'warning', 'PHP 进程异常', '未检测到 PHP 进程信息，请检查 /proc 权限或运行环境。');
        }
        if (!$diagnostics) {
            self::addDiagnostic($diagnostics, 'success', '服务器指标正常', '当前 CPU、内存、磁盘和 PHP 进程均处于可控范围。');
        }
    }

    private static function thresholdDiagnostic(array &$diagnostics, string $title, mixed $value, int $warning, int $danger): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $value = (float)$value;
        if ($value >= $danger) {
            self::addDiagnostic($diagnostics, 'danger', $title . '过高', $title . '已达到 ' . $value . '%，建议立即排查资源占用。');
        } elseif ($value >= $warning) {
            self::addDiagnostic($diagnostics, 'warning', $title . '偏高', $title . '当前为 ' . $value . '%，建议持续关注。');
        }
    }

    private static function parseCpuInfo(): array
    {
        $content = self::readFile('/proc/cpuinfo');
        if ($content === null) {
            return ['model' => php_uname('m'), 'cores' => self::cpuCount()];
        }
        preg_match('/model name\s*:\s*(.+)/', $content, $match);
        preg_match_all('/^processor\s*:/m', $content, $processors);
        return [
            'model' => trim($match[1] ?? php_uname('m')),
            'cores' => count($processors[0] ?? []) ?: self::cpuCount(),
        ];
    }

    private static function cpuUsagePercent(): ?float
    {
        $first = self::cpuStat();
        if (!$first) {
            return null;
        }
        usleep(120000);
        $second = self::cpuStat();
        if (!$second) {
            return null;
        }
        $totalDelta = $second['total'] - $first['total'];
        $idleDelta = $second['idle'] - $first['idle'];
        if ($totalDelta <= 0) {
            return null;
        }
        return round((1 - $idleDelta / $totalDelta) * 100, 2);
    }

    private static function cpuStat(): ?array
    {
        $content = self::readFile('/proc/stat');
        if (!$content || !preg_match('/^cpu\s+(.+)$/m', $content, $match)) {
            return null;
        }
        $values = array_map('intval', preg_split('/\s+/', trim($match[1])));
        $idle = ($values[3] ?? 0) + ($values[4] ?? 0);
        return ['idle' => $idle, 'total' => array_sum($values)];
    }

    private static function parseMeminfo(): array
    {
        $content = self::readFile('/proc/meminfo');
        if ($content === null) {
            return [];
        }
        $data = [];
        foreach (explode("\n", $content) as $line) {
            if (preg_match('/^([A-Za-z_()]+):\s+(\d+)/', $line, $match)) {
                $data[$match[1]] = (int)$match[2] * 1024;
            }
        }
        return $data;
    }

    private static function diskPaths(): array
    {
        $items = [];
        $content = self::readFile('/proc/mounts');
        $ignored = ['proc', 'sysfs', 'tmpfs', 'devtmpfs', 'devpts', 'cgroup', 'cgroup2', 'securityfs', 'debugfs', 'tracefs', 'fusectl', 'mqueue', 'hugetlbfs', 'squashfs'];
        if ($content !== null) {
            foreach (explode("\n", $content) as $line) {
                $parts = preg_split('/\s+/', trim($line));
                if (count($parts) < 3 || in_array($parts[2], $ignored, true)) {
                    continue;
                }
                $items[] = ['path' => str_replace('\\040', ' ', $parts[1]), 'mount' => str_replace('\\040', ' ', $parts[1]), 'device' => $parts[0], 'filesystem' => $parts[2]];
            }
        }

        if (!$items) {
            $items[] = ['path' => DIRECTORY_SEPARATOR === '/' ? '/' : getcwd(), 'mount' => DIRECTORY_SEPARATOR === '/' ? '/' : getcwd(), 'device' => '', 'filesystem' => ''];
        }
        return $items;
    }

    private static function parseStatusFile(string $path): array
    {
        $content = self::readFile($path);
        if ($content === null) {
            return [];
        }
        $data = [];
        foreach (explode("\n", $content) as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = explode(':', $line, 2);
            $value = trim($value);
            if (in_array($key, ['VmRSS', 'VmSize', 'VmPeak'], true)) {
                $data[$key] = (int)$value * 1024;
            } else {
                $data[$key] = $value;
            }
        }
        return $data;
    }

    private static function loadAverage(): array
    {
        if (function_exists('sys_getloadavg')) {
            $load = @sys_getloadavg();
            if (is_array($load)) {
                return array_map(fn($item) => round((float)$item, 2), $load);
            }
        }
        $content = self::readFile('/proc/loadavg');
        if ($content === null) {
            return [];
        }
        return array_map('floatval', array_slice(preg_split('/\s+/', trim($content)), 0, 3));
    }

    private static function uptimeSeconds(): ?int
    {
        $content = self::readFile('/proc/uptime');
        if ($content === null) {
            return null;
        }
        return (int)floor((float)explode(' ', trim($content))[0]);
    }

    private static function linuxDistribution(): string
    {
        $content = self::readFile('/etc/os-release');
        if ($content === null || !preg_match('/^PRETTY_NAME=(.+)$/m', $content, $match)) {
            return '';
        }
        return trim($match[1], "\"'");
    }

    private static function cpuCount(): int
    {
        if (function_exists('cpu_count')) {
            return (int)cpu_count();
        }
        return 1;
    }

    private static function procAvailable(): bool
    {
        return DIRECTORY_SEPARATOR === '/' && is_dir('/proc') && is_readable('/proc');
    }

    private static function readFile(string $path): ?string
    {
        if (!is_readable($path)) {
            return null;
        }
        $content = @file_get_contents($path);
        return $content === false ? null : $content;
    }

    private static function sanitizeCommand(string $command): string
    {
        $command = preg_replace('/(password|token|secret|key)=\S+/i', '$1=******', $command) ?: $command;
        return mb_strlen($command) > 300 ? mb_substr($command, 0, 300) . '...' : $command;
    }

    private static function addDiagnostic(array &$diagnostics, string $level, string $title, string $message): void
    {
        $diagnostics[] = compact('level', 'title', 'message');
    }

    private static function percent(float|int $used, float|int $total): float
    {
        return $total > 0 ? round($used / $total * 100, 2) : 0;
    }

    private static function formatBytes(float|int|null $bytes): string
    {
        if (!$bytes || $bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $index = 0;
        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }
        return round($bytes, $index === 0 ? 0 : 2) . ' ' . $units[$index];
    }

    private static function formatDuration(int $seconds): string
    {
        $days = intdiv($seconds, 86400);
        $seconds %= 86400;
        $hours = intdiv($seconds, 3600);
        $seconds %= 3600;
        $minutes = intdiv($seconds, 60);
        $parts = [];
        if ($days > 0) {
            $parts[] = $days . '天';
        }
        if ($hours > 0) {
            $parts[] = $hours . '小时';
        }
        $parts[] = $minutes . '分钟';
        return implode(' ', $parts);
    }
}
