<?php

namespace app\service\plugin;

use app\exception\ApiException;
use support\Log;
use ZipArchive;

/**
 * 插件备份恢复包签名服务（HMAC-SHA256，对称密钥）。
 *
 * 与市场安装包的 Ed25519 非对称链（ReleaseManifestService）不同，备份包在同一台站点内
 * 自产自消：备份时用 .env 的 RESTORE_PACKAGE_KEY 对「全包文件 sha256 清单」做 HMAC，
 * 恢复时先验签、再逐文件比对哈希，全部通过才允许解压落地。
 *
 * 安全口径：
 * - 密钥缺失/格式非法一律 fail-closed（备份、恢复都拒绝执行），不静默降级；
 * - 验签直接读 zip 条目（ZipArchive::getFromName / zip:// 流哈希），不落盘、不解压，
 *   满足「先验签再解压」；
 * - 包内没有 restore-manifest.json 视为历史遗留未签名包（unsigned），
 *   是否放行由调用方按显式 LEGACY_UNSIGNED 确认决定，放行必须留审计。
 */
class RestoreManifestService
{
    /** 备份包根目录下的清单文件名（与插件目录内的 manifest.json 不同名，不会被 findPackageSourceDir 误匹配） */
    public const MANIFEST_FILE = 'restore-manifest.json';

    /** 历史未签名包的显式放行确认语（客户端必须原样回传） */
    public const LEGACY_CONFIRM = 'LEGACY_UNSIGNED';

    public const SCHEMA = 1;

    public const ALG = 'HMAC-SHA256';

    /** 验签结论：签名与文件哈希全部通过 */
    public const TRUSTED = 'trusted';

    /** 验签结论：历史包，包内无清单（未签名） */
    public const UNSIGNED = 'unsigned';

    /** 验签结论：清单存在但签名/哈希/结构不符，硬拒 */
    public const INVALID = 'invalid';

    /** 进程内 memo：常驻进程下密钥装配一次即固定，同 JwtKeyGuard 口径 */
    private static ?string $binaryKey = null;

    /**
     * 取 HMAC 二进制密钥；缺失或非 64 位十六进制即 fail-closed。
     */
    public static function key(): string
    {
        if (self::$binaryKey !== null) {
            return self::$binaryKey;
        }
        $hex = trim((string)env('RESTORE_PACKAGE_KEY', ''));
        if ($hex === '' || !preg_match('/^[0-9a-fA-F]{64}$/', $hex)) {
            Log::error('[RestoreManifest] RESTORE_PACKAGE_KEY 未配置或格式非法（需 64 位十六进制），备份/恢复功能已熔断');
            throw new ApiException('备份包签名密钥未配置（RESTORE_PACKAGE_KEY），备份与恢复功能不可用，请检查 .env');
        }
        return self::$binaryKey = (string)hex2bin($hex);
    }

    /**
     * 为待压缩的工作目录生成签名清单（不写盘）。
     *
     * @param string $workRoot 备份工作根目录（其下一级为 {pluginName}/ 插件目录）
     * @param string $plugin 插件标识
     * @param string $version 插件版本
     * @param int $operatorUid 操作者 uid（CLI 场景为 0）
     * @return array 可直接 json_encode 的清单结构
     */
    public static function buildManifest(string $workRoot, string $plugin, string $version, int $operatorUid): array
    {
        $files = self::collectFiles($workRoot);
        $manifest = [
            'schema' => self::SCHEMA,
            'alg' => self::ALG,
            'plugin' => $plugin,
            'version' => $version,
            'created_at' => time(),
            'operator_uid' => $operatorUid,
            'files' => $files,
        ];
        $manifest['signature'] = hash_hmac('sha256', self::canonical($manifest), self::key());
        return $manifest;
    }

