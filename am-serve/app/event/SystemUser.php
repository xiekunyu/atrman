<?php

namespace app\event;

use app\cache\ReflectionCache;
use app\model\system\SystemLog;
use app\model\system\UserLoginLog;
use app\utils\ClientInfo;
use support\Log;
use Webman\Http\Request;
use Webman\Http\Response;

class SystemUser
{
    private const MAX_REQUEST_DATA_LENGTH = 8192;

    private const SENSITIVE_KEYS = [
        'password',
        'old_password',
        'oldpassword',
        'new_password',
        'newpassword',
        'confirm_password',
        'confirmpassword',
        'pwd',
        'salt',
        'token',
        'access_token',
        'accesstoken',
        'refresh_token',
        'refreshtoken',
        'authorization',
        'secret',
        'private_key',
        'privatekey',
        // 三方/AI 平台的公开标识与密钥对：secret 只覆盖 *_secret，密钥串本身另有命名习惯
        'api_key',
        'apikey',
        'access_key',
        'accesskey',
        'app_key',
        'appkey',
        'app_id',
        'appid',
        'client_key',
        'clientkey',
        'credential',
    ];

    public function operateLog(mixed $response): bool
    {
        /** @var Request|null $request */
        $request = request();
        if (!$request) {
            return false;
        }
        try {
            $userInfo = getCurrentInfo() ?: [];
            $controller = $request->controller;
            $action = $request->action;
            $clientInfo = ClientInfo::fromRequest($request);
            SystemLog::create([
                'username' => $userInfo['username'] ?? '',
                'app' => $request->plugin ?? '主应用',
                'method' => $request->method(),
                'router' => $request->uri(),
                'business_name' => $this->getBusinessName($controller, $action),
                'ip' => $clientInfo['ip'],
                'ip_location' => $clientInfo['ip_location'],
                'request_data' => $this->getRequestData($request),
                'remark' => $this->getRemark($response ?? null),
                'created_by' => $userInfo['id'] ?? null,
                'updated_by' => $userInfo['id'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::error('操作日志记录失败：' . $e->getMessage());
            return false;
        }

        return true;
    }

    public function loginLog(array $payload): bool
    {
        try {
            /** @var Request|null $request */
            $request = $payload['request'] ?? null;
            if (!$request) {
                return false;
            }
           
            $clientInfo = ClientInfo::fromRequest($request);
            UserLoginLog::create([
                'username' => ($payload['username'] ?? '') ?: '未知用户',
                'ip' => $clientInfo['ip'],
                'location' => $clientInfo['ip_location'],
                'os' => $clientInfo['os'],
                'browser' => $clientInfo['browser'],
                'status' => $payload['status'] ?? 2,
                'message' => $payload['message'] ?? '',
            ]);
        } catch (\Throwable $e) {
            Log::error('登录日志记录失败：' . $e->getMessage());
            return false;
        }

        return true;
    }

    private function getBusinessName(string $controller, string $action): string
    {
        $data = ReflectionCache::getPermissionAttributes($controller, $action);
        return $data['title'] ?? '未命名业务';
    }

    private function getClassBasename(string $class): string
    {
        $parts = explode('\\', $class);

        return end($parts) ?: $class;
    }

    private function getAppName(Request $request): string
    {
        if (!empty($request->app)) {
            return $request->app;
        }

        if (!empty($request->plugin)) {
            return $request->plugin;
        }

        $path = trim($request->path(), '/');
        if ($path !== '') {
            return explode('/', $path)[0] ?: 'app';
        }

        return 'app';
    }

    private function getRequestData(Request $request): string
    {
        $data = $request->all();

        if (empty($data) && str_contains(strtolower($request->header('content-type', '')), 'application/json')) {
            $json = json_decode($request->rawBody(), true);
            if (is_array($json)) {
                $data = $json;
            }
        }

        $data = $this->filterSensitiveData($data);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return '';
        }

        return $this->truncate($json);
    }

    private function filterSensitiveData(mixed $data): mixed
    {
        if (!is_array($data)) {
            return $data;
        }

        foreach ($data as $key => $value) {
            if ($this->isSensitiveKey((string) $key)) {
                $data[$key] = '******';
                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->filterSensitiveData($value);
            }
        }

        return $data;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', ' '], '_', $key));
        foreach (self::SENSITIVE_KEYS as $sensitiveKey) {
            if ($normalized === $sensitiveKey || str_contains($normalized, $sensitiveKey)) {
                return true;
            }
        }

        return false;
    }

    private function truncate(string $content): string
    {
        if (mb_strlen($content) <= self::MAX_REQUEST_DATA_LENGTH) {
            return $content;
        }

        return mb_substr($content, 0, self::MAX_REQUEST_DATA_LENGTH) . '... [truncated, original_length=' . mb_strlen($content) . ']';
    }

    private function getRemark(mixed $response): string
    {
        if ($response instanceof Response && method_exists($response, 'getStatusCode')) {
            return 'HTTP ' . $response->getStatusCode();
        }

        return '';
    }
}
