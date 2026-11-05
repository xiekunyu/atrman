<?php

namespace app\middleware;

use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

class ValidateCheck implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        if (strtoupper($request->method()) === 'OPTIONS') {
            return $handler($request);
        }

        if (empty($request->controller) || empty($request->action)) {
            return $handler($request);
        }

        $validateClass = $this->resolveValidateClass($request->controller);
        if ($validateClass === null) {
            return $handler($request);
        }

        $validate = new $validateClass();
        $scene = $this->resolveScene($request);
        if (!$scene || !$validate->hasScene($scene)) {
            return $handler($request);
        }

        if (!$validate->scene($scene)->check($this->getValidateData($request))) {
            return warning($validate->getError());
        }

        return $handler($request);
    }

    /**
     * 获取验证类名
     */
    private function resolveValidateClass(string $controller): ?string
    {
        if (!str_ends_with($controller, 'Controller')) {
            return null;
        }

        $validateClass = str_replace('\\controller\\', '\\validate\\', $controller);
        $validateClass = preg_replace('/Controller$/', 'Validate', $validateClass);

        return class_exists($validateClass) ? $validateClass : null;
    }

    /**
     * 特殊场景
     */
    private function resolveScene(Request $request): ?string
    {
        if ($request->controller === 'app\\controller\\system\\DictController' && $request->action === 'save') {
            return (int)$request->post('pid', 0) === 0 ? 'save' : 'saveItem';
        }

        return $request->action;
    }

    /**
     * 获取验证数据
     */
    private function getValidateData(Request $request): array
    {
        return array_merge($request->get(), $request->post());
    }
}
