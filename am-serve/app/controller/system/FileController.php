<?php

namespace app\controller\system;

use app\basic\BaseController;
use app\model\system\File;
use support\annotation\route\Get;
use support\annotation\route\Post;
use support\annotation\route\RouteGroup;
use support\Request;
use support\Response;
use app\service\Permission;

#[RouteGroup('/sys/file')]
class FileController extends BaseController
{
    public function initialize(): void
    {
        $this->model = new File();
    }
    
    /**
     * 文件列表
     */
    #[Get('/index')]
    #[Permission(title: '文件列表', slug: 'system:file:index')]
    public function index(Request $request): Response
    {
        $where = $request->buildMap([
            ['name', ''],
            ['type', ''],
            ['pid', ''],
            ['file_type', ''],
            ['storage_id', ''],
            ['keywords', ''],
        ]);
        $model=$this->search($this->model,$where);
        if ($request->input('tree', 'false') === 'true') {
            $list=$model->field('id,name,storage_id,pid,type')->where(['type'=>1])->select()->toArray();
            $data=\app\utils\Tree::createListToTree($list,'id','pid','children');
            $data=[['id'=>0,'name'=>"根目录",'storage_id'=>0,'pid'=>0,'children'=>$data]];
            return success('',$data,count($list));
        }
        $list = $this->getList($model->alias('file')->where(['file.type'=>2])->with(['storage'=>function($query) {
            $query->field('name');
        }]));
        return $this->returnListData($list);
    }

