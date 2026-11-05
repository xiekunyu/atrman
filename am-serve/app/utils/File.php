<?php
/**
 * lvzheAdmin
 * @author xiekunyu <raingad@foxmail.com>
 * @license http://www.apache.org/licenses/LICENSE-2.0
 */
namespace app\utils;

//------------------------
// 文件下载和图片下载
//-------------------------

class File
{
    /**
     * 文件下载
     * @param $file_path
     * @param string $file_name
     * @param string $file_size
     * @param string $ext
     */
    public static function download($file_path, $file_name = '', $file_size = '', $ext = '')
    {
        if (!$file_name) {
            $file_name = basename($file_path);
        }
        if (!$file_size && in_array(substr($file_path, 0, 1), [".", "/"])) {
            try {
                $file_size = filesize($file_path);
            } catch (\Exception $e) {
                return "文件不存在";
            }
        }
        if (!$ext) {
            $ext = pathinfo($file_path, PATHINFO_EXTENSION);
        }
        if ($ext && !strpos($file_name, ".")) {
            $file_name = $file_name . "." . $ext;
        }
        $content_type = self::getMime($ext);
        header("Cache-Control:");
        header("Cache-Control: public");
        // 处理中文文件名
        $ua = $_SERVER["HTTP_USER_AGENT"];
        $encoded_filename = rawurlencode($file_name);
        
        // 文件类型
        if (preg_match("/Safari/", $ua)) {
            header('Content-Type: application/octet-stream;charset=utf-8');
        } else {
            header("Content-type: {$content_type}");
        }
        //IE
        if (preg_match("/MSIE/", $ua) || preg_match("/Trident\/7.0/", $ua)) {
            header('Content-Disposition: attachment; filename="' . $encoded_filename . '"');
        } else if (preg_match("/Firefox/", $ua)) {
            header('Content-Disposition: attachment; filename*="utf8\'\'' . $file_name . '"');
        } else if (preg_match("/Safari/", $ua)) {
            header('Content-Disposition: attachment; filename*=UTF-8\'\'' . $encoded_filename);
        } else {
            header('Content-Disposition: attachment; filename="' . $file_name . '"');
        }
        // 文件大小
        if ($file_size) {
            header("Accept-Length: " . $file_size);
            header("Content-Length: " . $file_size);
        }
        readfile($file_path);
    }

    /**
     * 功能：php多种方式完美实现下载远程图片保存到本地
     * 参数：文件url,保存文件名称，使用的下载方式
     * 当保存文件名称为空时则使用远程文件原来的名称
     * @param string $url      请求图片的链接
     * @param string $filename 保存的文件名
     * @param int $type        保存图片的类型 0为curl,适用于静态图片,其他为缓冲缓存,适用于动态图片
     * @return string $filename 返回保存的文件名
     */
    public static function downloadImage($url, $filename, $type = 0)
    {
        if ($url == '') {
            return false;
        }
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'ico', 'tif', 'tiff'])) {
            $ext = pathinfo($url, PATHINFO_EXTENSION);
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'ico', 'tif', 'tiff'])) {
                $ext = 'jpg';
            }
            $filename = $filename . "." . $ext;
        }

        //下载文件流
        if ($type) {
            $ch = curl_init();
            $timeout = 5;
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
            $img = curl_exec($ch);
            curl_close($ch);
        } else {
            ob_start();
            readfile($url);
            $img = ob_get_contents();
            ob_end_clean();
        }

        //保存文件
        //        try {
        $fp2 = fopen($filename, 'w');
        fwrite($fp2, $img);
        fclose($fp2);
        return $filename;
        /*} catch (\think\Exception $e) {
    //TODO 异常处理
    return false;
    }*/
    }

    /**
     * 出站 HTTPS 是否校验证书：下载与 app\utils\Curl 封装共用这一条口径，避免出现两处不同的开关名。
     *
     * 出厂默认校验：不校验等于任何中间人都能替换回包，而下载内容随后会被当作插件包/图标写盘。
     * 显式 DOWNLOAD_VERIFY_TLS=false 才关闭。开发态（APP_DEBUG=true）默认不校验——
     * 本机 curl 常没有 CA 根证书包，强行校验会让合法 https 请求报 cURL 60，炸的是开发者。
     */
    public static function verifyTls(): bool
    {
        return filter_var(
            env('DOWNLOAD_VERIFY_TLS', config('app.debug', false) ? 'false' : 'true'),
            FILTER_VALIDATE_BOOLEAN
        );
    }

