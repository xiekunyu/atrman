-- v1.0.7 升级迁移（1.0.6 → 1.0.7，合并两个变更主题）：
--   1. 前端错误上报表 am_system_error_report（T7 可观测性）
--   2. 运维管理菜单拆分：操作日志/登录日志/缓存监控/服务器监控/备份管理/系统信息
--      从「系统管理」迁至独立一级目录「运维管理」
-- 全新安装已由 install/install.sql 建表、install/seed.sql 写入新菜单结构；
-- 本脚本供存量 1.0.6 实例经主程序增量升级时自动执行。
-- 发布时在 am_store 版本表单 upgrade_config.migrations 填入本文件的基座相对路径：
--   database/migrations/v1.0.7_add_error_report_and_ops_menu.sql
-- 幂等：IF NOT EXISTS / WHERE NOT EXISTS / 固定 id UPDATE 保证迁移记录缺失或失败重跑时不报错，可安全重复执行。
-- 表名前缀由 DatabaseService::executeSqlFile 将 {{DB_PREFIX}} 替换为实例实际前缀。

-- ========== 一、前端错误上报表 ==========
CREATE TABLE IF NOT EXISTS `{{DB_PREFIX}}system_error_report` (
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

-- ========== 二、运维管理菜单拆分 ==========

-- 1. 新增一级目录「运维管理」（component 仅占位，一级目录由前端统一用布局容器渲染）
INSERT INTO `{{DB_PREFIX}}system_menu`
    (`pid`, `level`, `name`, `code`, `app_name`, `type`, `path`, `method`, `component`, `slug`, `icon`, `sort`, `is_hide`, `is_cache`, `is_fixed`, `is_full_page`, `is_iframe`, `status`, `created_by`, `updated_by`, `create_time`, `update_time`, `delete_time`)
SELECT 0, NULL, '运维管理', 'Ops', '', 1, '/ops', '', '/index/index', NULL, 'ri:tools-line', 3, 0, 0, 0, 0, 0, 1, 1, 1, 1794897688, 1794897688, NULL
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM `{{DB_PREFIX}}system_menu`
    WHERE `code` = 'Ops' AND `type` = 1 AND `app_name` = '' AND `delete_time` IS NULL
);

-- 2. 六个页面菜单归入运维管理并切换组件路径（页面 id 不变，type=3 按钮权限的挂载关系不断）
UPDATE `{{DB_PREFIX}}system_menu`
SET `pid` = (SELECT `ops_id` FROM (SELECT `id` AS `ops_id` FROM `{{DB_PREFIX}}system_menu` WHERE `code` = 'Ops' AND `type` = 1 AND `delete_time` IS NULL LIMIT 1) AS `ops_ref`),
    `component` = '/ops/log'
WHERE `id` = 12;
UPDATE `{{DB_PREFIX}}system_menu`
SET `pid` = (SELECT `ops_id` FROM (SELECT `id` AS `ops_id` FROM `{{DB_PREFIX}}system_menu` WHERE `code` = 'Ops' AND `type` = 1 AND `delete_time` IS NULL LIMIT 1) AS `ops_ref`),
    `component` = '/ops/loginLog'
WHERE `id` = 13;
UPDATE `{{DB_PREFIX}}system_menu`
SET `pid` = (SELECT `ops_id` FROM (SELECT `id` AS `ops_id` FROM `{{DB_PREFIX}}system_menu` WHERE `code` = 'Ops' AND `type` = 1 AND `delete_time` IS NULL LIMIT 1) AS `ops_ref`),
    `component` = '/ops/cache'
WHERE `id` = 17;
UPDATE `{{DB_PREFIX}}system_menu`
SET `pid` = (SELECT `ops_id` FROM (SELECT `id` AS `ops_id` FROM `{{DB_PREFIX}}system_menu` WHERE `code` = 'Ops' AND `type` = 1 AND `delete_time` IS NULL LIMIT 1) AS `ops_ref`),
    `component` = '/ops/server'
WHERE `id` = 18;
UPDATE `{{DB_PREFIX}}system_menu`
SET `pid` = (SELECT `ops_id` FROM (SELECT `id` AS `ops_id` FROM `{{DB_PREFIX}}system_menu` WHERE `code` = 'Ops' AND `type` = 1 AND `delete_time` IS NULL LIMIT 1) AS `ops_ref`),
    `component` = '/ops/backup'
WHERE `id` = 25;
UPDATE `{{DB_PREFIX}}system_menu`
SET `pid` = (SELECT `ops_id` FROM (SELECT `id` AS `ops_id` FROM `{{DB_PREFIX}}system_menu` WHERE `code` = 'Ops' AND `type` = 1 AND `delete_time` IS NULL LIMIT 1) AS `ops_ref`),
    `component` = '/ops/info'
WHERE `id` = 26;

-- 3. 一级菜单排序顺延，为运维管理（sort=3）让位：数据管理 3→4、消息管理 4→5、应用中心 5→6
UPDATE `{{DB_PREFIX}}system_menu` SET `sort` = 4 WHERE `id` = 7;
UPDATE `{{DB_PREFIX}}system_menu` SET `sort` = 5 WHERE `id` = 20;
UPDATE `{{DB_PREFIX}}system_menu` SET `sort` = 6 WHERE `id` = 14;
