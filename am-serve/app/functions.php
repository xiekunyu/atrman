<?php
use Tinywan\Jwt\JwtToken;
use Webman\Route;

/**
 * Here is your custom functions.
 */
if (!function_exists('success')) {
    function success($msg, $data = '', $total = 0, $page = 1, $code = 200)
    {
        return ret($code, $msg, $data, $total, $page);
    }
}

if (!function_exists('warning')) {
    /**
     * 返回警告json信息
     */
    function warning($msg, $data = '', $total = 0, $page = 1 , $code = 400)
    {
        return success($msg ? : trans('system.fail'), $data, $total, $page, $code);
    }
}

if (!function_exists('error')) {
    /**
     * 返回服务器错误json信息
     */
    function error($msg, $code = 500)
    {
        return ret($code, trans('system.error').'：'.$msg ? : trans('system.fail'));
    }
}


/**
 * ajax数据返回，规范格式
 * @param array $data   返回的数据，默认空数组
 * @param string $msg   信息
 * @param int $code     错误码，0-未出现错误|其他出现错误
 * @param array $extend 扩展数据
 */
function ret($code, $msg = "",$data = [],$total=0, $page=0)
{
    $ret = ["code" =>$code, "msg" => $msg,'total'=>$total, "data" => $data,'page'=>$page];
    return json($ret);
}

if (!function_exists('getCurrentInfo')) {
    /**
     * 获取当前登录用户
     */
    function getCurrentInfo(): bool|array
    {
        if (!request()) {
            return false;
        }
        try {
            $token = JwtToken::getExtend();
        } catch (\Throwable $e) {
            return false;
        }
        // 退出登录后的令牌不再算登录态（名单里没有 jti 的存量令牌一律放行，见 JwtRevoker）
        if (\app\basic\JwtRevoker::isRevoked((string)($token['jti'] ?? ''))) {
            return false;
        }
        return $token;
    }
}

if (!function_exists('currentUserId')) {
    /** 当前登录用户 ID（未登录返回 0） */
    function currentUserId(): int
    {
        $info = getCurrentInfo();
        return (int) ($info['id'] ?? 0);
    }
}

if (!function_exists('isSuperAdmin')) {
    /** 是否超级管理员（沿用基座 id===1 约定） */
    function isSuperAdmin(?int $uid = null): bool
    {
        return (int) ($uid ?? currentUserId()) === 1;
    }
}

if (!function_exists('currentLevel')) {
    /** 当前用户的行政级别（所有角色 level 最大值；超管为最大值） */
    function currentLevel(): int
    {
        $uid = currentUserId();
        if ($uid <= 0) {
            return 0;
        }
        if (isSuperAdmin($uid)) {
            return PHP_INT_MAX;
        }
        $info = \app\cache\UserInfoCache::getUserInfo($uid);
        return (int) ($info['role_level'] ?? 0);
    }
}

if (!function_exists('currentDataScope')) {
    /** 当前用户的数据可见范围解析结果 {all, self, dept_ids} */
    function currentDataScope(): array
    {
        return \app\service\DataScopeService::resolve(currentUserId());
    }
}

if (!function_exists('normalizePluginName')) {
    /**
     * 规范化插件标识前缀。
     */
    function normalizePluginName(string $name): string
    {
        $name = trim($name);
        return str_starts_with($name, 'am_') ? $name : 'am_' . $name;
    }
}

