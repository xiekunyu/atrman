-- ============================================================
-- Atrman 1.0.6 → 1.0.7 手动升级脚本（测试服直连用）
--
-- 与正式升级链路的版本化迁移同源：
--   database/migrations/v1.0.7_add_error_report_and_ops_menu.sql
-- 含两个变更主题：
--   1. 前端错误上报表 am_system_error_report
--   2. 运维管理菜单拆分（操作/登录日志、缓存/服务器监控、备份、系统信息迁出系统管理）
--
-- 适用实例：1.0.6 之后未做过任何增量升级（v1.0.7 迁移未执行过）。
-- 表前缀：本文件按默认前缀 am_ 写死，可直接执行；
--         若实例前缀不是 am_，执行前请全文替换 `am_` 为实际前缀。
-- 幂等性：全部语句可重复执行，不会重复建表/建目录/改数据。
-- 执行方式：mysql -u<用户> -p <库名> < upgrade_v1.0.6_to_v1.0.7.sql
--           或 Navicat / phpMyAdmin 等客户端整文件运行。
-- 执行后：脚本末尾会补登 am_system_migration 成功记录，
--         将来在线升级到 1.0.7 时升级器会自动跳过该迁移，不会重复执行。
-- ============================================================

-- ------------------------------------------------------------
-- 一、前端错误上报表（T7 可观测性）
-- 全新安装已由 install/install.sql 建表，存量实例此处补齐
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `am_system_error_report` (
  `id` bigint NOT NULL AUTO_INCREMENT COMMENT '上报ID',
  `app` varchar(50) NOT NULL DEFAULT '' COMMENT '来源应用（am_web/am_member/am_aibase/am_store/am_plugin）',
  `level` varchar(20) NOT NULL DEFAULT 'error' COMMENT '级别：error/warn',
  `type` varchar(30) NOT NULL DEFAULT '' COMMENT '错误类型：vue/script/promise/resource',
  `message` varchar(1000) NOT NULL DEFAULT '' COMMENT '错误消息（入库前脱敏并截断）',
  `stack` text COMMENT '堆栈摘要（脱敏并截断 4000 字符）',
  `page_url` varchar(500) NOT NULL DEFAULT '' COMMENT '发生页面 URL（查询串敏感参数已脱敏）',
  `user_agent` varchar(255) NOT NULL DEFAULT '' COMMENT '浏览器 UA',
  `ip` varchar(45) NOT NULL DEFAULT '' COMMENT '上报方 IP',
  `user_id` int DEFAULT NULL COMMENT '登录用户 ID，匿名上报为空',
  `create_time` int DEFAULT NULL COMMENT '上报时间',
  PRIMARY KEY (`id`),
  KEY `idx_create_time` (`create_time`),
  KEY `idx_app_type` (`app`,`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='前端错误上报表（只存不回显，仅供聚合统计）';

-- ------------------------------------------------------------
-- 二、运维管理菜单拆分
-- 操作日志/登录日志/缓存监控/服务器监控/备份管理/系统信息
-- 从「系统管理」迁至独立一级目录「运维管理」
-- ------------------------------------------------------------

-- 1. 新增一级目录「运维管理」（按 code=Ops 判重，不硬写 id，避免与实例手工菜单冲突）
INSERT INTO `am_system_menu`
    (`pid`, `level`, `name`, `code`, `app_name`, `type`, `path`, `method`, `component`, `slug`, `icon`, `sort`, `is_hide`, `is_cache`, `is_fixed`, `is_full_page`, `is_iframe`, `status`, `created_by`, `updated_by`, `create_time`, `update_time`, `delete_time`)
SELECT 0, NULL, '运维管理', 'Ops', '', 1, '/ops', '', '/index/index', NULL, 'ri:tools-line', 3, 0, 0, 0, 0, 0, 1, 1, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), NULL
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM `am_system_menu`
    WHERE `code` = 'Ops' AND `type` = 1 AND `app_name` = '' AND `delete_time` IS NULL
);

-- 2. 六个页面菜单归入运维管理并切换组件路径（页面 id 不变，按钮权限挂载关系不断）
UPDATE `am_system_menu`
SET `pid` = (SELECT `ops_id` FROM (SELECT `id` AS `ops_id` FROM `am_system_menu` WHERE `code` = 'Ops' AND `type` = 1 AND `delete_time` IS NULL LIMIT 1) AS `ops_ref`),
    `component` = '/ops/log'
WHERE `id` = 12;
UPDATE `am_system_menu`
SET `pid` = (SELECT `ops_id` FROM (SELECT `id` AS `ops_id` FROM `am_system_menu` WHERE `code` = 'Ops' AND `type` = 1 AND `delete_time` IS NULL LIMIT 1) AS `ops_ref`),
    `component` = '/ops/loginLog'
WHERE `id` = 13;
UPDATE `am_system_menu`
SET `pid` = (SELECT `ops_id` FROM (SELECT `id` AS `ops_id` FROM `am_system_menu` WHERE `code` = 'Ops' AND `type` = 1 AND `delete_time` IS NULL LIMIT 1) AS `ops_ref`),
    `component` = '/ops/cache'
WHERE `id` = 17;
UPDATE `am_system_menu`
SET `pid` = (SELECT `ops_id` FROM (SELECT `id` AS `ops_id` FROM `am_system_menu` WHERE `code` = 'Ops' AND `type` = 1 AND `delete_time` IS NULL LIMIT 1) AS `ops_ref`),
    `component` = '/ops/server'
WHERE `id` = 18;
UPDATE `am_system_menu`
SET `pid` = (SELECT `ops_id` FROM (SELECT `id` AS `ops_id` FROM `am_system_menu` WHERE `code` = 'Ops' AND `type` = 1 AND `delete_time` IS NULL LIMIT 1) AS `ops_ref`),
    `component` = '/ops/backup'
WHERE `id` = 25;
UPDATE `am_system_menu`
SET `pid` = (SELECT `ops_id` FROM (SELECT `id` AS `ops_id` FROM `am_system_menu` WHERE `code` = 'Ops' AND `type` = 1 AND `delete_time` IS NULL LIMIT 1) AS `ops_ref`),
    `component` = '/ops/info'
WHERE `id` = 26;

-- 3. 一级菜单排序顺延，为运维管理（sort=3）让位：数据管理 3→4、消息管理 4→5、应用中心 5→6
UPDATE `am_system_menu` SET `sort` = 4 WHERE `id` = 7;
UPDATE `am_system_menu` SET `sort` = 5 WHERE `id` = 20;
UPDATE `am_system_menu` SET `sort` = 6 WHERE `id` = 14;

-- ------------------------------------------------------------
-- 三、补登迁移记录（唯一键 app_name+migration 防重）
-- 让在线升级器（CoreUpgradeService::runMigrations）认定 v1.0.7 迁移已成功，自动跳过
-- ------------------------------------------------------------
INSERT INTO `am_system_migration`
    (`app_name`, `migration_type`, `version`, `migration`, `checksum`, `from_version`, `to_version`, `batch`, `status`, `error_message`, `executed_at`, `created_by`, `updated_by`, `create_time`, `update_time`)
VALUES
    ('atrman', 2, '1.0.7', 'v1.0.7_add_error_report_and_ops_menu.sql', '', '1.0.6', '1.0.7', 1, 1, '', NOW(), 0, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE
    `version`       = VALUES(`version`),
    `from_version`  = VALUES(`from_version`),
    `to_version`    = VALUES(`to_version`),
    `status`        = 1,
    `error_message` = '',
    `executed_at`   = NOW(),
    `update_time`   = UNIX_TIMESTAMP();
