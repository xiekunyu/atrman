<?php
namespace app\service;

use app\exception\ApiException;
use app\basic\AdapterRegistry;
use app\basic\EventRegistry;
use app\basic\ServiceRegistry;
use app\interface\UploadConfigResolverInterface;
use app\model\system\Config;
use app\model\system\File;
use app\model\system\Storage;
/**
 * 文件上传服务
 * @method static array uploadBase64(string $base64, string $extension = 'png') 上传Base64文件
 * @method static array uploadServerFile(string $file_path)  上传服务端文件
 */
class UploadService
{
    /**
     * @desc 存储磁盘
     * @param int $type
     * @param string $upload
     * @param bool $_is_file_upload
     * @return mixed
     */
    public static function disk(array $config, bool $_is_file_upload = true)
    {
        return new $config['adapter'](array_merge(
            $config,
            [
                '_is_file_upload' => $_is_file_upload,
            ]
        ));
    }

    /**
     * @param $name
     * @param $arguments
     * @return mixed
     * @author Tinywan(ShaoBo Wan)
     */
    public static function __callStatic($name, $arguments)
    {
        $config = $arguments[0] ?? [];
        return static::disk($config)->{$name}(...$arguments);
    }

    // 获取config配置
    public static function getConfig(string $type,array $config)
    {
        $resolver = AdapterRegistry::getConfigResolver($type);

        // 如果有自定义配置解析器，使用它
        if ($resolver !== null) {
            return $resolver($type, $config);
        }

        // 默认走 'upload.config' 接管点：无插件覆盖时返回 DefaultUploadConfigResolver
        //（即原 resolveConfig 逻辑的逐行复刻），覆盖实现异常时自动回退默认
        return ServiceRegistry::make(UploadConfigResolverInterface::KEY)->resolve($type, $config);
    }

    // 删除文件
    public static function delete(string $type,string $path)
    {
        $relativePath = $path;
        $config = config('plugin.tinywan.storage.app');
        $config = self::getConfig($type,$config);
        if ($config['disk'] == 'local') {
            // 直接删除本地文件
            $path = $config['root'].$path;
            if (file_exists($path)) {
                unlink($path);
            }
        } else {
            // 统一适配器删除契约（去 per-disk switch 硬编码）：
            // deleteObject 优先、delete 兜底；内置驱动由基座适配器子类补齐 deleteObject，
            // 自定义驱动实现二者其一即可
            $adapterClass = AdapterRegistry::getAdapterClass($type);
            $adapter = new $adapterClass($config);
            if (method_exists($adapter, 'deleteObject')) {
                $adapter->deleteObject($config['bucket'], $relativePath);
            } elseif (method_exists($adapter, 'delete')) {
                $adapter->delete($config['bucket'], $relativePath);
            } else {
                throw new \Exception('不支持的存储驱动');
            }
        }
        EventRegistry::emit('file.deleted', [
            'type' => $type,
            'path' => $relativePath,
            'disk' => $config['disk'] ?? '',
        ]);
        return true;
    }

    /**
     * 上传文件并写入文件管理记录
     */
    public static function uploadFile(
        array $mainFile,
        array $config,
        string $tempPath = '',
        bool $isRemote = false,
        array $options = []
    ): File {
        $fileType = getFileType($mainFile['ext'], false);
        $isScaleImage = in_array($mainFile['ext'], ['jpg', 'jpeg', 'png', 'gif']);
        if ($isRemote) {
            $result = self::disk($config, false)->uploadServerFile($tempPath);
        } else {
            // 上传文件
            $result = self::disk($config)->uploadFile();
            $result = $result[0] ?? [];
        }
        if (!$result || empty($result)) {
            throw new ApiException('文件上传失败');
        }

        $mainFile = array_merge($mainFile, $result);
        $mainFile['name'] = $options['name'] ?? $mainFile['origin_name'];
        $mainFile['path'] = str_replace('\\', '/', str_replace($config['domain'], '', $mainFile['url']));
        $mainFile['ext'] = $mainFile['extension'];
        $mainFile['file_type'] = $fileType;
        // 创建缩略图
        if ($isScaleImage) {
            $mainFile = self::createThumb($mainFile, $config, $tempPath);
        }
        if ($fileType == 1 && $tempPath) {
            $mainFile['extends'] = getImageSizeInfo($tempPath);
        }
        if (!empty($options['extends'])) {
            $mainFile['extends'] = array_merge($mainFile['extends'] ?? [], $options['extends']);
        }
        $shouldDeleteSource = (bool)($options['delete_source'] ?? true);
        // 保存文件记录
        $fileData = [
            'name' => $mainFile['name'],
            'path' => $mainFile['path'],
            'thumb' => $mainFile['thumb'] ?? '',
            'url' => $config['domain'],
            'ext' => $mainFile['ext'],
            'size' => $mainFile['size'],
            'file_type' => $mainFile['file_type'],
            'md5' => $mainFile['md5'],
            'type' => 2, // 文件类型
            'pid' => $mainFile['pid'],
            'storage_id' => $mainFile['storage_id'],
            'status' => 1,
            'mime_type' => $mainFile['mime_type'] ?? '',
            'extends' => $mainFile['extends'] ?? [],
            'is_lock' => (int)($options['is_lock'] ?? 0),
        ];

        $fileModel = File::create($fileData);
        if (!$fileModel) {
            throw new ApiException('文件记录保存失败');
        }
        if ($shouldDeleteSource && $tempPath && is_file($tempPath)) {
            unlink($tempPath);
        }

        EventRegistry::emit('file.uploaded', [
            'file_id' => (int)$fileModel->id,
            'name' => $fileData['name'],
            'path' => $fileData['path'],
            'ext' => $fileData['ext'],
            'size' => $fileData['size'],
            'storage_id' => $fileData['storage_id'],
        ]);

        return $fileModel;
    }