if (!function_exists('fastRoute')) {
    /**
     * 快速注册路由[index|save|update|read|destroy|import|export]
     * @param string $name
     * @param string $controller
     * @return void
     */
    function fastRoute(string $name, string $controller): void
    {
        $name = trim($name, '/');
        if (method_exists($controller, 'index'))
            Route::get("/$name/index", [$controller, 'index']);
        if (method_exists($controller, 'save'))
            Route::post("/$name/save", [$controller, 'save']);
        if (method_exists($controller, 'update'))
            Route::post("/$name/update", [$controller, 'update']);
        if (method_exists($controller, 'read'))
            Route::get("/$name/read", [$controller, 'read']);
        if (method_exists($controller, 'destroy'))
            Route::delete("/$name/destroy", [$controller, 'destroy']);
        if (method_exists($controller, 'import'))
            Route::post("/$name/import", [$controller, 'import']);
        if (method_exists($controller, 'export'))
            Route::post("/$name/export", [$controller, 'export']);
        if (method_exists($controller, 'delete'))
            Route::post("/$name/delete", [$controller, 'delete']);
        if (method_exists($controller, 'batchDelete'))
            Route::post("/$name/batchDelete", [$controller, 'batchDelete']);
        if (method_exists($controller, 'changeStatus'))
            Route::post("/$name/changeStatus", [$controller, 'changeStatus']);
    }
}

// 密码加密
if (!function_exists('passwordHash')) {
    function passwordHash($password,$salt)
    {
        return password_hash($salt.$password.$salt, PASSWORD_DEFAULT);
    }
}

// 密码验证
if (!function_exists('passwordVerify')) {
    function passwordVerify($password,$hash,$salt)
    {
        return password_verify($salt.$password.$salt, $hash);
    }
}   

/**
 * 生成Artd菜单
 * @param array $data
 * @param string $children
 * @param string $id
 * @param string $pid
 * @return array
 */
if (!function_exists('createArtdMenus')) {
    function createArtdMenus(array $data, string $children = 'children', string $id = 'id', string $pid = 'pid')
    {
        $list = [];
        foreach ($data as $value) {
            $component = '';
            if ($value['type'] === 1) {
                // 目录（catalog）布局容器组件：
                // - 插件菜单（app_name 非空）：子应用后台布局在 /manage/index（脚手架 views/manage/index.vue）
                // - 基座自身菜单（app_name 为空）：沿用基座布局 /index/index
                $component = !empty($value['app_name']) ? '/manage/index' : '/index/index';
            }
            if ($value['type'] === 2) {
                $component = $value['component'];
            }
            $temp = [
                $id => $value[$id],
                $pid => $value[$pid],
                'name' => $value['code'],
                'path' => $value['path'],
                'component' => $component,
                'meta' => [
                    'title' => $value['name'],
                    'icon' => $value['icon'],
                    'isIframe' => $value['is_iframe'] === 1,
                    'keepAlive' => $value['is_cache'] === 1,
                    'isHide' => $value['is_hide'] === 1,
                    'fixedTab' => $value['is_fixed'] === 1,
                    'isFullPage' => $value['is_full_page'] === 1,
                    'appName' => $value['app_name'] ?? '',
                ],
            ];
            if ($value['type'] === 4) {
                $temp['path'] = '/outside/Iframe';
                $temp['meta']['link'] = $value['path'];
            }else{
                $temp['meta']['isIframe'] = 0;
            }
            $list[$value[$id]] = $temp;
        }
        $tree = [];
        foreach ($list as $item) {
            if (isset($list[$item[$pid]])) {
                $list[$item[$pid]][$children][] = &$list[$item[$id]];
            } else {
                $tree[] = &$list[$item[$id]];
            }
        }
        return $tree;
    }
}

/**
 * 递归解析菜单树中的图片图标：将本地相对路径（如插件入口 /app/{name}/logo.png）
 * 拼好资源域名后返回，前台直接作为图片地址渲染，无需再推断接口地址。
 * Iconify 图标名（如 ep:home）与已带域名的绝对地址原样保留。
 */
if (!function_exists('resolveMenuIcons')) {
    function resolveMenuIcons(array $menus): array
    {
        foreach ($menus as $key => $menu) {
            $icon = (string)($menu['meta']['icon'] ?? '');
            if ($icon !== '' && str_starts_with($icon, '/')) {
                $menus[$key]['meta']['icon'] = withAssetDomain($icon);
            }
            if (!empty($menu['children']) && is_array($menu['children'])) {
                $menus[$key]['children'] = resolveMenuIcons($menu['children']);
            }
        }
        return $menus;
    }
}

