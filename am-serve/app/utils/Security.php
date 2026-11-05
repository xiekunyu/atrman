<?php

namespace app\utils;

use app\exception\ApiException;

/**
 * 安全工具类
 *
 * 提供签名/验签（Ed25519 优先，回退 RSA-SHA256）与对称加解密（AES-256-CBC），
 * 用于应用市场包签名、License 授权校验与市场账号凭证的加密存储。
 *
 * 依赖：
 * - sodium 扩展（可选，提供 Ed25519）
 * - openssl 扩展（必需，提供 RSA 验签与 AES 加解密，系统安装环境检测已要求）
 */
class Security
{
    /** 签名算法：Ed25519（需 sodium） */
    public const ALG_ED25519 = 'ed25519';

    /** 签名算法：RSA-SHA256（openssl） */
    public const ALG_RSA_SHA256 = 'rsa-sha256';

    /** AES 加密方法 */
    protected const CIPHER = 'aes-256-cbc';

    /** 发布清单条目内字段分隔符（US 控制符，不会出现在版本号/哈希中，且显式校验） */
    public const ENTRY_SEP = "\x1f";

    /**
     * 检测当前环境支持的签名算法
     */
    public static function availableAlg(): string
    {
        return function_exists('sodium_crypto_sign_verify_detached')
            ? self::ALG_ED25519
            : self::ALG_RSA_SHA256;
    }

    /**
     * 生成签名密钥对（仅用于市场系统签发与本地 Mock 测试）
     *
     * @return array{private: string, public: string, alg: string}
     */
    public static function generateKeyPair(?string $alg = null): array
    {
        $alg = $alg ?: self::availableAlg();

        if ($alg === self::ALG_ED25519) {
            if (!function_exists('sodium_crypto_sign_keypair')) {
                throw new ApiException('当前环境不支持 Ed25519（缺少 sodium 扩展）');
            }
            $keyPair = sodium_crypto_sign_keypair();
            return [
                'private' => base64_encode(sodium_crypto_sign_secretkey($keyPair)),
                'public' => base64_encode(sodium_crypto_sign_publickey($keyPair)),
                'alg' => $alg,
            ];
        }

        $config = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];
        $resource = openssl_pkey_new($config);
        if (!$resource) {
            throw new ApiException('RSA 密钥生成失败：' . openssl_error_string());
        }
        openssl_pkey_export($resource, $privateKey);
        $details = openssl_pkey_get_details($resource);

