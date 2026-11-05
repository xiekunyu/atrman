<?php

namespace app\service\provider;

use app\exception\ApiException;
use GuzzleHttp\Client;

/**
 * 远程数据源提供者
 *
 * 对接远程应用市场系统 API（config/store.php 配置地址）。
 * 使用 Guzzle 发起请求，市场账号 token 通过 Header 携带。
 *
 * 约定的市场 API（与新系统对接时保持一致）：
 * - GET  /api/v1/apps                    应用列表
 * - GET  /api/v1/apps/{id}               应用详情
 * - GET  /api/v1/apps/{id}/versions      版本历史
 * - GET  /api/v1/categories              分类
 * - GET  /api/v1/releases                发布清单 {plugin, since_revision}（匿名，带 ETag）
 * - POST /api/v1/apps/check-update       批量检查更新 {apps: {name: version}}
 * - GET  /api/v1/system/update           系统更新检查
 * - POST /api/v1/auth/login              市场账号登录
 * - POST /api/v1/auth/refresh            凭证续期
 * - POST /api/v1/auth/logout             注销会话
 * - POST /api/v1/download-ticket         下载凭证 {app_id, instance_id, domain}
 * - POST /api/v1/license/heartbeat       License 心跳 {license_id, plugin, version, instance_id}
 */
class RemoteStoreProvider implements StoreProvider
{
    protected string $baseUrl;
    protected int $timeout;
    protected ?Client $client = null;

    public function __construct()
    {
        $this->baseUrl = rtrim((string)config('store.remote_url', ''), '/');
        $this->timeout = (int)config('store.remote_timeout', 30);

        if ($this->baseUrl === '') {
            throw new ApiException('远程应用插件地址未配置（config/store.php 或 STORE_REMOTE_URL）');
        }
    }

    public function name(): string
    {
        return 'remote';
    }

    public function list(array $params = []): array
    {
        $response = $this->request('GET', '/api/v1/apps', ['query' => $params]);
        return $response['data'] ?? [];
    }

    public function detail(string $appId): ?array
    {
        $response = $this->request('GET', '/api/v1/apps/' . rawurlencode($appId));
        return $response['data'] ?? null;
    }

    public function checkName(string $name): array
    {
        $response = $this->request('GET', '/api/v1/apps/check-name', ['query' => ['name' => $name]]);
        $data = $response['data'] ?? [];
        return [
            'available' => (bool)($data['available'] ?? true),
            'reason' => (string)($data['reason'] ?? ''),
            'message' => (string)($data['message'] ?? ''),
        ];
    }

    public function categories(): array
    {
        $response = $this->request('GET', '/api/v1/categories');
        return $response['data'] ?? [];
    }

    public function versions(string $appId): array
    {
        $response = $this->request('GET', '/api/v1/apps/' . rawurlencode($appId) . '/versions');
        return $response['data'] ?? [];
    }

    public function checkUpdate(array $installed): array
    {
        $versions = [];
        foreach ($installed as $plugin) {
            $versions[(string)$plugin['name']] = (string)$plugin['version'];
        }
        $response = $this->request('POST', '/api/v1/apps/check-update', ['json' => ['apps' => $versions]]);
        return $response['data'] ?? [];
    }

    public function systemUpdate(string $currentVersion): ?array
    {
        $response = $this->request('GET', '/api/v1/system/update', ['query' => ['version' => $currentVersion]]);
        return $response['data'] ?? null;
    }

    // ─────────────────────── 市场账号 ───────────────────────

    public function login(array $credentials): array
    {
        $response = $this->request('POST', '/api/v1/auth/login', [
            'json' => [
                'username' => (string)($credentials['username'] ?? ''),
                'password' => (string)($credentials['password'] ?? ''),
            ],
        ]);
        return $this->normalizeAccount($response['data'] ?? []);
    }

    public function refreshToken(string $refreshToken): ?array
    {
        try {
            $response = $this->request('POST', '/api/v1/auth/refresh', [
                'json' => ['refresh_token' => $refreshToken],
            ]);
        } catch (\Throwable) {
            return null;
        }
        $data = $response['data'] ?? [];
        return empty($data['token']) ? null : $this->normalizeAccount($data);
    }

