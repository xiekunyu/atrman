# syntax=docker/dockerfile:1
#
# Atrman 基座生产镜像（多阶段构建）
# 阶段一 vendor：仅 composer.json/lock 入镜像装依赖，源码变动不击穿依赖层缓存
# 阶段二 runtime：扩展 + opcache + 非 root 运行，镜像不含 .env（见 .dockerignore）

# ============================== stage 1: composer 依赖 ==============================
FROM php:8.3-cli-alpine AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# zip/mbstring/pdo 在依赖解析阶段就可能被 composer 插件用到
RUN apk add --no-cache git unzip libzip-dev oniguruma-dev \
    && docker-php-ext-install -j"$(nproc)" zip mbstring pdo pdo_mysql

WORKDIR /app
COPY composer.json composer.lock ./
# --no-scripts：webman 插件发布脚本依赖完整源码，留到源码拷入后再跑
# --no-autoloader：源码未齐，自动加载在 runtime 阶段 dump-autoload 统一生成
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# ============================== stage 2: 运行镜像 ==============================
FROM php:8.3-cli-alpine AS runtime

# 运行期动态库 + 编译期 -dev 包分组安装，构建结束删除 -dev 与编译工具链
RUN apk add --no-cache \
        libzip libpng libjpeg-turbo freetype libwebp oniguruma libgcc \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        libzip-dev libpng-dev libjpeg-turbo-dev freetype-dev libwebp-dev \
        oniguruma-dev libxml2-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        pdo pdo_mysql pcntl mbstring zip gd dom bcmath exif opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /tmp/pear /var/cache/apk/*

# CLI 常驻进程同样受益于 opcache：代码常驻内存，validate_timestamps=0 省去每次请求的重复编译与 stat
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=1'; \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.save_comments=1'; \
    } > "$PHP_INI_DIR/conf.d/opcache.ini"

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# 依赖层在前、全量源码在后：业务代码改动不会让 composer install 重跑
COPY --from=vendor /app/vendor ./vendor
COPY . .

# 生成优化自动加载（含 webman 插件发现），随后移除 composer 减小攻击面；
# runtime/secrets 为挂载点预留并归属 www-data（php 官方 alpine 镜像内置 uid=82 的 www-data）
RUN composer dump-autoload --optimize --no-dev --no-interaction \
    && rm -f /usr/bin/composer \
    && mkdir -p runtime public/storage secrets \
    && chown -R www-data:www-data /app

USER www-data

EXPOSE 8787

# HTTP 探针：/health 免鉴权且不查依赖，进程能处理请求即 healthy；
# 依赖级就绪状态另由 /health/ready 提供（Redis/DB 任一不可用返 503）
HEALTHCHECK --interval=30s --timeout=10s --start-period=30s --retries=3 \
    CMD php -r "exit(@file_get_contents('http://127.0.0.1:8787/health') === false ? 1 : 0);"

# 容器内以前台模式运行（不带 -d），文件监控由 MONITOR_FILE_ENABLED 显式控制
CMD ["php", "start.php", "start"]