/**
 * 服务器端下载远程文件到本地
 * @param string $remoteUrl 远程文件URL
 * @param string $savePath 本地保存路径（含文件名）
 * @param int $timeout 超时时间（秒）
 * @param string $pinnedIp 调用方已校验的公网 IP，非空时在连接层固定解析结果
 * @return bool|string 成功返回true，失败返回错误信息
 */
public static function downloadRemoteFile(string $remoteUrl, string $savePath, int $timeout = 60, string $pinnedIp = ''): bool|string
{
        // 1. 检查远程URL是否合法
        if (!filter_var($remoteUrl, FILTER_VALIDATE_URL)) {
            return "无效的URL地址";
        }

        // 2. 初始化curl
        $ch = curl_init($remoteUrl);

        // 3. 打开本地文件句柄（写入模式）
        $fp = fopen($savePath, 'wb');
        if (!$fp) {
            curl_close($ch);
            return "无法创建本地文件，请检查目录权限";
        }

        // TLS 校验开关见 verifyTls()（下载与 Curl 封装共用一条口径）
        $verifyTls = self::verifyTls();

        // 4. CURL核心配置
        $options = [
            CURLOPT_TIMEOUT => $timeout,        // 超时
            CURLOPT_FOLLOWLOCATION => true,     // 自动跟随302跳转
            // 锁死协议：FILTER_VALIDATE_URL 允许 file:///gopher:// 这类地址，不锁协议 + 跟随跳转
            // 等于让 curl 变成任意协议客户端，重定向还能绕过调用方的 host 校验
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => $verifyTls,
            CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
            CURLOPT_FILE => $fp,                // 写入到文件
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36', // 模拟浏览器
        ];
        // 固定首跳解析：host 校验（assertPublicUrl）与实际连接之间有一次解析的间隔，
        // TTL 极短的域名可以在间隔内把 A 记录换成内网地址，把 host 绑到已校验的公网 IP 上闭合这个窗口。
        $resolve = self::resolvePinning($remoteUrl, $pinnedIp);
        if ($resolve !== '') {
            $options[CURLOPT_RESOLVE] = [$resolve];
        }
        curl_setopt_array($ch, $options);

        // 5. 执行下载
        $result = curl_exec($ch);

        // 6. 错误处理
        if (curl_errno($ch)) {
            $error = "下载失败：" . curl_error($ch);
            fclose($fp);
            curl_close($ch);
            @unlink($savePath); // 下载失败删除空文件
            return $error;
        }

        // 7. 检查HTTP状态码
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpCode < 200 || $httpCode >= 300) {
            fclose($fp);
            curl_close($ch);
            @unlink($savePath);
            return "下载失败，HTTP状态码：" . $httpCode;
        }

        // 8. 关闭资源
        fclose($fp);
        curl_close($ch);

        return true;
    }

    /**
     * 组装 curl 的 host 固定条目：`HOST:PORT:IP`。
     *
     * host 本身是 IP 时返回空串——curl 对 IP 字面量不做解析，没有可固定的东西。
     */
    private static function resolvePinning(string $remoteUrl, string $pinnedIp): string
    {
        if ($pinnedIp === '' || !filter_var($pinnedIp, FILTER_VALIDATE_IP)) {
            return '';
        }
        $host = (string) parse_url($remoteUrl, PHP_URL_HOST);
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
            return '';
        }
        $scheme = strtolower((string) parse_url($remoteUrl, PHP_URL_SCHEME));
        $port = (int) (parse_url($remoteUrl, PHP_URL_PORT) ?: ($scheme === 'https' ? 443 : 80));

        return "{$host}:{$port}:{$pinnedIp}";
    }

    /**
     * 获取文件Mime
     * @param string $ext
     * @return string
     */
    public static function getMime($ext)
    {
        $mimes = [
            'xml' => 'text/xml',
            'json' => 'application/json',
            'js' => 'text/javascript',
            'php' => 'application/octet-stream',
            'css' => 'text/css',
            'html' => 'text/html',
            'htm' => 'text/html',
            'xhtml' => 'text/html',
            'rss' => 'application/rss+xml',
            'yaml' => 'application/x-yaml',
            'atom' => 'application/atom+xml',
            'pdf' => 'application/pdf',
            'text' => 'text/plain',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'gif' => 'image/gif',
            'csv' => 'text/csv',
            'tif' => 'image/tiff',
            'ai' => 'application/postscript',
            'asp' => 'text/asp',
            'au' => 'audio/basic',
            'avi' => 'video/avi',
            'rmvb' => 'application/vnd.rn-realmedia-vbr',
            '3gp' => 'application/octet-stream',
            'flv' => 'application/octet-stream',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'sql' => 'application/octet-stream',
            'rar' => 'application/octet-stream',
            'zip' => 'application/zip',
            '7z' => 'application/octet-stream',
            'bmp' => 'application/x-bmp',
            'cdr' => 'application/x-cdr',
            'class' => 'java/*',
            'exe' => 'application/x-msdownload',
            'fax' => 'image/fax',
            'icb' => 'application/x-icb',
            'ico' => 'image/x-icon',
            'java' => 'java/*',
            'jfif' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'jsp' => 'text/html',
            'mp4' => 'video/mpeg4',
            'mpa' => 'video/x-mpg',
            'mpeg' => 'video/mpg',
            'mpg' => 'video/mpg',
            'mpga' => 'audio/rn-mpeg',
            'ras' => 'application/x-ras',
            'tiff' => 'image/tiff',
            'txt' => 'text/plain',
            'wax' => 'audio/x-ms-wax',
            'wm' => 'video/x-ms-wm',
            'apk' => 'application/vnd.android.package-archive',
            'doc' => 'application/msword',
            'dot' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'dotx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.template',
            'docm' => 'application/vnd.ms-word.document.macroEnabled.12',
            'dotm' => 'application/vnd.ms-word.template.macroEnabled.12',
            'xls' => 'application/vnd.ms-excel',
            'xlt' => 'application/vnd.ms-excel',
            'xla' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xltx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.template',
            'xlsm' => 'application/vnd.ms-excel.sheet.macroEnabled.12',
            'xltm' => 'application/vnd.ms-excel.template.macroEnabled.12',
            'xlam' => 'application/vnd.ms-excel.addin.macroEnabled.12',
            'xlsb' => 'application/vnd.ms-excel.sheet.binary.macroEnabled.12',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pot' => 'application/vnd.ms-powerpoint',
            'pps' => 'application/vnd.ms-powerpoint',
            'ppa' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'potx' => 'application/vnd.openxmlformats-officedocument.presentationml.template',
            'ppsx' => 'application/vnd.openxmlformats-officedocument.presentationml.slideshow',
            'ppam' => 'application/vnd.ms-powerpoint.addin.macroEnabled.12',
            'pptm' => 'application/vnd.ms-powerpoint.presentation.macroEnabled.12',
            'potm' => 'application/vnd.ms-powerpoint.template.macroEnabled.12',
            'ppsm' => 'application/vnd.ms-powerpoint.slideshow.macroEnabled.12',
        ];
        return isset($mimes[$ext]) ? $mimes[$ext] : 'application/octet-stream';
    }