    public function logout(string $token): bool
    {
        try {
            $this->request('POST', '/api/v1/auth/logout', [], $token);
        } catch (\Throwable) {
            // 注销失败不阻断本地解绑
        }
        return true;
    }

    // ─────────────────────── 下载与授权 ───────────────────────

    public function requestDownloadTicket(string $appId, array $context): array
    {
        $token = (string)($context['market_token'] ?? '');
        if ($token === '') {
            throw new ApiException('请先绑定应用插件账号');
        }

        $body = [
            'app_id' => $appId,
            'instance_id' => (string)($context['instance_id'] ?? ''),
            'domain' => (string)($context['domain'] ?? ''),
        ];
        // 支持指定版本安装
        $version = (string)($context['version'] ?? '');
        if ($version !== '') {
            $body['version'] = $version;
        }

        try {
            $response = $this->request('POST', '/api/v1/download-ticket', [
                'json' => $body,
            ], $token);
        } catch (ApiException $e) {
            // 市场返回 402：付费未购买，转换为购买引导
            if ($e->getCode() === 402) {
                return [
                    'need_purchase' => true,
                    'price' => 0,
                    'buy_url' => '',
                    'message' => $e->getMessage(),
                ];
            }
            throw $e;
        }

        $ticket = $response['data'] ?? [];
        // need_purchase 必须前置于 url 判空——付费未购响应不携带 url，
        // 先判 url 会误报「市场未返回有效下载凭证」，导致前端拿不到 price/buy_url。
        if (!empty($ticket['need_purchase'])) {
            return [
                'need_purchase' => true,
                'price' => $ticket['price'] ?? 0,
                'buy_url' => $ticket['buy_url'] ?? '',
                'message' => $ticket['message'] ?? '该应用为付费应用，当前市场账号未购买',
            ];
        }
        if (empty($ticket['url'])) {
            throw new ApiException('市场未返回有效下载凭证');
        }
        return $ticket;
    }

    /**
     * 主程序包下载凭证：POST /api/v1/system/download-ticket。
     * 升级链每个待装包各签一张；票面 sha256 以市场落库记录为准。
     */
    public function requestSystemDownloadTicket(array $package, array $context): array
    {
        $token = (string)($context['market_token'] ?? '');
        if ($token === '') {
            throw new ApiException('请先绑定应用插件账号');
        }
        if (empty($package['version'])) {
            throw new ApiException('缺少待装包版本号');
        }

        $response = $this->request('POST', '/api/v1/system/download-ticket', [
            'json' => [
                'app_id' => (string)($package['app_id'] ?? 'core_atrman'),
                'version' => (string)$package['version'],
                // 主程序升级链只会用到增量包（package_type=1），缺省跟选包结果同口径，
                // 避免缺字段时静默签下全量包槽位
                'package_type' => (int)($package['package_type'] ?? 1),
                'delta_from_version' => (string)($package['delta_from_version'] ?? ''),
                'instance_id' => (string)($context['instance_id'] ?? ''),
                'domain' => (string)($context['domain'] ?? ''),
            ],
        ], $token);

        $ticket = $response['data'] ?? [];
        if (empty($ticket['url']) || empty($ticket['sha256'])) {
            throw new ApiException('市场未返回有效主程序包下载凭证');
        }
        return $ticket;
    }

    public function heartbeat(array $context): array
    {
        $token = (string)($context['market_token'] ?? '');
        if ($token === '') {
            return ['valid' => true, 'status' => 1, 'message' => '市场账号未绑定，本次跳过在线校验'];
        }

        try {
            $response = $this->request('POST', '/api/v1/license/heartbeat', [
                'json' => [
                    'license_id' => (string)($context['license_id'] ?? ''),
                    'plugin' => (string)($context['plugin'] ?? ''),
                    'version' => (string)($context['version'] ?? ''),
                    'instance_id' => (string)($context['instance_id'] ?? ''),
                ],
            ], $token, 10);
        } catch (\Throwable $e) {
            // 网络异常不判定授权无效，交由宽限期机制处理
            return ['valid' => true, 'status' => 1, 'network_error' => true, 'message' => '市场连接失败：' . $e->getMessage()];
        }

        $data = $response['data'] ?? [];
        return [
            'valid' => (bool)($data['valid'] ?? false),
            'status' => (int)($data['status'] ?? -1),
            'expires_at' => (int)($data['expires_at'] ?? 0),
            'revoked' => (bool)($data['revoked'] ?? false),
            'message' => (string)($data['message'] ?? ''),
        ];
    }

