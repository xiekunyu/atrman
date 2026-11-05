<?php
// +----------------------------------------------------------------------
// | atrman企业快速开发框架
// +----------------------------------------------------------------------
// | Author: raingad <raingad@foxmail.com>
// +----------------------------------------------------------------------
namespace app\exception;

use Webman\Http\Request;
use Webman\Http\Response;
use support\exception\BusinessException;

/**
 * 常规操作异常-只返回json数据,不记录异常日志
 */
class ApiException extends BusinessException
{
    public function render(Request $request): ?Response
    {
        return warning($this->getMessage(), '',0,1,$this->getCode() ?: 500);
    }
}