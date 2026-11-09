<?php

namespace app\controller\system;

use app\basic\BaseController;
use app\model\system\Backup;
use app\model\system\File;
use app\service\BackupService;
use app\service\FileService;
use app\service\Permission;
use app\service\UploadService;
use support\annotation\route\Get;
use support\annotation\route\Post;
use support\annotation\route\RouteGroup;
use support\Request;
use support\Response;

#[RouteGroup('/sys/backup')]
class BackupController extends BaseController
{
    public function initialize(): void
    {
        $this->model = new Backup();
    }

    /**
     * 备份列表（展示系统备份和插件备份）
     */
    #[Get('/index')]
    #[Permission(title: '备份列表', slug: 'system:backup:index')]
    public function index(Request $request): Response
    {
        $where = $request->buildMap([
            ['keywords', ''],
            ['backup_type', ''],
            ['status', ''],
        ]);
        $model = $this->search($this->model, $where);
        $list = $this->getList($model->with(['file']));
        return $this->returnListData($list);
    }

    /**
     * 备份详情
     */
    #[Get('/read')]
    #[Permission(title: '备份详情', slug: 'system:backup:read')]
    public function read(Request $request): Response
    {
        $id = $request->get('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $info = $this->model->with(['file'])->findOrEmpty($id);
        if ($info->isEmpty()) {
            return warning('备份记录不存在');
        }

        return success('', $info);
    }

    /**
     * 创建系统备份
     */
    #[Post('/create')]
    #[Permission(title: '创建备份', slug: 'system:backup:create')]
    public function create(Request $request): Response
    {
        $record = BackupService::backupSystem();
        return success('备份创建成功', $record);
    }

    /**
     * 删除备份
     */
    #[Post('/delete')]
    #[Permission(title: '删除备份', slug: 'system:backup:delete')]
    public function delete(Request $request): Response
    {
        $id = $request->post('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $info = $this->model->findOrEmpty($id);
        if ($info->isEmpty()) {
            return warning('备份记录不存在');
        }
        if ((int)$info->backup_type !== Backup::TYPE_SYSTEM) {
            return warning('只能删除系统备份');
        }

        // 删除物理文件
        self::deleteBackupFile($info->file_id);

        $info->delete();
        return success('删除成功');
    }

    /**
     * 批量删除备份
     */
    #[Post('/batchDelete')]
    #[Permission(title: '批量删除', slug: 'system:backup:batchDelete')]
    public function batchDelete(Request $request): Response
    {
        $ids = $request->post('ids');
        if (empty($ids) || !is_array($ids)) {
            return warning('参数错误');
        }

        $list = $this->model->whereIn('id', $ids)
            ->where('backup_type', Backup::TYPE_SYSTEM)
            ->select();
        if ($list->count() !== count($ids)) {
            return warning('部分备份记录不存在');
        }

        $this->transaction(function () use ($list) {
            foreach ($list as $backup) {
                // 删除物理文件
                self::deleteBackupFile($backup->file_id);
                $backup->delete();
            }
        });

        return success('批量删除成功');
    }

    /**
     * 下载备份文件
     */
    #[Get('/download')]
    #[Permission(title: '下载备份', slug: 'system:backup:download')]
    public function download(Request $request): Response
    {
        $id = $request->get('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $backup = $this->model->findOrEmpty($id);
        if ($backup->isEmpty()) {
            return warning('备份记录不存在');
        }

        $file = File::findOrEmpty($backup->file_id);
        if ($file->isEmpty()) {
            return warning('备份文件不存在');
        }

        // 解析备份文件本地路径（UploadService 存储的路径格式为 /storage/backup/xxx.zip）
        $realPath = BackupService::resolveBackupLocalPath($file);
        if ($realPath === null) {
            return warning('备份文件路径异常');
        }
        if (!is_file($realPath)) {
            return warning('备份文件不在本地储存中或已被删除');
        }

        return response()->download($realPath, (string)$file->name)->withHeaders([
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'no-cache',
        ]);
    }

    /**
     * 恢复前兼容性检查
     */
    #[Post('/check')]
    #[Permission(title: '兼容性检查', slug: 'system:backup:check')]
    public function check(Request $request): Response
    {
        $id = $request->post('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $backup = $this->model->findOrEmpty($id);
        if ($backup->isEmpty()) {
            return warning('备份记录不存在');
        }
        if ((int)$backup->backup_type !== Backup::TYPE_SYSTEM) {
            return warning('该备份不是系统备份');
        }

        $manifest = $backup->manifest ?: [];
        // JSON 字段可能是 stdClass，转为数组
        if ($manifest instanceof \stdClass) {
            $manifest = (array)$manifest;
        }
        $result = BackupService::checkSystemCompatibility($manifest);
        return success('', $result);
    }

    /**
     * 执行恢复
     */
    #[Post('/restore')]
    #[Permission(title: '恢复系统', slug: 'system:backup:restore')]
    public function restore(Request $request): Response
    {
        $id = $request->post('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        // 外部上传的备份包是未经市场签名的任意内容，而恢复会覆盖 plugin/config/vendor/public、
        // 合并 .env 并导入 SQL，等于任意代码部署。这类恢复只允许超管执行；
        // 本机 create() 生成的备份仍按原有 system:backup:restore 授权走。
        if (!isSuperAdmin()) {
            $backup = Backup::findOrEmpty((int)$id);
            if (!$backup->isEmpty()) {
                $file = File::findOrEmpty((int)$backup->file_id);
                $source = $file->isEmpty() ? '' : (string)($file->extends['source'] ?? '');
                if ($source === 'system_backup_upload') {
                    return error('上传的备份包仅允许超级管理员恢复', 403);
                }
            }
        }

        $result = BackupService::restoreSystem((int)$id);
        return success($result['message'] ?? '恢复成功', $result);
    }

    /**
     * 上传备份文件
     */
    #[Post('/upload')]
    #[Permission(title: '上传备份', slug: 'system:backup:upload')]
    public function upload(Request $request): Response
    {
        $file = $request->file('file');
        if ($file) {
            if (!$file->isValid()) {
                return warning('请选择有效的备份文件');
            }
            if (strtolower($file->getUploadExtension()) !== 'zip') {
                return warning('仅支持 zip 备份文件');
            }

            // 存入 storage/backup/ 目录（备份文件不限制大小）
            $fileModel = UploadService::saveLocalFile($file->getPathname(), [
                'name' => $file->getUploadName() ?: 'system_backup.zip',
                'pid' => 0,
                'is_lock' => 1,
                'skip_size_check' => true,
                'dirname' => 'backup',
                'type_dir' => '', // 备份固定目录，不追加文件类型层
                'extends' => [
                    'source' => 'system_backup_upload',
                ],
            ]);

            // 解析备份清单
            $backupManifest = [];
            try {
                $tempDir = runtime_path() . '/system_backup_upload_' . uniqid();
                \app\utils\Directory::ensureDir($tempDir);
                FileService::extractZipSafely($file->getPathname(), $tempDir);
                $backupManifest = BackupService::readBackupManifest($tempDir);
                \app\utils\Directory::removeDir($tempDir);
            } catch (\Throwable $e) {
                // 解析失败，仍然保存记录
            }

            // 写入备份记录
            $record = new Backup();
            $record->save([
                'app_name' => 'system',
                'title' => $backupManifest['app_title'] ?? '上传的系统备份',
                'version' => $backupManifest['app_version'] ?? '',
                'backup_type' => Backup::TYPE_SYSTEM,
                'file_id' => $fileModel->id,
                'file_count' => 0,
                'database_summary' => null,
                'plugin_snapshot' => $backupManifest['plugin_list'] ?? [],
                'manifest' => $backupManifest ?: null,
                'status' => 1,
                'error_message' => '',
            ]);

            $extends = $fileModel->extends ?: [];
            $extends['backup_id'] = $record->id;
            $fileModel->save(['extends' => $extends]);

            return success('上传成功', $record);
        }

        return warning('请选择备份文件');
    }

    /**
     * 删除备份关联的物理文件和数据库记录
     */
    private static function deleteBackupFile(int $fileId): void
    {
        if (!$fileId) {
            return;
        }
        $file = File::findOrEmpty($fileId);
        if ($file->isEmpty()) {
            return;
        }
        // 删除磁盘文件（path 越界时只跳过物理删除，记录照常清理，避免脏数据在后台删不掉）
        $localPath = BackupService::resolveBackupLocalPath($file);
        if ($localPath !== null && is_file($localPath)) {
            @unlink($localPath);
        }
        // 删除数据库记录
        $file->delete();
    }
}