    /**
     * 校验备份包（先验签再解压：全程只读 zip，不向磁盘写任何包内文件）。
     *
     * @param string $zipPath 备份包绝对路径
     * @return array{status:string,reason:string,manifest:?array}
     *               status=trusted 时 manifest 为解析后的清单；unsigned/invalid 时为 null
     */
    public static function verifyZip(string $zipPath): array
    {
        if (!is_file($zipPath)) {
            return ['status' => self::INVALID, 'reason' => '备份包不存在', 'manifest' => null];
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['status' => self::INVALID, 'reason' => '备份包打开失败', 'manifest' => null];
        }

        $raw = $zip->getFromName(self::MANIFEST_FILE);
        if ($raw === false) {
            $zip->close();
            // 包内无清单：历史遗留未签名包，交调用方按显式确认决定
            return ['status' => self::UNSIGNED, 'reason' => '备份包缺少签名清单（' . self::MANIFEST_FILE . '），属历史未签名包', 'manifest' => null];
        }
        $zip->close();

        // 清单存在即必须验过：密钥缺失在此处 fail-closed 抛出
        $manifest = json_decode((string)$raw, true);
        if (!is_array($manifest) || (int)($manifest['schema'] ?? 0) !== self::SCHEMA) {
            return ['status' => self::INVALID, 'reason' => '备份包签名清单格式不受支持', 'manifest' => null];
        }
        // alg 不参与协商：只接受签发端固定的 HMAC-SHA256，防伪造清单声明弱算法
        if ((string)($manifest['alg'] ?? '') !== self::ALG) {
            return ['status' => self::INVALID, 'reason' => '备份包签名算法不受支持', 'manifest' => null];
        }

        $signature = (string)($manifest['signature'] ?? '');
        if ($signature === '' || !preg_match('/^[0-9a-fA-F]{64}$/', $signature)) {
            return ['status' => self::INVALID, 'reason' => '备份包签名缺失或格式非法', 'manifest' => null];
        }

        $expected = hash_hmac('sha256', self::canonical($manifest), self::key());
        if (!hash_equals($expected, strtolower($signature))) {
            return ['status' => self::INVALID, 'reason' => '备份包 HMAC 签名验证失败（包可能已被篡改或密钥已更换）', 'manifest' => null];
        }

        $fileError = self::verifyFiles($zipPath, (array)($manifest['files'] ?? []));
        if ($fileError !== null) {
            return ['status' => self::INVALID, 'reason' => $fileError, 'manifest' => null];
        }

        return ['status' => self::TRUSTED, 'reason' => '', 'manifest' => $manifest];
    }

    /**
     * 逐文件比对 zip 实际内容与清单哈希：缺文件、多文件、哈希不符一律拒。
     */
    private static function verifyFiles(string $zipPath, array $listedFiles): ?string
    {
        $listed = [];
        foreach ($listedFiles as $item) {
            $path = str_replace('\\', '/', (string)($item['path'] ?? ''));
            $sha = strtolower((string)($item['sha256'] ?? ''));
            if ($path === '' || $path === self::MANIFEST_FILE || !preg_match('/^[0-9a-f]{64}$/', $sha)) {
                return '备份包签名清单条目非法';
            }
            if (isset($listed[$path])) {
                return '备份包签名清单存在重复条目：' . $path;
            }
            $listed[$path] = $sha;
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return '备份包打开失败';
        }

        $actual = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                continue;
            }
            $name = str_replace('\\', '/', (string)($stat['name'] ?? ''));
            if ($name === '' || str_ends_with($name, '/')) {
                continue; // 目录条目
            }
            if ($name === self::MANIFEST_FILE) {
                continue; // 清单自身不参与文件哈希
            }
            $actual[$name] = true;
        }
        $zip->close();

        // 多文件：zip 里出现清单未覆盖的条目
        foreach (array_keys($actual) as $name) {
            if (!array_key_exists($name, $listed)) {
                return '备份包存在签名清单未覆盖的文件：' . $name;
            }
        }
        // 缺文件 / 哈希不符：zip:// 流逐字节哈希，避免整包读进内存
        foreach ($listed as $path => $sha) {
            if (!array_key_exists($path, $actual)) {
                return '备份包缺少清单登记的文件：' . $path;
            }
            $actualHash = hash_file('sha256', 'zip://' . $zipPath . '#' . $path);
            if ($actualHash === false || !hash_equals($sha, strtolower((string)$actualHash))) {
                return '备份包文件哈希与签名清单不一致：' . $path;
            }
        }

        return null;
    }

    /**
     * 递归收集目录内全部文件的 [path => 相对路径, sha256 => 哈希]，按相对路径排序。
     */
    private static function collectFiles(string $root): array
    {
        $root = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root), DIRECTORY_SEPARATOR);
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isDir()) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $files[] = ['path' => $relative, 'sha256' => strtolower((string)hash_file('sha256', $file->getPathname()))];
        }
        usort($files, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));
        return $files;
    }

    /**
     * 规范化签名载荷：递归按键名排序后 JSON 编码，签发端与验签端逐字节一致。
     * signature 字段本身不参与签名。
     */
    private static function canonical(array $manifest): string
    {
        unset($manifest['signature']);
        self::recursiveKsort($manifest);
        return (string)json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function recursiveKsort(array &$data): void
    {
        ksort($data);
        foreach ($data as &$value) {
            if (is_array($value)) {
                self::recursiveKsort($value);
            }
        }
        unset($value);
    }
}