/**
 * 下载文件到服务器
 * addtime 2020年8月28日 18:38:43
 */
    public static function getFile($url, $save_dir = '', $filename = '', $type = 0)
    {
        if (trim($url) == '') {
            return false;
        }
        if (trim($save_dir) == '') {
            $save_dir = './';
        }
        if (0 !== strrpos($save_dir, '/')) {
            $save_dir .= '/';
        }
        //创建保存目录
        if (!file_exists($save_dir) && !mkdir($save_dir, 0777, true)) {
            return false;
        }
        //获取远程文件所采用的方法
        if ($type) {
            $ch = curl_init();
            $timeout = 5;
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
            $content = curl_exec($ch);
            curl_close($ch);
        } else {
            ob_start();
            readfile($url);
            $content = ob_get_contents();
            ob_end_clean();
        }
        $size = strlen($content);
        //文件大小
        $fp2 = @fopen($save_dir . $filename, 'a');
        fwrite($fp2, $content);
        fclose($fp2);
        unset($content, $url);
        $res['code'] = 200;
        $res['file_name'] = $filename;
        return $res;
    }
    /**
     * 检测文件大小
     */
    public static function getFileSize($url)
    {
        $res = get_headers($url, true);
        $filesize = round($res['Content-Length'] / 1024 / 1024, 2); //四舍五入获取文件大小，单位M
        return $filesize;
    }

}