    /**
     * 文件详情
     */
    #[Get('/read')]
    #[Permission(title: '文件详情', slug: 'system:file:read')]
    public function read(Request $request): Response
    {
        $id = $request->get('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $info = $this->model->with(['user' => function($query) {
            $query->field('id, nickname');
        },
        'parent' => function($query) {
            $query->field('id, name');
        },'storage'=>function($query) {
            $query->field('id, name');
        }])->find($id);
        if (!$info) {
            return warning('文件不存在');
        }

        return success('', $info);
    }

    /**
     * 创建文件/目录
     */
    #[Post('/save')]
    #[Permission(title: '创建文件', slug: 'system:file:save')]
    public function save(Request $request): Response
    {
        $data = $request->post();
        if (isset($data['id'])) {
            unset($data['id']);
        }

        // 创建目录
        $info = $this->model->save($data);
        if (!$info) {
            return warning('创建失败');
        }

        return success('', $info);
    }

    /**
     * 更新文件/目录
     */
    #[Post('/update')]
    #[Permission(title: '更新文件', slug: 'system:file:update')]
    public function update(Request $request): Response
    {
        $id = $request->post('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $info = $this->model->findOrEmpty($id);
        if (!$info) {
            return warning('目录不存在');
        }

        $data = $request->post();

        // 检查是否为自己的子文件/目录（避免循环）
        if (isset($data['pid']) && $data['pid']) {
            $children = $info->children()->column('id');
            if (in_array($data['pid'], $children)) {
                return warning('父级不能是当前目录的子级');
            }
        }

        // 更新目录
        $result = $info->save($data);
        if (!$result) {
            return warning('更新失败');
        }

        return success('更新成功', $info);
    }

    /**
     * 删除文件/目录
     */
    #[Post('/delete')]
    #[Permission(title: '删除文件', slug: 'system:file:delete')]
    public function delete(Request $request): Response
    {
        $id = $request->post('id');
        if (empty($id)) {
            return warning('参数错误');
        }

        $info = $this->model->findOrEmpty($id);
        if (!$info) {
            return warning('目录不存在');
        }
        if ((int)$info->is_lock === 1) {
            return warning('文件已锁定，无法删除');
        }

        // 检查是否有子文件/目录
        if ($info->children()->count() > 0) {
            return warning('该目录下还有子文件/目录，无法删除');
        }

        // 删除目录
        $result = $info->delete();
        if (!$result) {
            return warning('删除失败');
        }

        return success('删除成功');
    }

    /**
     * 批量删除文件/目录
     */
    #[Post('/batchDelete')]
    #[Permission(title: '批量删除文件', slug: 'system:file:batchDelete')]
    public function batchDelete(Request $request): Response
    {
        $ids = $request->post('ids');
        if (empty($ids)) {
            return warning('参数错误');
        }

        $lockedCount = $this->model->whereIn('id', $ids)->where('is_lock', 1)->count();
        if ($lockedCount > 0) {
            return warning('包含已锁定文件，无法删除');
        }

        // 检查是否有子文件/目录
        $hasChildren = $this->model->whereIn('pid', $ids)->count() > 0;
        if ($hasChildren) {
            return warning('部分目录下还有子文件/目录，无法删除');
        }

        // 批量删除文件
        $this->transaction(function () use ($ids) {
            $this->model->destroy($ids);
        });

        return success('删除文件成功');
    }

    /**
     * 切换文件状态
     */
    #[Post('/changeStatus')]
    #[Permission(title: '切换文件状态', slug: 'system:file:changeStatus')]
    public function changeStatus(Request $request): Response
    {
        $id = $request->post('id');
        $status = $request->post('status');
        if (empty($id) || '' === $status) {
            return warning('参数错误');
        }

        $info = $this->model->findOrEmpty($id);
        if (!$info) {
            return warning('目录不存在');
        }
        $info->status = $status;
        $result = $info->save();
        if (!$result) {
            return warning('切换状态失败');
        }

        return success('切换状态成功');
    }

    /**
     * 获取文件树
     */
    #[Get('/getFileTree')]
    #[Permission(title: '获取文件树', slug: 'system:file:getFileTree')]
    public function getFileTree(Request $request): Response
    {
        $pid = $request->input('pid', 0);
        $files = $this->model->where(['status' => 1, 'pid' => $pid])->order('type asc, create_time desc')->select()->toArray();
        
        foreach ($files as &$file) {
            $file['hasChildren'] = $this->model->where('pid', $file['id'])->count() > 0;
        }
        
        return success('获取文件树成功', $files);
    }

    /**
     * 锁定/解锁文件
     */
    #[Post('/lock')]
    #[Permission(title: '锁定文件', slug: 'system:file:lock')]
    public function lock(Request $request): Response
    {
        $id = $request->post('id');
        $isLock = $request->post('is_lock');
        if (empty($id) || '' === $isLock) {
            return warning('参数错误');
        }

        $file = $this->model->findOrEmpty($id);
        if (!$file) {
            return warning('文件不存在');
        }

        // 切换锁定状态
        $file->is_lock = $isLock;
        $result = $file->save();
        if (!$result) {
            return warning('操作失败');
        }

        return success('操作成功');
    }

    /**
     * 检查文件MD5是否存在（秒传接口）
     * @param Request $request
     * @return Response
     */
    #[Post('/checkMd5')]
    #[Permission(title: '检查文件MD5', slug: 'system:file:checkMd5')]
    public function checkMd5(Request $request): Response
    {
        $md5 = $request->post('md5');
        $storageId = $request->post('storage_id', 1);
        $pid = $request->post('pid', 0);
        if (empty($md5)) {
            return warning('MD5值不能为空');
        }

        // 查询是否已存在相同MD5的文件
        $existingFile = $this->model->where('md5', $md5)->where('status', 1)->find();
        
        if ($existingFile) {
            $fileData = [
                'name' => $existingFile->name,
                'path' => $existingFile->path,
                'url' => $existingFile->url,
                'file_type' => $existingFile->file_type,
                'size' => $existingFile->size,
                'ext' => $existingFile->ext,
                'type' => $existingFile->type,
                'pid' => $pid,
                'storage_id' => $storageId,
                'md5' => $existingFile->md5,
            ];
            $this->model->save($fileData);
            // 秒传也会新建一条记录，把主键一并返回：前端选择器要在「上传成功」的列表里直接点选它
            $fileData['id'] = (int)$this->model->getKey();
            $fileData['is_existed'] = true;
            // 文件已存在，返回秒传成功信息
            return success('', $fileData);
        }

        // 文件不存在，需要上传
        return success('', [
            'is_existed' => false,
        ]);
    }

    /**
     * 合并分片文件
     * @param Request $request
     * @return Response
     */
    #[Post('/merge')]
    #[Permission(title: '合并文件', slug: 'system:file:merge')]
    public function merge(Request $request): Response
    {
        $md5 = $request->post('md5');
        $filename = $request->post('filename');
        $fileType = $request->post('file_type', 2); // 默认为文件类型
        $pid = $request->post('pid', 0);
        $storageId = $request->post('storage_id', 1);
        
        if (empty($md5) || empty($filename)) {
            return warning('参数错误');
        }

        // 获取文件扩展名
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        
        // 获取存储配置
        $uploadConfig = config('plugin.tinywan.storage.app');
        $disk = $storageId == 1 ? 'local' : 'oss';
        $diskConfig = $uploadConfig[$disk] ?? $uploadConfig['local'];
        
        // 构建最终文件路径
        $savePath = date('Ymd') . '/' . $md5 . '.' . $ext;
        $fullPath = ($diskConfig['root'] ?? runtime_path()) . 'uploads/' . $savePath;
        $domain = $diskConfig['domain'] ?? request()->host();
        $url = rtrim($domain, '/') . '/uploads/' . $savePath;

        // 检查文件是否存在
        if (!file_exists($fullPath)) {
            return warning('文件不存在或已过期');
        }

        // 获取文件大小
        $fileSize = filesize($fullPath);

        // 保存文件记录
        $fileData = [
            'name' => $filename,
            'path' => '/uploads/' . $savePath,
            'url' => $url,
            'ext' => $ext,
            'size' => $fileSize,
            'md5' => $md5,
            'type' => $fileType,
            'pid' => $pid,
            'storage_id' => $storageId,
            'status' => 1,
            'mime_type' => $this->getMimeType($ext),
        ];

        $info = $this->model->create($fileData);
        
        if (!$info) {
            return warning('文件记录保存失败');
        }

        return success('文件上传成功', [
            'id' => $info->id,
            'url' => $url,
            'path' => $fileData['path'],
        ]);
    }

    /**
     * 获取MIME类型
     */
    private function getMimeType(string $ext): string
    {
        $mimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'bmp' => 'image/bmp',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'zip' => 'application/zip',
            'rar' => 'application/x-rar-compressed',
            '7z' => 'application/x-7z-compressed',
            'tar' => 'application/x-tar',
            'gz' => 'application/gzip',
            'txt' => 'text/plain',
            'html' => 'text/html',
            'css' => 'text/css',
            'js' => 'application/javascript',
            'json' => 'application/json',
            'xml' => 'text/xml',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            'mp4' => 'video/mp4',
            'avi' => 'video/x-msvideo',
            'mov' => 'video/quicktime',
            'wmv' => 'video/x-ms-wmv',
            'flv' => 'video/x-flv',
        ];

        return $mimeTypes[strtolower($ext)] ?? 'application/octet-stream';
    }
}