/**
 * 获取某个节点下的所有子节点
 * @param mixed $pid 父节点ID
 * @param array $allNodes 所有节点数据
 * @param string $idField ID字段名
 * @param string $pidField 父ID字段名
 * @param bool $includeSelf 是否包含自身节点
 * @return array
 */
if (!function_exists('findAllChildren')) {
    function findAllChildren($pid, $allNodes, $includeSelf = false, $idField = 'id', $pidField = 'pid') {
        $allChildren = [];
        
        // 如果需要包含自身节点，自身只有一个节点
        if ($includeSelf) {
            $allChildren = array_filter($allNodes, function($node) use ($pid, $idField) {
                return $node[$idField] == $pid;
            });
        }
        
        // 内部递归逻辑
        $recursiveFind = function($pid) use (&$allChildren, &$recursiveFind, $allNodes, $idField, $pidField) {
            // 找当前父ID的直接子节点
            $directChildren = array_filter($allNodes, function($node) use ($pid, $pidField) {
                return $node[$pidField] == $pid;
            });
            // 遍历并递归
            foreach ($directChildren as $child) {
                $allChildren[] = $child;
                $recursiveFind($child[$idField]);
            }
        };
        
        $recursiveFind($pid);
        return $allChildren;
    }
}

/**
 * GD库等比例缩放图片
 * @param string $srcPath 原图路径
 * @param string $dstPath 输出缩放后图片路径
 * @param int $maxWidth 最大宽度
 * @param int $maxHeight 最大高度
 * @param int $quality 图片质量（1-100，仅JPG有效）
 * @return bool 成功返回true，失败返回false
 */
function scaleImage($srcPath, $dstPath, $maxWidth, $maxHeight, $quality = 80) {
    // 1. 获取原图信息
    $srcInfo = getimagesize($srcPath);
    if (!$srcInfo) return false;
    $srcW = $srcInfo[0]; // 原图宽度
    $srcH = $srcInfo[1]; // 原图高度
    $srcType = $srcInfo[2]; // 图片类型

    // 2. 计算等比例缩放尺寸（核心：保证不变形）
    $scale = min($maxWidth / $srcW, $maxHeight / $srcH);
    $dstW = round($srcW * $scale);
    $dstH = round($srcH * $scale);

    // 3. 根据图片类型创建原图资源
    switch ($srcType) {
        case IMAGETYPE_JPEG:
            $srcImg = imagecreatefromjpeg($srcPath);
            break;
        case IMAGETYPE_PNG:
            $srcImg = imagecreatefrompng($srcPath);
            break;
        case IMAGETYPE_GIF:
            $srcImg = imagecreatefromgif($srcPath);
            break;
        default:
            return false; // 不支持的格式
    }

    // 4. 创建缩放后的画布
    $dstImg = imagecreatetruecolor($dstW, $dstH);

    // 5. 处理PNG透明背景（避免变黑）
    if ($srcType == IMAGETYPE_PNG) {
        imagealphablending($dstImg, false);
        imagesavealpha($dstImg, true);
    }

    // 6. 核心：缩放图片（重采样，保证清晰度）
    imagecopyresampled(
        $dstImg,   // 目标画布
        $srcImg,   // 原图资源
        0, 0,      // 目标坐标
        0, 0,      // 原图坐标
        $dstW, $dstH, // 目标尺寸
        $srcW, $srcH  // 原图尺寸
    );

    // 7. 输出保存缩放后的图片
    $result = false;
    switch ($srcType) {
        case IMAGETYPE_JPEG:
            $result = imagejpeg($dstImg, $dstPath, $quality);
            break;
        case IMAGETYPE_PNG:
            $result = imagepng($dstImg, $dstPath);
            break;
        case IMAGETYPE_GIF:
            $result = imagegif($dstImg, $dstPath);
            break;
    }

    // 8. 释放内存（必须操作）
    imagedestroy($srcImg);
    imagedestroy($dstImg);

    return $result;
}