        return [
            'private' => $privateKey,
            'public' => $details['key'],
            'alg' => self::ALG_RSA_SHA256,
        ];
    }

    /**
     * 使用私钥签名数据
     *
     * @param string $payload 待签名内容
     * @param string $privateKey 私钥（Ed25519 为 base64，RSA 为 PEM）
     * @param string $alg 算法
     * @return string base64 签名
     */
    public static function sign(string $payload, string $privateKey, string $alg = self::ALG_ED25519): string
    {
        if ($alg === self::ALG_ED25519) {
            if (!function_exists('sodium_crypto_sign_detached')) {
                throw new ApiException('当前环境不支持 Ed25519（缺少 sodium 扩展）');
            }
            $secret = base64_decode($privateKey, true);
            if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
                throw new ApiException('Ed25519 私钥格式不正确');
            }
            return base64_encode(sodium_crypto_sign_detached($payload, $secret));
        }

        $key = openssl_pkey_get_private($privateKey);
        if (!$key) {
            throw new ApiException('RSA 私钥格式不正确');
        }
        $signature = '';
        if (!openssl_sign($payload, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new ApiException('RSA 签名失败：' . openssl_error_string());
        }
        return base64_encode($signature);
    }

    /**
     * 使用公钥验证签名
     *
     * @param string $payload 原始内容
     * @param string $signature base64 签名
     * @param string $publicKey 公钥（Ed25519 为 base64，RSA 为 PEM）
     * @param string $alg 算法
     */
    public static function verify(string $payload, string $signature, string $publicKey, string $alg = self::ALG_ED25519): bool
    {
        $decoded = base64_decode($signature, true);
        if ($decoded === false) {
            return false;
        }

        if ($alg === self::ALG_ED25519) {
            if (!function_exists('sodium_crypto_sign_verify_detached')) {
                return false;
            }
            $public = base64_decode($publicKey, true);
            if ($public === false || strlen($public) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                return false;
            }
            try {
                return sodium_crypto_sign_verify_detached($decoded, $payload, $public);
            } catch (\Throwable) {
                return false;
            }
        }

        $key = openssl_pkey_get_public($publicKey);
        if (!$key) {
            return false;
        }
        return openssl_verify($payload, $decoded, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * 构造待签名的规范化内容（字段按 key 排序后拼接，保证签发/验签两端一致）
     *
     * @param array $data 参与签名的字段（不含 signature 本身）
     */
    public static function canonicalPayload(array $data): string
    {
        unset($data['signature']);
        ksort($data);
        $parts = [];
        foreach ($data as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $parts[] = $key . '=' . (string)$value;
        }
        return implode('&', $parts);
    }

    /**
     * AES-256-CBC 加密
     *
     * @param string $plainText 明文
     * @param string $key 加密密钥（任意长度，内部派生为 32 字节）
     * @return string base64(iv + ciphertext)
     */
    public static function encrypt(string $plainText, string $key): string
    {
        if (!function_exists('openssl_encrypt')) {
            throw new ApiException('当前环境缺少 openssl 扩展，无法加密存储凭证');
        }
        $derivedKey = hash('sha256', $key, true);
        $iv = random_bytes(16);
        $cipherText = openssl_encrypt($plainText, self::CIPHER, $derivedKey, OPENSSL_RAW_DATA, $iv);
        if ($cipherText === false) {
            throw new ApiException('凭证加密失败');
        }
        return base64_encode($iv . $cipherText);
    }

    /**
     * AES-256-CBC 解密
     *
     * @param string $encrypted base64(iv + ciphertext)
     * @param string $key 加密密钥
     * @return string|null 明文，解密失败返回 null
     */
    public static function decrypt(string $encrypted, string $key): ?string
    {
        if (!function_exists('openssl_decrypt')) {
            return null;
        }
        $raw = base64_decode($encrypted, true);
        if ($raw === false || strlen($raw) <= 16) {
            return null;
        }
        $derivedKey = hash('sha256', $key, true);
        $iv = substr($raw, 0, 16);
        $cipherText = substr($raw, 16);
        $plainText = openssl_decrypt($cipherText, self::CIPHER, $derivedKey, OPENSSL_RAW_DATA, $iv);
        return $plainText === false ? null : $plainText;
    }

    /**
     * 获取凭证加密密钥（从 JWT 密钥派生，密钥不进数据库）
     */
    public static function credentialKey(): string
    {
        $seed = (string)env('JWT_ACCESS_KEY', '');
        if ($seed === '') {
            $seed = (string)env('APP_KEY', '');
        }
        if ($seed === '') {
            throw new ApiException('缺少凭证加密密钥，请在 .env 中配置 JWT_ACCESS_KEY');
        }
        return hash_hkdf('sha256', $seed, 32, 'atrman-market-credential');
    }

    /**
     * 生成随机字符串（hex）
     */
    public static function randomHex(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /**
     * 计算文件 SHA256
     */
    public static function fileSha256(string $path): string
    {
        if (!is_file($path)) {
            throw new ApiException('文件不存在：' . $path);
        }
        return hash_file('sha256', $path);
    }

    /**
     * 是否为合法的 SHA256 十六进制串（大小写均可）。
     *
     * 这条判据同时决定「包进不进发布清单 / 能不能过审 / 换票是否放行 / 后台是否显示已登记」，
     * 只允许一处实现：某侧单独收紧会让 UI 显示已登记而换票被拒，或反之。
     */
    public static function isSha256(mixed $value): bool
    {
        return (bool)preg_match('/^[0-9a-fA-F]{64}$/', (string)$value);
    }

    /**
     * 目录内全部文件的聚合哈希，签名链路的唯一实现（基座与市场侧共用）。
     *
     * 逐字节规则，改动会导致已签发包的 files_hash 校验失败：
     * 递归遍历（SKIP_DOTS）→ 按 basename 排除 $exclude 中任意层级的同名文件
     * → 「相对路径(统一 / 分隔):文件sha256」逐行 → sort 升序 → sha256(implode("\n"))。
     */
    public static function filesHash(string $dir, array $exclude = ['signature.json', 'license.json']): string
    {
        $dir = rtrim($dir, '/\\');
        $lines = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (in_array($file->getBasename(), $exclude, true)) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
            $lines[] = $relative . ':' . hash_file('sha256', $file->getPathname());
        }
        sort($lines);
        return hash('sha256', implode("\n", $lines));
    }

    /**
     * 发布清单条目聚合哈希（签发端与消费端共用，逐字节规则不得单侧改动）。
     *
     * 逐条：5 个字段按固定顺序 → 不可打印分隔符 \x1F 连接 → 逐条 sha256
     *       → 全部 digest 升序 sort → sha256(implode("\n"))。
     * 与 filesHash 的区别：不碰文件，只哈希清单条目元数据，恒定微秒级；
     * 逐条哈希 + 全局排序使条目顺序无关，避免 canonicalPayload 对数组走
     * json_encode 时受键插入顺序影响。
     *
     * @param array $entries 每项含 version/package_type/delta_from/sha256/channel
     */
    public static function releaseEntriesHash(array $entries): string
    {
        $digests = [];
        foreach ($entries as $entry) {
            $version = (string)($entry['version'] ?? '');
            $deltaFrom = (string)($entry['delta_from'] ?? '');
            $sha256 = strtolower((string)($entry['sha256'] ?? ''));
            if ($version === '' || !preg_match('/^[0-9a-f]{64}$/', $sha256)) {
                throw new ApiException('发布清单条目非法（version/sha256）');
            }
            if (str_contains($version, self::ENTRY_SEP) || str_contains($deltaFrom, self::ENTRY_SEP)) {
                throw new ApiException('发布清单条目含非法分隔符');
            }
            $line = implode(self::ENTRY_SEP, [
                $version,
                (string)(int)($entry['package_type'] ?? 0),
                $deltaFrom,
                $sha256,
                (string)($entry['channel'] ?? 'stable'),
            ]);
            $digests[] = hash('sha256', $line);
        }
        sort($digests);
        return hash('sha256', implode("\n", $digests));
    }
}