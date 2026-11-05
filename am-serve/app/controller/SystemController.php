<?php

namespace app\controller;

use support\Request;
use support\Response;
use app\service\UploadService;
use app\model\system\User;
use app\model\system\Dept;
use app\model\system\File;
use app\model\system\Storage;
use app\basic\BaseController;
use app\model\system\Config;
use app\cache\UserInfoCache;
use app\cache\UserMenuCache;
use app\cache\DictCache;
use app\utils\ClientInfo;
use support\annotation\route\Get;
use support\annotation\route\Post;
use support\annotation\route\RouteGroup;

#[RouteGroup('/pub/system')]
class SystemController extends BaseController
{
    protected array $noNeedLogin = ['systemInfo'];

    /**
     * 获取系统基础信息
     */
    #[Get('/systemInfo')]
    public function systemInfo():Response
    {
        return success('', [
            'website' => Config::getConfig('website')
        ]);
    }

    // 获取用户信息
    #[Post('/user')]
    public function user(Request $request):Response
    {
        $userData = UserInfoCache::getUserInfo((int) $this->userInfo['id']);
        if (!$userData) {
            return error('用户不存在', 401);
        }
        $userData = array_merge($this->userInfo, $userData);
        $lastLoginIp = (string) ($userData['last_login_ip'] ?? '');
        $userData['ip_location'] = ClientInfo::ipLocation($lastLoginIp);
        return success('', $userData);
    }

    /**
     * 获取菜单
     */
    #[Get('/menu')]
    public function menu(Request $request):Response
    {
        // 出口统一把图片图标（/app/{name}/logo.png）解析成带域名的绝对地址，前台直接渲染
        return success('', resolveMenuIcons(UserMenuCache::getUserMenu((int) $this->userInfo['id'])));
    }

    /**
     * 获取字典
     */
    #[Get('/dict')]
    public function dict(Request $request):Response
    {
        return success('', DictCache::getDictAll());
    }

    // 获取子部门以及成员
    #[Get('/departSub')]
    public function departSub(Request $request):Response
    {
        $pid=$request->input('pid', '');
        if($pid === ''){
            $data=[['id'=>0,'name'=>"顶级部门",'stype'=>'dept','label'=>"顶级部门",'pid'=>0,'value'=>0,'key'=>'dept_0','isLeaf'=>false]];
            return success('', $data);
        }
        $keywords=$request->input('keywords', '');
        $where=[['status','=',1]];
        if($keywords){
            $keywords='%'.escapeLike((string)$keywords).'%';
            $where[]=['name','like',$keywords];
        }else{
            $where[]=['pid','=',$pid];
        }
        $deptList=Dept::field(Dept::$treeField)->where($where)->select()->toArray();
        foreach($deptList as $k=>$v){
            $deptList[$k]['stype']='dept';
            $deptList[$k]['isLeaf']=true;
            $deptList[$k]['key']='dept_'.$v['id'];
            $depCount=Dept::where(['pid'=>$v['id']])->count();
            $userCount=User::where(['dept_id'=>$v['id']])->count();
            if($depCount>0 || $userCount>0){
                $deptList[$k]['isLeaf']=false;
            }
        }
        $map=[['status','=',1]];
        if($keywords){
            $map[]=['username|nickname','like',$keywords];
        }else{
            $map[]=['dept_id','=',$pid];
        }
        $userList=User::field('id, id as value,username,nickname as name')->where($map)->select()->toArray();
        foreach($userList as $k=>$v){
            $userList[$k]['stype']='user';
            $userList[$k]['key']='user_'.$v['id'];
            $userList[$k]['isLeaf']=true;
        }
        $data=array_merge($deptList,$userList);
        return success('', $data);
    }