    /**
     * 按系统上传配置转存服务端本地文件
     */
    public static function saveLocalFile(mixed $sourcePath, array|string $options = [], array $mainFile = [])
    {
        if (is_array($sourcePath)) {
            return self::saveLocalFileByConfig($sourcePath, (string)$options, $mainFile);
        }

        if (!is_array($options)) {
            $options = [];
        }
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new ApiException('本地文件不存在或不可读');
        }

        $uploadConfig = Config::getConfig('upload');
        $allow = is_array($uploadConfig['allow_ext'])
            ? $uploadConfig['allow_ext']
            : json_decode($uploadConfig['allow_ext'], true);
        $ext = strtolower(pathinfo($options['name'] ?? $sourcePath, PATHINFO_EXTENSION));
        if (!$ext || !in_array($ext, $allow ?: [])) {
            throw new ApiException('文件格式错误');
        }
        if (filesize($sourcePath) > ((int)$uploadConfig['limit_size']) * 1024 * 1024) {
            // 允许通过 skip_size_check 选项跳过大小限制（如系统备份上传）
            if (empty($options['skip_size_check'])) {
                throw new ApiException('仅支持上传' . $uploadConfig['limit_size'] . 'MB以下的文件');
            }
        }

        $storageId = (int)($options['storage_id'] ?? $uploadConfig['storage_id']);
        $storageConfig = (array)((new Storage())->getStorageConfig($storageId));
        if (!$storageConfig) {
            throw new ApiException('存储配置不存在');
        }

        $config = self::getConfig($storageConfig['disk'], $storageConfig);
        if (!$config) {
            throw new ApiException('获取存储配置失败');
        }

        // 支持通过选项覆盖子目录（如备份文件统一存入 backup/ 目录）
        if (!empty($options['dirname'])) {
            // 安全过滤：仅允许字母、数字、下划线、中划线
            $config['dirname'] = preg_replace('/[^a-zA-Z0-9_\-]/', '', $options['dirname']);
        }

        return self::uploadFile([
            'ext' => $ext,
            'md5' => hash_file('md5', $sourcePath),
            'pid' => $options['pid'] ?? 0,
            'storage_id' => $storageId,
        ], $config, $sourcePath, true, array_merge($options, ['delete_source' => $options['delete_source'] ?? false]));
    }

    /**
     * 保存本地文件到指定本地配置，不写入文件管理记录
     */
    public static function saveLocalFileByConfig(array $config,string $tempPath,array $mainFile){
        $basePath = $config['root'].$config['dirname'].DIRECTORY_SEPARATOR;
        if (!is_dir($basePath)) {
            mkdir($basePath, 0755, true);
        }

        $fileInfo=getFileInfo($tempPath);
        if($fileInfo===false){
            return false;
        }

        $baseUrl = $config['domain'].$config['uri'].str_replace(DIRECTORY_SEPARATOR, '/', $config['dirname']).'/';
        $saveFilename = $mainFile['md5'].'.'.$mainFile['ext'];
        $savePath = $basePath.$saveFilename;
        $result = [
            'origin_name' => $fileInfo['origin_name'],
            'save_name' => $saveFilename,
            'save_path' => $savePath,
            'url' => $baseUrl.$saveFilename,
            'unique_id' => $mainFile['md5'],
            'size' => $fileInfo['size'],
            'mime_type' => $fileInfo['mime_type'],
            'extension' => $mainFile['ext'],
        ];
        if (!copy($tempPath, $savePath)) {
            return false;
        }
        return $result;
    }

    /**
     * 生成缩略图
     */
    protected static function createThumb(array $mainFile, array $config, string $tempPath): array
    {
        $root = public_path();
        $thumbDir = '/storage/' . date('Ymd') . '/thumb';

        // 确保缩略图目录存在
        if (!is_dir($root . $thumbDir)) {
            mkdir($root . $thumbDir, 0755, true);
        }
        $saveName = $mainFile['save_name'] ?? '';
        if (empty($saveName)) {
            $originName = explode('/', $mainFile['origin_name']);
            $saveName = end($originName);
        }
        $thumbPath = $thumbDir . '/' . $saveName;
        // 先将文件移动到本地目录，再缩放
        // 缩放图片
        $scaleResult = scaleImage($tempPath, $root . $thumbPath, 120, 120);

        if ($scaleResult && $config['disk'] != 'local') {
            $config['dirname'] = trim($thumbDir, '/');
            $thumb = self::disk($config, false)->uploadServerFile($root . $thumbPath);
            if ($thumb && isset($thumb['save_path'])) {
                // 删除本地缩略图文件
                unlink($root . $thumbPath);
                $thumbPath = '/' . $thumb['save_path'];
            }
        }
        $mainFile['thumb'] = $thumbPath;
        return $mainFile;
    }
}


