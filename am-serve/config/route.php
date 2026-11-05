<?php
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

use Webman\Route;

// 所有业务路由已由控制器注解注册（去除了所有 fastRoute 和手写 Route）
// 插件应用路由由各插件 route.php 自行注册

// 禁用主项目默认路由，不影响应用插件
Route::disableDefaultRoute();