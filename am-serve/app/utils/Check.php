<?php
/* file:常用验证函数封装的集合
Created by raingad<raingad@foxmail.com>
Date: 2021-02-25 */

namespace app\utils;

class Check{

    /* 
    判断是否是手机号
    $phone -string
    return boolean
    */
    public static function check_phone($phone)
    {
        return Regular::is_phonenumber($phone);
    }

    /* 
    判断是否是邮箱
    $email -string
    return boolean
    */
    public static function check_email($email)
    {
        return Regular::is_email($email);
    }

    /* 
    判断是否是一个IP
    $str -string
    return 
    */
    public static function check_ip($str)
    {
        return filter_var($str, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }
}