    // 远程文件下载并上传
    #[Post('/uploadUrl')]
    public function uploadUrl(Request $request):Response
    {
        try {
            // 检查是否有文件上传
            $url = $request->input('url');
            if(!$url){
                return warning('请输入文件链接');
            }
            $path=explode('.',$url);
            $ext=strtolower(end($path));
            $config=Config::getConfig('upload');
            $allow=is_array($config['allow_ext']) ? $config['allow_ext'] : json_decode($config['allow_ext'],true);
            if(!in_array($ext,$allow)){
                return warning('文件链接格式错误');
            }
            // 服务端代取外部资源前先验地址：只允许公网 http(s)，拒绝直连内网 host 与解析到内网的域名。
            // 缺这一道时上面按「最后一个点」取扩展名，http://127.0.0.1:6379/?x.png 就能过白名单。
            // 返回值是已校验的公网 IP，交给下载侧固定连接目标，闭合 DNS 重绑定窗口。
            $pinnedIp = assertPublicUrl((string)$url);
            $pid = $request->input('pid', 0);
            $storageId = $request->input('storage_id', (int)$config['storage_id']);
            // 获取上传配置
            $storageConfig = (array)((new Storage())->getStorageConfig($storageId));

            if (!$storageConfig) {
                return warning('存储配置不存在');
            }
            $config = UploadService::getConfig($storageConfig['disk'], $storageConfig);
            if (!$config) {
                return warning('获取存储配置失败');
            }
            // 如果是图片文件需要复制一份备份到临时目录,并创建缩略图
            $tempPath = createTempFile($url, $ext,false, $pinnedIp);
            if(!$tempPath){
                return warning('文件下载失败');
            }
            $fileInfo=getFileInfo($tempPath);
            if($fileInfo['size']>((int)$config['limit_size'])*1024*1024){
                return warning('仅支持上传'.$config['limit_size'].'MB以下的文件');
            }
            $md5 = hash_file('md5', $tempPath);
            $mainFile = File::where(['md5' => $md5])->find();
            if($mainFile){
                $mainFile = $mainFile->toArray();
            }else{
                $mainFile=[];
                $mainFile['ext']=$ext;
                $mainFile['md5']=$md5;
            }
            $mainFile['pid']=$pid;
            $mainFile['storage_id']=$storageId;
            $fileModel = UploadService::uploadFile($mainFile, $config, $tempPath, true);
            return success('', $fileModel->toArray());
        } catch (\Exception $e) {
            return error($e->getMessage().'，'.$e->getLine());
        }
    }

    // 上传文件
    #[Post('/upload')]
    public function upload(Request $request):Response
    {
        try {
            // 检查是否有文件上传
            $file = $request->file('file');
            if (!$file) {
                return warning('请选择要上传的文件');
            }

            // 检查文件是否有效
            if (!$file->isValid()) {
                return warning('上传文件无效');
            }

            $config=Config::getConfig('upload');
            $allow=is_array($config['allow_ext']) ? $config['allow_ext'] : json_decode($config['allow_ext'],true);
            $ext=strtolower($file->getUploadExtension());
            if(!in_array($ext,$allow)){
                return warning('文件格式错误');
            }
            if($file->getSize()>((int)$config['limit_size'])*1024*1024){
                return warning('仅支持上传'.$config['limit_size'].'MB以下的文件');
            }
            $md5 = hash_file('md5', $file->getPathname());
            $pid = $request->input('pid', 0);
            $storageId = $request->input('storage_id', (int)$config['storage_id']);
            $mainFile = File::where(['md5' => $md5])->find();

            // 获取上传配置
            $storageConfig = (array)((new Storage())->getStorageConfig($storageId));

            if (!$storageConfig) {
                return warning('存储配置不存在');
            }

            $config = UploadService::getConfig($storageConfig['disk'], $storageConfig);
            if (!$config) {
                return warning('获取存储配置失败');
            }
            $tempPath='';
            if($mainFile){
                $mainFile = $mainFile->toArray();
            }else{
                $mainFile['ext']=$ext;
                $mainFile['md5']=$md5;
                $isScaleImage=in_array($ext,['jpg','jpeg','png','gif']);
                
                // 如果是图片文件需要复制一份备份到临时目录,并创建缩略图
                if($isScaleImage){
                    $tempPath = createTempFile($file, $ext);
                }
            }
            $mainFile['pid']=$pid;
            $mainFile['storage_id']=$storageId;
            $fileModel = UploadService::uploadFile($mainFile, $config, $tempPath);
            return success('', $fileModel->toArray());
        } catch (\Exception $e) {
            return error($e->getMessage().'，'.$e->getLine());
        }
    }

}