// 根据文件后缀进行分类
function getFileType($ext,$rst=false){
    $ext=strtolower($ext);
    $image=['jpg','jpeg','png','bmp','gif','webp','ico'];
    $radio=['mp3','wav','wmv','amr'];
    $video=['mp4','3gp','avi','m2v','mkv','mov'];
    $doc=['ppt','pptx','doc','docx','xls','xlsx','pdf','txt','md'];
    $zip=['zip', 'rar', '7z', 'tar', 'gz'];
    $msgType='file';
    if(in_array($ext,$image)){
        $fileType=1;
        $msgType='image';
    }elseif(in_array($ext,$radio)){
        $fileType=2;
        $msgType='voice';
    }elseif(in_array($ext,$video)){
        $fileType=3;
        $msgType='video';
    }elseif(in_array($ext,$doc)){
        $fileType=4;
        $msgType='doc';
    }elseif(in_array($ext,$zip)){
        $fileType=5;
        $msgType='zip';
    }else{
        $fileType=9;
    }
    if($rst){
        return $msgType;
    }else{
        return $fileType;
    }
}

// 获取图片的长宽比
function getImageSizeInfo($file){
    $extends=[];
    // 如果图片获取图片的尺寸
    $imageSize = getimagesize($file);
    $extends['width']=$imageSize[0];
    $extends['height']=$imageSize[1];
    // 如果宽大于高则为横图，宽度填充模式，否则为竖图，高度填充模式
    if($imageSize[0]>=$imageSize[1]){
        $extends['fixMode']=1;  // 宽度填充
    }else{
        $extends['fixMode']=2;  // 高度填充
    }
    if($imageSize[0]<200 && $imageSize[1]<240){
        $extends['fixMode']=3;    // 小图
    }
    return $extends;
}

// 创建临时文件
function createTempFile(mixed $file,string $ext, bool $is_upload=true, string $pinnedIp = '') {
    // 目标临时目录
    $tempDir = runtime_path() . '/temp';
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0755, true);
    }
    $tempName = uniqid() . '.' . $ext;
    $tempPath = $tempDir . DIRECTORY_SEPARATOR . $tempName;
    if($is_upload){
        $source = $file->getPathname();
        copy($source, $tempPath);
        return $tempPath;
    }else{
        $res=app\utils\File::downloadRemoteFile($file,$tempPath,60,$pinnedIp);
        if($res===true){
            return $tempPath;
        }
        return false;
    }
    
}

/**
 * 获取标准完整的文件信息
 * @param string $filepath 文件绝对/相对路径
 * @return array|false 标准文件信息数组，失败返回false
 */
function getFileInfo(string $filepath)
{
    // 1. 基础校验：文件是否存在 & 是合法文件
    if (!file_exists($filepath) || !is_file($filepath)) {
        return false;
    }

    try {
        $file = new SplFileObject($filepath);
        
        // 2. 获取真实路径（解决软链接/相对路径问题）
        $realPath = $file->getRealPath();
        
        // 3. 获取标准 MIME 类型（兼容 PHP 所有版本）
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($realPath);

        // 4. 组装标准文件信息（补充常用字段 + 格式化大小）
        $fileInfo = [
            'origin_name'   => $file->getFilename(),       // 原始文件名
            'save_name'     => $file->getFilename(),       // 存储文件名
            'save_path'     => $filepath,                  // 传入路径
            'real_path'     => $realPath,                  // 真实绝对路径（重要）
            'extension'     => $file->getExtension(),       // 文件后缀（无点）
            'base_name'     => $file->getBasename(),       // 文件名（含后缀）
            'size'          => $file->getSize(),            // 字节大小
            'mime_type'     => $mimeType,                  // 标准MIME类型
            'create_time'   => date('Y-m-d H:i:s', $file->getCTime()), // 创建时间
            'modify_time'   => date('Y-m-d H:i:s', $file->getMTime()), // 修改时间
            'access_time'   => date('Y-m-d H:i:s', $file->getATime()), // 访问时间
            'is_readable'   => $file->isReadable(),        // 是否可读
            'is_writable'   => $file->isWritable()         // 是否可写
        ];

        return $fileInfo;

    } catch (Exception $e) {
        // 异常处理：文件权限不足、损坏等
        return false;
    }
}

