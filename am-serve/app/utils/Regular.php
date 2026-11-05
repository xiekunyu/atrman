<?php
/* 正则集合
Created by raingad<raingad@foxmail.com>
Date: 2021-02-24 */

namespace app\utils;

class Regular{

    /** 参与正则匹配的输入长度上限：校验用的都是短字段，超长直接判非法 */
    private const MAX_MATCH_LENGTH = 254;

    /**
     * 判断是否示手机号
     */
    public static function is_phonenumber($str){
        if (!self::withinLimit($str)) {
            return false;
        }
        $preg ="/^1[3456789]\d{9}$/";
        return preg_match($preg,$str) ? true : false;
    }

    //判断是否是邮箱
    public static function is_email($str){
        if (!self::withinLimit($str)) {
            return false;
        }
        // 原式 `([a-zA-Z0-9]+[-.])+` 与本地部分的 `(A+[-_.]A+)*` 都是嵌套量词，
        // 对「一串同类字符 + 结尾不匹配」的输入会指数级回溯（ReDoS）。
        // 改成纯字符类的线性形式，语义等价于「两段字母数字 . _ -，域名以 2~5 位字母结尾」。
        // 同时去掉 m 修饰符：带 m 时 `^...$` 会匹配多行字符串中的任意一行，
        // "a@b.com\n任何内容" 也能通过校验，邮箱里可塞换行。
        $preg='/^[a-zA-Z0-9][-_.a-zA-Z0-9]*@[a-zA-Z0-9][-_.a-zA-Z0-9]*\.[a-zA-Z]{2,5}$/i';
        return preg_match($preg,$str) ? true : false;
    }

    /** 输入须为字符串且不超过上限（0=不限，用于整串扫描类方法） */
    private static function withinLimit($str, int $max = self::MAX_MATCH_LENGTH): bool
    {
        return is_string($str) && ($max === 0 || strlen($str) <= $max);
    }

    // 判断手机号或者邮箱,1手机号，2邮箱，0不是
    public static function check_account($str){
        if(self::is_phonenumber($str)){
            return 1;
        }
        if(self::is_email($str)){
            return 2;
        }
        return 0;
    }

    //判断是否是网址
    public static function is_url($str){
        if (!self::withinLimit($str, 2048)) {
            return false;
        }
        // 原式 `([\w\-]+(\.[\w\-]+)*\/)*` 同样是嵌套量词，改写成「协议 + 主机 + 可选路径 + 可选 query」的线性形式；
        // 匹配范围与原式一致（仍是前缀匹配，不锚定结尾）。
        $preg='/^((ht|f)tps?):\/\/[\w\-]+(\.[\w\-]+)*(\/[\w\-\.]*)*(\?[\w\-\.,@?^=%&:\/~\+#]*)?/i';
        return preg_match($preg,$str) ? true : false;
    }

    /**
     * 摘取手机号
     * @param string $oldStr
     * @return array
     */
    public static function findThePhoneNumbers($oldStr = "",$onlyone=true){
        // 整串扫描类方法不限制长度（上限传 0），只要求必须是字符串
        if (!self::withinLimit($oldStr, 0)) {
            return false;
        }
        // 检测字符串是否为空
        $oldStr=trim("q{$oldStr}q");
        if(empty($oldStr)){
            return false;
        }
        $strArr = explode("-", $oldStr);
        $newStr = $strArr[0];
        for ($i=1; $i < count($strArr); $i++) {
            if (preg_match("/\d{2}$/", $newStr) && preg_match("/^\d{11}/", $strArr[$i])){
                $newStr .= $strArr[$i];
            } elseif (preg_match("/\d{3,4}$/", $newStr) && preg_match("/^\d{7,8}/", $strArr[$i])) {
                $newStr .= $strArr[$i];
            } else {
                $newStr .= "-".$strArr[$i];
            }
        }
        // 手机号的获取
        $reg='/\D(?:86)?(\d{11})\D/is';//匹配数字的正则表达式
        preg_match_all($reg,$newStr,$result);
		
        $nums = array();
        $common = '/^1[3-9]\d{9}$/';
        foreach ($result[1] as $key => $value) {
            if(preg_match($common,$value)){
                $nums[] = $value;
            }
        }
		if(count($nums)>0){
			return $onlyone ? $nums[0] : $nums;
		}else{
			return false;
		}
    }

    // 验证身份证号码
    public static function is_idcard($str){
        if (!self::withinLimit($str, 32)) {
            return false;
        }
        $reg = '/^(\d{6})(\d{4})(\d{2})(\d{2})(\d{3})([0-9]|X)$/i';
        return preg_match($reg,$str) ? true : false;
    }
}