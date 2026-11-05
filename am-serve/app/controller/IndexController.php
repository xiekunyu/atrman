<?php

namespace app\controller;

use app\model\system\Config;
use support\annotation\route\Get;
use support\Request;

class IndexController
{
    protected $noNeedLogin = ['index'];

    #[Get('/')]
    public function index(Request $request)
    {
        // 配置了自定义后台入口时，/ 不再暴露入口位置
        $adminEntry = adminEntryPath();
        $hiddenEntry = $adminEntry !== '/index.html';

        // 网站配置「前端入口」：访问站点根域名时默认进入的地址（应用前台 / 后台 / 官网外链）
        $frontEntry = trim((string) (Config::getConfig('website')['front_entry'] ?? ''));
        // 拒绝空值、自跳环（/）、协议相对外域（//host）；启用随机安全入口时不许填它，否则等于从公开根路径泄露入口
        if ($frontEntry !== '' && $frontEntry !== '/' && !str_starts_with($frontEntry, '//')
            && (!$hiddenEntry || !preg_match('~^' . preg_quote($adminEntry, '~') . '([?#]|$)~', $frontEntry))) {
            return redirect($frontEntry);
        }

        if ($hiddenEntry) {
            return response('<h1>404 Not Found</h1>', 404);
        }
        return redirect($adminEntry);
    }

    public function view(Request $request)
    {
        return view('index/view', ['name' => 'webman']);
    }

    public function json(Request $request)
    {
        return json(['code' => 0, 'msg' => 'ok']);
    }

}