    public function signingPublicKey(): array
    {
        $signature = (array)config('store.signature', []);
        $publicKey = (string)($signature['public_key'] ?? '');
        if ($publicKey === '') {
            throw new ApiException('市场验签公钥未配置（config/store.php signature.public_key）');
        }
        return [
            'alg' => (string)($signature['alg'] ?? 'ed25519'),
            'public_key' => $publicKey,
        ];
    }

    public function releaseManifest(string $plugin, int $sinceRevision = 0): ?array
    {
        $query = ['plugin' => $plugin];
        if ($sinceRevision > 0) {
            $query['since_revision'] = $sinceRevision;
        }
        $response = $this->request('GET', '/api/v1/releases', ['query' => $query]);
        $data = (array)($response['data'] ?? []);
        // unchanged=true 表示市场侧 revision 未前进，沿用本地缓存
        if (!empty($data['unchanged'])) {
            return null;
        }
        $manifest = $data['manifest'] ?? null;
        return is_array($manifest) ? $manifest : null;
    }

    // ─────────────────────── 内部方法 ───────────────────────

    protected function normalizeAccount(array $data): array
    {
        if (empty($data['token'])) {
            throw new ApiException('市场登录失败：返回数据缺少 token');
        }
        return [
            'token' => (string)$data['token'],
            'refresh_token' => (string)($data['refresh_token'] ?? ''),
            'expires_at' => (int)($data['expires_at'] ?? (time() + 7200)),
            'username' => (string)($data['username'] ?? ''),
            'nickname' => (string)($data['nickname'] ?? ''),
        ];
    }

    /**
     * 发起 HTTP 请求
     *
     * @param string $method GET/POST
     * @param string $path 接口路径
     * @param array $options Guzzle 选项（query/json）
     * @param string|null $token 市场账号 token
     * @param int|null $timeout 覆盖超时
     * @return array 市场响应 JSON
     * @throws ApiException 网络错误或市场返回业务错误
     */
    protected function request(string $method, string $path, array $options = [], ?string $token = null, ?int $timeout = null): array
    {
        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => 'atrman-Store/1.0',
        ];
        if ($token) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        try {
            $client = $this->getClient();
            $response = $client->request($method, $this->baseUrl . $path, array_merge($options, [
                'headers' => $headers,
                'timeout' => $timeout ?? $this->timeout,
                'http_errors' => false,
            ]));
        } catch (\Throwable $e) {
            throw new ApiException('请求应用插件失败：' . $e->getMessage());
        }

        $statusCode = $response->getStatusCode();
        $body = (string)$response->getBody();
        $data = json_decode($body, true);

        if ($statusCode === 402) {
            $message = is_array($data) ? (string)($data['msg'] ?? $data['message'] ?? '需要购买') : '需要购买';
            throw new ApiException($message, 402);
        }
        if ($statusCode === 401) {
            throw new ApiException('市场账号凭证无效或已过期，请重新绑定', 401);
        }
        if ($statusCode < 200 || $statusCode >= 300) {
            $message = is_array($data) ? (string)($data['msg'] ?? $data['message'] ?? '') : '';
            throw new ApiException('应用插件返回错误（HTTP ' . $statusCode . '）' . ($message ? '：' . $message : ''));
        }
        if (!is_array($data)) {
            throw new ApiException('应用插件返回数据格式错误');
        }
        if (isset($data['code']) && (int)$data['code'] !== 200 && (int)$data['code'] !== 0) {
            throw new ApiException((string)($data['msg'] ?? $data['message'] ?? '应用插件业务错误'), (int)$data['code']);
        }

        return $data;
    }

    protected function getClient(): Client
    {
        if (!$this->client) {
            $this->client = new Client();
        }
        return $this->client;
    }
}