/**
 * 规范化ID数组
 * @param mixed $ids 原始ID数组或逗号分隔字符串
 * @param bool $strict 严格模式（默认true）：仅接受数组，转换为int并去重；宽松模式：接受字符串，保留原始值
 * @return array 规范化后的ID数组
 */
if(!function_exists('normalizeIds')){
   function normalizeIds(mixed $ids, bool $strict = true): array
    {
        // 支持逗号分隔字符串（宽松模式）
        if (is_string($ids) && str_contains($ids, ',')) {
            $ids = array_filter(explode(',', $ids), fn($v) => $v !== '' && $v !== null);
        }
        
        if (!is_array($ids)) {
            return $strict ? [] : [$ids];
        }
        
        if ($strict) {
            // 严格模式：转换为int，过滤<=0，去重
            $ids = array_map('intval', $ids);
            $ids = array_filter($ids, fn($id) => $id > 0);
            return array_values(array_unique($ids));
        }
        
        // 宽松模式：保留原始值，只过滤空值
        return array_values(array_filter($ids, fn($id) => $id !== '' && $id !== null));
    }
}

/**
 * 将 JSON 字符串、对象等值规范化为数组
 */
if(!function_exists('normalizeArray')){
    function normalizeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if ($value instanceof \JsonSerializable) {
            return normalizeArray($value->jsonSerialize());
        }
        if ($value instanceof \stdClass) {
            return json_decode(json_encode($value), true) ?: [];
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }
}

/**
 * 格式化 JSON 字符串
 */
if(!function_exists('jsonEncodePretty')){
    function jsonEncodePretty(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }
}

/**
 * 判断当前版本是否大于等于指定版本
 */
if(!function_exists('versionGte')){
    function versionGte(string $current, string $required): bool
    {
        return version_compare(ltrim($current, 'vV'), ltrim($required, 'vV'), '>=');
    }
}

/**
 * 获取当前资源域名
 */
if(!function_exists('assetDomain')){
    function assetDomain(): string
    {
        $request = request();
        $domain = '';
        if ($request) {
            $host = (string)$request->host();
            $scheme = (string)($request->header('x-forwarded-proto') ?: $request->header('x-scheme') ?: 'http');
            $domain = preg_match('/^https?:\/\//i', $host) ? $host : $scheme . '://' . $host;
        }
        return rtrim($domain, '/');
    }
}

/**
 * 给本地资源地址补齐域名
 */
if(!function_exists('withAssetDomain')){
    function withAssetDomain(string $url): string
    {
        // 只判断是否带有http协议，如果是相对路径，拼接域名地址
        if (strpos($url, 'http') === false) {
            return assetDomain() . $url;
        }
        return $url;
    }
}

/**
 * 规范化可下载地址
 */
if(!function_exists('normalizeDownloadUrl')){
    function normalizeDownloadUrl(string $url): string
    {
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }
        if (str_starts_with($url, '/')) {
            $domain = assetDomain();
            if ($domain === '') {
                throw new \app\exception\ApiException('插件图标相对地址缺少域名配置');
            }
            return $domain . $url;
        }
        if (!preg_match('/^https?:\/\//i', $url)) {
            throw new \app\exception\ApiException('插件图标地址必须是 http 或 https');
        }
        return $url;
    }
}

/**
 * 校验下载地址不指向内网地址
 *
 * 返回一个已判定为公网的 IP，调用方应把它传给下载函数做连接层固定
 * （curl 的 CURLOPT_RESOLVE）：否则「校验时解析一次、curl 连接时再解析一次」
 * 之间留有 DNS 重绑定窗口，第二次解析可以指到内网。
 * 注意这只锁住首跳；跟随重定向后的目标仍走常规解析，属已知残余。
 */
