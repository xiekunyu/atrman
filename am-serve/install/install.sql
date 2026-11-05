-- ============================================================
-- Atrman 系统表结构（install.sql）
-- 每个表一条完整 CREATE TABLE 语句（含主键、索引、自增起始值）
-- 不含任何业务数据，初始数据见 seed.sql（本文件之后执行）
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- --------------------------------------------------------

--
-- 表的结构 `am_oauth_access_token`
--

CREATE TABLE `am_oauth_access_token` (
  `id` int NOT NULL AUTO_INCREMENT,
  `token` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '访问令牌',
  `refresh_token` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '刷新令牌',
  `client_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '客户端ID',
  `sid` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '全局主体唯一ID（人）',
  `scopes` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '授权范围',
  `expires_at` int NOT NULL COMMENT '过期时间',
  `refresh_expires_at` int DEFAULT '0' COMMENT '刷新令牌过期时间',
  `create_time` int DEFAULT '0' COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_token` (`token`),
  UNIQUE KEY `uniq_refresh_token` (`refresh_token`),
  KEY `idx_client_user` (`client_id`),
  KEY `idx_expires` (`expires_at`),
  KEY `idx_client_sid` (`client_id`,`sid`(22)),
  KEY `idx_sid` (`sid`(22))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='OAuth访问令牌表';

-- --------------------------------------------------------

--
-- 表的结构 `am_oauth_auth_code`
--

CREATE TABLE `am_oauth_auth_code` (
  `id` int NOT NULL AUTO_INCREMENT,
  `code` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '授权码',
  `client_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '客户端ID',
  `sid` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '全局主体唯一ID（人）',
  `redirect_uri` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '回调地址',
  `scopes` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '授权范围',
  `expires_at` int NOT NULL COMMENT '过期时间',
  `create_time` int DEFAULT '0' COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_code` (`code`),
  KEY `idx_client_id` (`client_id`),
  KEY `idx_expires` (`expires_at`),
  KEY `idx_sid` (`sid`(22))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='OAuth授权码表';

-- --------------------------------------------------------

--
-- 表的结构 `am_plugin_app`
--

CREATE TABLE `am_plugin_app` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '插件标识',
  `title` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '插件名称',
  `icon` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '应用图标',
  `type` tinyint(1) NOT NULL DEFAULT '1' COMMENT '类型：1插件应用,2插件,3三方应用',
  `version` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '1.0.0' COMMENT '插件版本',
  `description` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '插件描述',
  `author` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '作者',
  `path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '插件路径',
  `is_custom` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否自建',
  `is_core` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否为核心应用',
  `source` tinyint(1) NOT NULL DEFAULT '1' COMMENT '应用来源：1本地插件，2远程应用',
  `scope` tinyint(1) NOT NULL DEFAULT '1' COMMENT '适用范围：1通用应用，2企业应用，3个人应用',
  `platform` tinyint(1) DEFAULT '1' COMMENT '使用设备：1都支持，2网页端，3移动端',
  `is_dev` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否为开发模式',
  `auth` json DEFAULT NULL COMMENT '课件范围',
  `status` tinyint NOT NULL DEFAULT '0' COMMENT '状态:-1异常,0禁用,1启用,2已安装',
  `config` json DEFAULT NULL COMMENT '插件配置',
  `manifest` json DEFAULT NULL COMMENT '插件清单',
  `composer_state` json DEFAULT NULL COMMENT '插件 Composer 依赖检测/安装状态快照',
  `oauth_client_id` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '三方应用OAuth客户端ID',
  `oauth_client_secret` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '三方应用OAuth客户端密钥',
  `oauth_redirect_uri` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '三方应用OAuth回调地址',
  `oauth_scopes` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT '' COMMENT '三方应用OAuth授权范围',
  `error_message` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '异常信息',
  `installed_at` datetime DEFAULT NULL COMMENT '安装时间',
  `enabled_at` datetime DEFAULT NULL COMMENT '启用时间',
  `created_by` int DEFAULT '0' COMMENT '创建人',
  `updated_by` int DEFAULT '0' COMMENT '更新人',
  `create_time` int DEFAULT '0' COMMENT '创建时间',
  `update_time` int DEFAULT '0' COMMENT '更新时间',
  `delete_time` int DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_name` (`name`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统插件表';

-- --------------------------------------------------------

--
-- 表的结构 `am_plugin_widget`
--

CREATE TABLE `am_plugin_widget` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(32) COLLATE utf8mb4_general_ci NOT NULL COMMENT '标识',
  `title` varchar(32) COLLATE utf8mb4_general_ci NOT NULL COMMENT '名称',
  `description` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '描述',
  `cate` tinyint(1) NOT NULL DEFAULT '1' COMMENT '组件类型：1:vue，2：html',
  `type` varchar(32) COLLATE utf8mb4_general_ci NOT NULL COMMENT '类型：widght,page',
  `url` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '组件路径',
  `size` varchar(64) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '展示宽度',
  `app_name` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '关联应用名',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态',
  `created_by` int NOT NULL DEFAULT '0' COMMENT '创建人',
  `updated_by` int NOT NULL DEFAULT '0' COMMENT '更新人',
  `create_time` int NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int DEFAULT '0' COMMENT '更新时间',
  `delete_time` int DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='小组件表';

-- --------------------------------------------------------

--
-- 表的结构 `am_system_backup`
--

CREATE TABLE `am_system_backup` (
  `id` int NOT NULL AUTO_INCREMENT,
  `app_name` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '应用标识，系统为system',
  `title` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '备份对象名称',
  `version` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '备份对象版本',
  `backup_type` tinyint NOT NULL DEFAULT '1' COMMENT '备份类型：1插件备份，2系统备份',
  `file_id` int NOT NULL DEFAULT '0' COMMENT '关联文件ID',
  `file_count` int NOT NULL DEFAULT '0' COMMENT '备份文件数量',
  `database_summary` json DEFAULT NULL COMMENT '数据库备份摘要',
  `plugin_snapshot` json DEFAULT NULL COMMENT '插件记录快照',
  `manifest` json DEFAULT NULL COMMENT '插件清单快照',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '状态：1成功，-1失败',
  `error_message` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '异常信息',
  `created_by` int DEFAULT '0' COMMENT '创建人',
  `updated_by` int DEFAULT '0' COMMENT '更新人',
  `create_time` int DEFAULT '0' COMMENT '创建时间',
  `update_time` int DEFAULT '0' COMMENT '更新时间',
  `delete_time` int DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`),
  KEY `idx_app_name` (`app_name`),
  KEY `idx_backup_type` (`backup_type`),
  KEY `idx_file_id` (`file_id`),
  KEY `idx_create_time` (`create_time`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统备份记录表';

-- --------------------------------------------------------

--
-- 表的结构 `am_system_config`
--

CREATE TABLE `am_system_config` (
  `id` int NOT NULL AUTO_INCREMENT,
  `pid` int NOT NULL DEFAULT '0' COMMENT '父ID',
  `name` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '名称',
  `code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '字段名',
  `value` text COLLATE utf8mb4_general_ci COMMENT '字段值',
  `form_type` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '表单类型',
  `options` json DEFAULT NULL COMMENT '配置项',
  `is_sys` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否内置',
  `is_edit` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否可编辑',
  `app_name` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '所属应用',
  `sort` int NOT NULL DEFAULT '0' COMMENT '排序',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态',
  `created_by` int NOT NULL DEFAULT '0' COMMENT '创建人',
  `updated_by` int DEFAULT '0' COMMENT '更新人',
  `create_time` int DEFAULT NULL COMMENT '创建时间',
  `update_time` int DEFAULT NULL COMMENT '更新时间',
  `delete_time` int DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=91 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- 表的结构 `am_system_dept`
--

CREATE TABLE `am_system_dept` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT '部门ID',
  `pid` int DEFAULT '0' COMMENT '父部门ID（0为顶级）',
  `name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT '部门名称',
  `name_py` varchar(128) DEFAULT NULL COMMENT '名称拼音，用于检索',
  `sort` int DEFAULT '0' COMMENT '排序号',
  `leader` varchar(50) DEFAULT '' COMMENT '部门负责人',
  `childs` json DEFAULT NULL COMMENT '所有子部门',
  `status` tinyint DEFAULT '1' COMMENT '状态（0禁用/1正常）',
  `created_by` int DEFAULT NULL COMMENT '创建者',
  `updated_by` int DEFAULT NULL COMMENT '更新者',
  `create_time` int DEFAULT NULL COMMENT '创建时间',
  `update_time` int DEFAULT NULL COMMENT '修改时间',
  `delete_time` int DEFAULT NULL COMMENT '删除时间（软删除）',
  PRIMARY KEY (`id`),
  KEY `idx_parent_id` (`pid`),
  KEY `idx_status` (`status`),
  KEY `idx_delete_time` (`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='部门表';

-- --------------------------------------------------------

--
-- 表的结构 `am_system_dept_user`
--

CREATE TABLE `am_system_dept_user` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT '关联ID',
  `is_main` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否主部门（0否/1是）',
  `is_leader` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否为领导',
  `dept_id` int NOT NULL COMMENT '部门ID',
  `user_id` int NOT NULL COMMENT '用户ID',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_dept_user` (`dept_id`,`user_id`),
  KEY `idx_dept_id` (`dept_id`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='部门-用户关联表';

-- --------------------------------------------------------

--
-- 表的结构 `am_system_dict`
--

CREATE TABLE `am_system_dict` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `pid` int NOT NULL DEFAULT '0' COMMENT '父节点ID（0为根节点）',
  `code` varchar(50) NOT NULL DEFAULT '' COMMENT '字典编码（仅根节点有值）',
  `name` varchar(100) NOT NULL COMMENT '名称（字典类型名/字典项标签）',
  `value` varchar(100) NOT NULL DEFAULT '' COMMENT '字典项值',
  `color` varchar(32) NOT NULL DEFAULT '' COMMENT '颜色',
  `sort` int NOT NULL DEFAULT '0' COMMENT '排序号',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '状态（0-禁用，1-启用）',
  `remark` varchar(500) DEFAULT '' COMMENT '备注',
  `is_sys` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否系统数据',
  `created_by` int DEFAULT NULL COMMENT '创建者',
  `updated_by` int DEFAULT NULL COMMENT '更新者',
  `create_time` int DEFAULT NULL COMMENT '创建时间',
  `update_time` int DEFAULT NULL COMMENT '修改时间',
  `delete_time` int DEFAULT NULL COMMENT '删除时间（软删除）',
  PRIMARY KEY (`id`),
  KEY `idx_pid` (`pid`)
) ENGINE=InnoDB AUTO_INCREMENT=72 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='系统字典表';

-- --------------------------------------------------------

--
-- 表的结构 `am_system_file`
--

CREATE TABLE `am_system_file` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT '文件ID',
  `type` tinyint DEFAULT '1' COMMENT '类型 0-目录 1-文件',
  `pid` int DEFAULT '0' COMMENT '父级ID',
  `level` smallint DEFAULT '0' COMMENT '层级',
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT '文件原名',
  `url` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT '文件域名',
  `path` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT '文件存储路径',
  `thumb` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT '缩略图',
  `ext` varchar(20) DEFAULT '' COMMENT '文件后缀（如jpg、pdf）',
  `size` int DEFAULT '0' COMMENT '文件大小（字节B）',
  `md5` char(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT '文件MD5值（用于秒传）',
  `storage_id` smallint DEFAULT '1' COMMENT '所属存储库ID',
  `is_lock` tinyint DEFAULT '0' COMMENT '是否锁定 0-否 1-是',
  `file_type` tinyint DEFAULT '0' COMMENT '文件类型 1-图片 2-文档 3-视频 4-音频 99-其他',
  `mime_type` varchar(128) DEFAULT '' COMMENT '文件MIME类型',
  `extends` json DEFAULT NULL COMMENT '扩展信息',
  `status` tinyint DEFAULT '1' COMMENT '状态 0-禁用 1-正常',
  `created_by` int DEFAULT NULL COMMENT '创建者',
  `updated_by` int DEFAULT NULL COMMENT '更新者',
  `create_time` int DEFAULT NULL COMMENT '创建时间',
  `update_time` int DEFAULT NULL COMMENT '修改时间',
  `delete_time` int DEFAULT NULL COMMENT '删除时间（软删除）',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='文件表';

-- --------------------------------------------------------

--
-- 表的结构 `am_system_log`
--

CREATE TABLE `am_system_log` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `username` varchar(32) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '用户名',
  `app` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '应用名称',
  `method` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '请求方式',
  `router` varchar(500) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '请求路由',
  `business_name` varchar(64) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '业务名称',
  `ip` varchar(45) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '请求IP地址',
  `ip_location` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'IP所属地',
  `request_data` text COLLATE utf8mb4_general_ci COMMENT '请求数据',
  `remark` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '备注',
  `created_by` int DEFAULT '0' COMMENT '创建者',
  `updated_by` int DEFAULT '0' COMMENT '更新者',
  `create_time` int DEFAULT '0' COMMENT '创建时间',
  `update_time` int DEFAULT '0' COMMENT '更新时间',
  `delete_time` int DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `username` (`username`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='系统日志表' ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- 表的结构 `am_system_menu`
--

CREATE TABLE `am_system_menu` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT '菜单ID',
  `pid` int DEFAULT '0' COMMENT '父菜单ID（0为顶级）',
  `level` varchar(500) DEFAULT NULL COMMENT '组级集合',
  `name` varchar(50) NOT NULL COMMENT '菜单名称',
  `code` varchar(64) DEFAULT NULL COMMENT '菜单标识',
  `app_name` varchar(64) NOT NULL DEFAULT '' COMMENT '归属插件标识，空=系统基座菜单',
  `type` tinyint(1) NOT NULL COMMENT '类型（1目录/2菜单/3按钮API/4链接）',
  `path` varchar(255) DEFAULT '' COMMENT '路由路径，如果为链接则是URL',
  `method` varchar(10) DEFAULT NULL COMMENT '请求方式',
  `component` varchar(255) DEFAULT '' COMMENT '组件路径',
  `slug` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT '权限标识（按钮用），需要对应后端路由',
  `icon` varchar(100) DEFAULT '' COMMENT '菜单图标',
  `sort` int DEFAULT '0' COMMENT '排序号',
  `is_hide` tinyint DEFAULT '1' COMMENT '是否显示（0否/1是）',
  `is_cache` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否缓存',
  `is_fixed` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否固定',
  `is_full_page` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否全屏',
  `is_iframe` tinyint DEFAULT '1' COMMENT '是否为iframe（0内嵌/1新的标签页）链接时有效',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态',
  `created_by` int DEFAULT NULL COMMENT '创建者',
  `updated_by` int DEFAULT NULL COMMENT '更新者',
  `create_time` int DEFAULT NULL COMMENT '创建时间',
  `update_time` int DEFAULT NULL COMMENT '修改时间',
  `delete_time` int DEFAULT NULL COMMENT '删除时间（软删除）',
  PRIMARY KEY (`id`),
  KEY `idx_parent_id` (`pid`),
  KEY `idx_type` (`type`),
  KEY `idx_visible` (`is_hide`),
  KEY `idx_delete_time` (`delete_time`),
  KEY `idx_app_name` (`app_name`)
) ENGINE=InnoDB AUTO_INCREMENT=63 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='菜单表';

-- --------------------------------------------------------

--
-- 表的结构 `am_system_migration`
--

CREATE TABLE `am_system_migration` (
  `id` int NOT NULL AUTO_INCREMENT,
  `app_name` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '应用标识，系统为system',
  `migration_type` tinyint NOT NULL DEFAULT '1' COMMENT '迁移类型：1插件迁移，2系统迁移',
  `version` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '迁移版本',
  `migration` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '迁移文件名',
  `checksum` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '迁移文件校验值',
  `from_version` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '升级前版本',
  `to_version` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '升级后版本',
  `batch` int NOT NULL DEFAULT '0' COMMENT '迁移批次',
  `status` tinyint NOT NULL DEFAULT '1' COMMENT '状态：1成功，-1失败',
  `error_message` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '异常信息',
  `executed_at` datetime DEFAULT NULL COMMENT '执行时间',
  `created_by` int DEFAULT '0' COMMENT '创建人',
  `updated_by` int DEFAULT '0' COMMENT '更新人',
  `create_time` int DEFAULT '0' COMMENT '创建时间',
  `update_time` int DEFAULT '0' COMMENT '更新时间',
  `delete_time` int DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_system_migration` (`app_name`,`migration`),
  KEY `idx_app_version` (`app_name`,`version`),
  KEY `idx_migration_type` (`migration_type`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统迁移记录表';

-- --------------------------------------------------------

--
-- 表的结构 `am_system_notify_log`
--

CREATE TABLE `am_system_notify_log` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `template_id` int DEFAULT NULL COMMENT '模板ID',
  `template_code` varchar(50) DEFAULT '' COMMENT '模板编码（冗余，方便查询）',
  `title` varchar(200) DEFAULT '' COMMENT '实际发送的标题',
  `content` text COMMENT '实际发送的内容（变量替换后）',
  `receiver` varchar(500) NOT NULL DEFAULT '' COMMENT '接收方（邮箱/手机号/用户ID，多个逗号分隔）',
  `channels` json DEFAULT NULL COMMENT '各通道发送结果：{"email":{"status":1,"message":"成功","cost_time":120},...}',
  `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '状态：0=待发送,1=已发送,2=发送失败',
  `retry_count` tinyint NOT NULL DEFAULT '0' COMMENT '重试次数',
  `create_time` int DEFAULT '0' COMMENT '创建时间',
  `update_time` int NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_template_code` (`template_code`),
  KEY `idx_create_time` (`create_time`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='通知发送记录表';

-- --------------------------------------------------------

--
-- 表的结构 `am_system_notify_message`
--

CREATE TABLE `am_system_notify_message` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `log_id` bigint DEFAULT NULL COMMENT '关联发送记录ID',
  `user_id` int UNSIGNED NOT NULL COMMENT '接收用户ID',
  `title` varchar(200) NOT NULL DEFAULT '' COMMENT '消息标题',
  `content` text COMMENT '消息内容（HTML或纯文本）',
  `type` varchar(30) NOT NULL DEFAULT '' COMMENT '消息分类：system/activity/notice 等',
  `is_read` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否已读：0=未读,1=已读',
  `read_at` datetime DEFAULT NULL COMMENT '阅读时间',
  `is_deleted` tinyint(1) NOT NULL DEFAULT '0' COMMENT '用户是否删除（收件箱隐藏）：0=正常,1=已删除',
  `extra` json DEFAULT NULL COMMENT '扩展数据（如跳转链接、关联业务ID等）',
  `create_time` int DEFAULT '0' COMMENT '创建时间',
  `update_time` int NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_user_read` (`user_id`,`is_read`),
  KEY `idx_user_deleted` (`user_id`,`is_deleted`),
  KEY `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='用户站内信表';

-- --------------------------------------------------------

--
-- 表的结构 `am_system_notify_preference`
--

CREATE TABLE `am_system_notify_preference` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int UNSIGNED NOT NULL COMMENT '用户ID',
  `type` enum('email','sms','inbox') NOT NULL COMMENT '通知类型',
  `notify_type` varchar(50) NOT NULL DEFAULT '*' COMMENT '消息分类，*=全部',
  `enabled` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否接收：1=接收,0=拒收',
  `create_time` int DEFAULT '0' COMMENT '创建时间',
  `update_time` int DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_type_notify` (`user_id`,`type`,`notify_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='用户通知偏好设置表';

-- --------------------------------------------------------

--
-- 表的结构 `am_system_notify_template`
--

CREATE TABLE `am_system_notify_template` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '模板名称',
  `code` varchar(50) NOT NULL DEFAULT '' COMMENT '模板编码（调用标识）',
  `source` varchar(50) NOT NULL DEFAULT 'system' COMMENT '模板来源：system=系统核心，插件名=插件模板（如 am_todo）',
  `sms_provider_id` int DEFAULT NULL COMMENT '绑定短信供应商ID（为空则用系统默认供应商）',
  `title` varchar(200) DEFAULT '' COMMENT '标题（邮件主题/站内信标题）',
  `content` text COMMENT '模板内容（富文本，用于邮件/站内信）',
  `variables` json DEFAULT NULL COMMENT '变量声明 [{name,label}]',
  `sms_template_id` varchar(100) DEFAULT '' COMMENT '短信模板编号（有值则用服务商模板ID发短信；为空则用sms_content作为短信文字；都为空则不发短信）',
  `sms_content` varchar(500) DEFAULT '' COMMENT '短信模板内容（纯文本，不含HTML标签，用于短信发送）',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态：1=启用,0=禁用',
  `remark` varchar(255) DEFAULT '' COMMENT '备注',
  `created_by` int DEFAULT NULL COMMENT '创建人',
  `updated_by` int DEFAULT NULL COMMENT '更新人',
  `create_time` int DEFAULT '0' COMMENT '创建时间',
  `update_time` int DEFAULT '0' COMMENT '更新时间',
  `delete_time` int DEFAULT '0' COMMENT '删除时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_source` (`source`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='通知模板表';

-- --------------------------------------------------------

--
-- 表的结构 `am_system_oper_log`
--

CREATE TABLE `am_system_oper_log` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `username` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '用户名',
  `app` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '应用名称',
  `method` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '请求方式',
  `router` varchar(500) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '请求路由',
  `service_name` varchar(30) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '业务名称',
  `ip` varchar(45) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '请求IP地址',
  `ip_location` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'IP所属地',
  `request_data` text COLLATE utf8mb4_general_ci COMMENT '请求数据',
  `remark` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '备注',
  `create_time` int DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `username` (`username`) USING BTREE,
  KEY `idx_create_time` (`create_time`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='操作日志表' ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- 表的结构 `am_system_remote`
--

CREATE TABLE `am_system_remote` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `name` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '名称',
  `code` varchar(128) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '识别码',
  `api_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'api请求地址或路径',
  `method` tinyint(1) NOT NULL DEFAULT '1' COMMENT '请求方式：1get，2post',
  `type` tinyint(1) NOT NULL DEFAULT '1' COMMENT '类型1列表，2树状列表',
  `label_field` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '标签字段',
  `value_field` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '值字段',
  `chidlren_field` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '子列表字段',
  `header` json DEFAULT NULL COMMENT '请求头',
  `params` json DEFAULT NULL COMMENT '固定请求参数',
  `is_sys` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否系统内置',
  `created_by` int NOT NULL DEFAULT '0' COMMENT '创建人',
  `udpated_by` int NOT NULL DEFAULT '0' COMMENT '更新人',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态',
  `create_time` int NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int NOT NULL DEFAULT '0' COMMENT '更新时间',
  `delete_time` int DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='远程数据表';

-- --------------------------------------------------------

--
-- 表的结构 `am_system_role`
--

CREATE TABLE `am_system_role` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT '角色ID',
  `name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT '角色名称',
  `code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT '角色标识（唯一）',
  `level` smallint NOT NULL DEFAULT '1' COMMENT '层级，越高权限越大',
  `data_scope` tinyint NOT NULL DEFAULT 2 COMMENT '数据范围:1全部 2本部门及下属 3本部门 4仅本人 5自定义',
  `sort` int DEFAULT '0' COMMENT '排序号',
  `status` tinyint DEFAULT '1' COMMENT '状态（0禁用/1正常）',
  `remark` varchar(255) DEFAULT '' COMMENT '角色备注',
  `created_by` int DEFAULT NULL COMMENT '创建者',
  `updated_by` int DEFAULT NULL COMMENT '更新者',
  `create_time` int DEFAULT NULL COMMENT '创建时间',
  `update_time` int DEFAULT NULL COMMENT '修改时间',
  `delete_time` int DEFAULT NULL COMMENT '删除时间（软删除）',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_role_key` (`code`),
  KEY `idx_status` (`status`),
  KEY `idx_delete_time` (`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='角色表';

-- --------------------------------------------------------

--
-- 表的结构 `am_system_role_dept`
--

CREATE TABLE `am_system_role_dept` (
  `role_id` int NOT NULL COMMENT '角色ID',
  `dept_id` int NOT NULL COMMENT '部门ID',
  PRIMARY KEY (`role_id`,`dept_id`),
  KEY `idx_role_id` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='角色自定义数据权限部门';

-- --------------------------------------------------------

--
-- 表的结构 `am_system_role_menu`
--

CREATE TABLE `am_system_role_menu` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT '关联ID',
  `role_id` int NOT NULL COMMENT '角色ID',
  `menu_id` int NOT NULL COMMENT '菜单ID',
  PRIMARY KEY (`id`),
  KEY `idx_role_id` (`role_id`),
  KEY `idx_menu_id` (`menu_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='角色-菜单关联表';

-- --------------------------------------------------------

--
-- 表的结构 `am_system_sms_provider`
--

CREATE TABLE `am_system_sms_provider` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL DEFAULT '' COMMENT '供应商名称，如：阿里云短信、腾讯云短信',
  `type` int UNSIGNED DEFAULT NULL COMMENT '关联配置ID（am_system_config）',
  `code` varchar(30) NOT NULL DEFAULT '' COMMENT '供应商标识（easy-sms 网关名）：aliyun / tencent / huawei 等',
  `config` json DEFAULT NULL COMMENT '供应商配置（JSON），结构因 code 不同而不同',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态：1=启用,0=禁用',
  `is_default` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否默认供应商',
  `is_sys` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否内置（内置不可删）',
  `remark` varchar(255) DEFAULT '' COMMENT '备注',
  `created_by` int DEFAULT NULL COMMENT '创建人',
  `updated_by` int DEFAULT NULL COMMENT '更新人',
  `create_time` int DEFAULT '0' COMMENT '创建时间',
  `update_time` int DEFAULT '0' COMMENT '更新时间',
  `delete_time` int DEFAULT '0' COMMENT '删除时间',
  PRIMARY KEY (`id`),
  KEY `idx_type` (`type`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='短信供应商配置表';

-- --------------------------------------------------------

--
-- 表的结构 `am_system_storage`
--

CREATE TABLE `am_system_storage` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '名称',
  `type` int DEFAULT NULL COMMENT '类型',
  `adapter` varchar(32) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '适配器',
  `config` json DEFAULT NULL COMMENT '配置',
  `is_sys` tinyint(1) DEFAULT '0' COMMENT '是否内置',
  `status` tinyint(1) NOT NULL COMMENT '状态',
  `created_by` int NOT NULL DEFAULT '0' COMMENT '创建人',
  `updated_by` int NOT NULL DEFAULT '0' COMMENT '更新人',
  `create_time` int DEFAULT NULL COMMENT '创建时间',
  `update_time` int DEFAULT NULL COMMENT '更新时间',
  `delete_time` int DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='存储表';

-- --------------------------------------------------------

--
-- 表的结构 `am_system_tags`
--

CREATE TABLE `am_system_tags` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(64) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '标签名称',
  `name_py` varchar(128) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '标签拼音',
  `user_ids` json DEFAULT NULL COMMENT '用户id集合',
  `dept_ids` json DEFAULT NULL COMMENT '部门id集合',
  `created_by` int NOT NULL DEFAULT '0' COMMENT '创建人',
  `updated_by` int NOT NULL DEFAULT '0' COMMENT '更新人',
  `create_time` int DEFAULT NULL COMMENT '创建时间',
  `update_time` int DEFAULT NULL COMMENT '更新时间',
  `delete_time` int DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- 表的结构 `am_system_user`
--

CREATE TABLE `am_system_user` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT '用户ID',
  `sid` varchar(32) DEFAULT NULL COMMENT 'sourceId全局唯一ID',
  `username` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT '用户名（唯一）',
  `password` varchar(100) NOT NULL COMMENT '加密密码',
  `nickname` varchar(50) NOT NULL COMMENT '昵称',
  `gender` tinyint(1) NOT NULL DEFAULT '2' COMMENT '性别',
  `salt` varchar(4) DEFAULT NULL COMMENT '加密盐',
  `dept_id` int DEFAULT '0' COMMENT '所属部门ID，主部门',
  `email` varchar(100) DEFAULT '' COMMENT '邮箱（唯一）',
  `mobile` varchar(20) DEFAULT '' COMMENT '手机号（唯一）',
  `avatar_id` int DEFAULT NULL COMMENT '头像ID',
  `motto` varchar(255) DEFAULT NULL COMMENT '签名',
  `account_type` tinyint(1) NOT NULL DEFAULT '1' COMMENT '账号身份类别：1=管理员,2=成员（统一主体，会员注册写2）',
  `status` tinyint DEFAULT '1' COMMENT '状态（0禁用/1正常）',
  `last_login_time` int DEFAULT NULL COMMENT '最后登录时间',
  `last_login_ip` varchar(50) DEFAULT '' COMMENT '最后登录IP',
  `created_by` int DEFAULT NULL COMMENT '创建者',
  `updated_by` int DEFAULT NULL COMMENT '更新者',
  `create_time` int DEFAULT NULL COMMENT '创建时间',
  `update_time` int DEFAULT NULL COMMENT '修改时间',
  `delete_time` int DEFAULT NULL COMMENT '删除时间（软删除）',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_username` (`username`),
  UNIQUE KEY `sid` (`sid`(22)),
  KEY `idx_dept_id` (`dept_id`),
  KEY `idx_status` (`status`),
  KEY `idx_delete_time` (`delete_time`),
  KEY `idx_account_type` (`account_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='系统用户表';

-- --------------------------------------------------------

--
-- 表的结构 `am_system_user_login_log`
--

CREATE TABLE `am_system_user_login_log` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT '日志ID',
  `username` varchar(50) NOT NULL COMMENT '用户名',
  `ip` varchar(45) DEFAULT NULL COMMENT '登录IP地址',
  `location` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'IP所属地',
  `os` varchar(50) DEFAULT NULL COMMENT '操作系统',
  `browser` varchar(50) DEFAULT NULL COMMENT '浏览器',
  `status` tinyint(1) DEFAULT '1' COMMENT '登录状态 (1成功 2失败)',
  `message` varchar(50) DEFAULT NULL COMMENT '提示消息',
  `create_time` int DEFAULT NULL COMMENT '登录时间',
  PRIMARY KEY (`id`),
  KEY `idx_username` (`username`),
  KEY `idx_create_time` (`create_time`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='登录日志表';

-- --------------------------------------------------------

--
-- 表的结构 `am_system_user_role`
--

CREATE TABLE `am_system_user_role` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT '关联ID',
  `user_id` int NOT NULL COMMENT '用户ID',
  `role_id` int NOT NULL COMMENT '角色ID',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_user_role` (`user_id`,`role_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_role_id` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='用户-角色关联表';

COMMIT;
