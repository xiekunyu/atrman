<?php

namespace app\service;

class TemplateEngine
{
    /**
     * 渲染模板内容，替换 {{变量名}} 占位符
     *
     * @param string $content 模板内容
     * @param array  $variables 变量值 ['变量名' => '值']
     * @param array  $systemVars 系统内置变量（自动注入）
     * @return string 渲染后的内容
     */
    public static function render(string $content, array $variables = [], array $systemVars = []): string
    {
        // 合并系统内置变量（优先级低于用户变量）
        $allVars = array_merge($systemVars, $variables);

        // 替换 {{变量名}} 格式
        return preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', function ($matches) use ($allVars) {
            $key = $matches[1];
            return $allVars[$key] ?? $matches[0]; // 未匹配的变量保持原样
        }, $content);
    }

    /**
     * 从模板内容中提取所有变量名
     *
     * @param string $content 模板内容
     * @return array 变量名数组
     */
    public static function extractVariables(string $content): array
    {
        preg_match_all('/\{\{\s*(\w+)\s*\}\}/', $content, $matches);
        return array_unique($matches[1] ?? []);
    }

    /**
     * 获取系统内置变量
     *
     * @return array ['变量名' => '值']
     */
    public static function getSystemVariables(array $extra = []): array
    {
        $vars = [
            'system_name' => config('app.name', 'Atrman'),
            'current_time' => date('Y-m-d H:i:s'),
        ];

        return array_merge($vars, $extra);
    }
}