if(!function_exists('assertPublicUrl')){
    function assertPublicUrl(string $url): string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = (string)($parts['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new \app\exception\ApiException('地址必须是 http 或 https');
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            assertPublicIp($host);
            // host 本身就是 IP，curl 不再解析，无需固定
            return '';
        }

        $ips = gethostbynamel($host) ?: [];
        if (function_exists('dns_get_record')) {
            foreach (dns_get_record($host, DNS_AAAA) ?: [] as $record) {
                if (!empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }
        if (!$ips) {
            throw new \app\exception\ApiException('地址域名解析失败');
        }
        foreach ($ips as $ip) {
            assertPublicIp($ip);
        }
        return (string)$ips[0];
    }
}

/**
 * 校验 IP 是否为公网地址
 */
if(!function_exists('assertPublicIp')){
    function assertPublicIp(string $ip): void
    {
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        if (!filter_var($ip, FILTER_VALIDATE_IP, $flags)) {
            throw new \app\exception\ApiException('地址不能指向内网地址');
        }
    }
}

if(!function_exists('escapeLike')){
    /**
     * 转义 LIKE 通配符：后台关键字搜索里用户输入的 % 与 _ 应当作字面量匹配。
     *
     * 不转义时 `%` 单独一个字符就能让查询退化成全表扫描（前缀通配本来已无法走索引，
     * 再加上任意长度通配会把扫描范围放到整表）。先转义反斜杠，再转义 % 和 _。
     * MySQL 的 LIKE 默认转义符就是反斜杠，且 ThinkORM 走参数绑定，字面量里的反斜杠不受
     * NO_BACKSLASH_ESCAPES 影响。
     */
    function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}

/**
 * 校验文件是否为图片
 */
if(!function_exists('assertImageFile')){
    function assertImageFile(string $file): void
    {
        $info = @getimagesize($file);
        $mime = strtolower((string)($info['mime'] ?? ''));
        if (!$info || !in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) {
            throw new \app\exception\ApiException('插件图标必须是 PNG、JPG、GIF 或 WebP 图片');
        }
    }
}

/**
 * 基座后台入口路径（如 /index.html，或安装时随机命名的安全入口）。
 *
 * 站点根 '/' 可能被「网站配置-前端入口」改跳去应用前台，凡是要在站内落回 am-web 的
 * 绝对地址（SSO 回跳锚点、回跳白名单）都必须用它而不是 '/'，两处取值必须同源否则换票必断。
 */
if(!function_exists('adminEntryPath')){
    function adminEntryPath(): string
    {
        $file = trim((string) env('APP_ENTRY_FILE', 'index.html'));
        // 去掉可能误写的-leading slash，否则拼出 '//xxx.html' 会被当成协议相对外域
        return '/' . ($file === '' ? 'index.html' : ltrim($file, '/'));
    }
}

/**
 * 统一认证入口基址（会员中心前台入口，返回时不带尾斜杠）。
 *
 * 取值优先级：网站配置 website.auth_entry → 会员中心插件 manifest 的 entry → 约定 /app/{插件名}。
 * 配置项为空即未启用统一认证入口，行为与接管前一致（各端仍用构建期 VITE_MEMBER_ENTRY / 这里的约定值兜底）。
 *
 * 注意拼出的地址只作为 # 之前的基址：hash 片段由调用方附加，配置值本身不允许协议相对形态（//host）。
 */
if(!function_exists('authEntry')){
    function authEntry(): string
    {
        $configured = trim((string)(\app\model\system\Config::getConfig('website')['auth_entry'] ?? ''));
        if ($configured !== '' && !str_starts_with($configured, '//')) {
            return rtrim($configured, '/');
        }
        $name = (string) env('SSO_MEMBER_APP', 'am_member');
        $plugin = \app\model\plugin\App::where('name', $name)->where('status', 1)->find();
        $entry = $plugin
            ? trim((string)(\app\service\PluginManifestService::getManifest($plugin)['entry'] ?? ''))
            : '';
        return rtrim($entry !== '' ? $entry : '/app/' . $name, '/');
    }
}


