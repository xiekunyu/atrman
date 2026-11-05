<?php

namespace app\service\provider;

/**
 * 商店数据源提供者接口
 *
 * 抽象应用市场的全部远程交互：浏览、账号、下载凭证、心跳。
 * 开发测试使用 MockStoreProvider，上线切换 RemoteStoreProvider（config/store.php）。
 *
 * 安全约定：
 * - 下载凭证（ticket）只在后端流转，任何实现都不得把下载 URL 暴露给前端
 * - 付费应用由市场侧校验购买记录后签发 license，免费应用无 license
 */
interface StoreProvider
{
    /** 提供者标识：mock / remote */
    public function name(): string;

    /**
     * 获取应用列表
     * @param array $params 筛选参数：category、keyword、recommend、orderField、orderType
     * @return array 应用列表（含 is_paid、price、purchased 等市场字段，不含下载地址）
     */
    public function list(array $params = []): array;

    /**
     * 获取应用详情
     * @param string $appId 应用标识
     * @return array|null
     */
    public function detail(string $appId): ?array;

    /**
     * 校验应用标识在应用市场是否可用（全局唯一，含未上架/审核中/软删行）。
     *
     * 仅回答市场侧占用判定，基础格式/保留名/长度由调用方本地校验。
     * 市场不可达时由调用方按 fail-open 处理（抛异常即可）。
     *
     * @param string $name 规范化后的应用标识（am_ 前缀）
     * @return array{available:bool, reason:string, message:string}
     */
    public function checkName(string $name): array;

    /**
     * 获取分类列表
     * @return array
     */
    public function categories(): array;

    /**
     * 检查已安装应用的更新
     * @param array $installed 已安装应用列表（name、version）
     * @return array 需要更新的应用列表
     */
    public function checkUpdate(array $installed): array;

    /**
     * 检查系统更新
     * @param string $currentVersion 当前系统版本
     * @return array|null
     */
    public function systemUpdate(string $currentVersion): ?array;

    // ─────────────────────── 市场账号 ───────────────────────

    /**
     * 登录应用市场，换取账号凭证
     * @param array $credentials {username, password} 或 {token}
     * @return array {token, refresh_token, expires_at, username, nickname}
     */
    public function login(array $credentials): array;

    /**
     * 使用 refresh_token 续期账号凭证
     * @return array|null 新凭证，失败返回 null
     */
    public function refreshToken(string $refreshToken): ?array;

    /**
     * 注销市场账号会话（解绑时调用）
     */
    public function logout(string $token): bool;

    // ─────────────────────── 下载与授权 ───────────────────────

    /**
     * 请求下载凭证（ticket）
     *
     * 市场侧校验：账号有效性、付费应用购买记录、实例绑定数量。
     * 免费应用返回短期下载 URL；付费应用额外返回已签名的 license。
     *
     * @param string $appId 应用标识
     * @param array $context {market_token, instance_id, domain, version?}
     *                       version 可选：指定安装版本，缺省取最新版
     * @return array 成功：{url, sha256, license_required, license?, expires_at}
     *               未购买：{need_purchase: true, price, buy_url, message}
     * @throws \app\exception\ApiException 账号凭证无效/应用不存在/实例绑定超限等
     */
    public function requestDownloadTicket(string $appId, array $context): array;

    /**
     * 请求主程序包下载凭证（升级链每个待装包各签一张）
     *
     * @param array $package systemUpdate 返回的 upgrade.packages 单条目
     *                       （version/package_type/delta_from_version/sha256 等）
     * @param array $context {market_token, instance_id, domain}
     * @return array {url, sha256, version, package_type, expires_at}
     * @throws \app\exception\ApiException 账号凭证无效/版本包缺失等
     */
    public function requestSystemDownloadTicket(array $package, array $context): array;

    /**
     * License 心跳校验（仅付费应用，每周一次）
     *
     * 市场侧校验授权是否仍有效（支持退款/封禁远程吊销），可同步续期信息。
     *
     * @param array $context {market_token, instance_id, license_id, plugin, version}
     * @return array {valid, status, expires_at?, revoked?, message?}
     */
    public function heartbeat(array $context): array;

    /**
     * 获取应用版本历史列表
     * @param string $appId 应用标识
     * @return array 版本列表（按发布时间倒序，每项含 version/changelog/release_date 等）
     */
    public function versions(string $appId): array;

    /**
     * 获取用于验证发布清单签名与 License 签名的公钥
     *
     * Mock 提供者返回本地生成的开发公钥；远程提供者返回市场官方公钥。
     * @return array{alg: string, public_key: string}
     */
    public function signingPublicKey(): array;

    /**
     * 拉取发布清单（签名的唯一载体：列出该 plugin 全部已发布包的 sha256）
     *
     * 匿名接口，不需要市场账号；应答可被 CDN/反代按 ETag 缓存。
     * Mock 提供者用本地开发密钥即时签发一份，供离线开发验通链路。
     *
     * @param string $plugin 应用标识（主程序为 atrman）
     * @param int $sinceRevision 本地已持有的清单 revision，市场无更新时返回 null
     * @return array|null 清单结构（schema/issuer/plugin/revision/issued_at/expires_at/entries_hash/entries/alg/signature）
     * @throws \app\exception\ApiException 应用不存在或市场返回业务错误
     */
    public function releaseManifest(string $plugin, int $sinceRevision = 0): ?array;
}