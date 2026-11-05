<?php
/* file: curl请求类
Created by raingad<raingad@foxmail.com>
Date: 2021-02-22 */

namespace app\utils;

class Curl{

    /* 
    请求封装-curl_request
    $url -string 请求地址
    $method -string 请求方式，默认GET
    $headers -array 请求头，默认[]
    $bodys -array 请求体，默认[]
    $json -boolean 对请求体进行json_encode处理，默认false
    return $response 请求返回值
    */
    public static function curl_request($url, $method = 'GET', $headers = [], $bodys = [], $json=false)
    {
        if($json==false && !empty($bodys)){
            $bodys=json_encode($bodys);
        }
        // 创建连接
        $curl = curl_init($url);
        // 证书校验与下载链路同一条口径（File::verifyTls）：恒关 TLS 等于任何中间人都能改回包内容
        $verifyTls = File::verifyTls();
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, $verifyTls);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, $verifyTls ? 2 : 0);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_FAILONERROR, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, false);
        if($method === 'POST'){
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $bodys);
        }
        // 发送请求
        $response = curl_exec($curl);
        if($json && is_string($response)){
            $response=json_decode($response,true);
        }
        curl_close($curl);
        return $response;
    }

    /*
    get请求-curl_get
    $url -string 请求地址
    $json -boolean 对返回值进行json_decode处理，默认true进行处理成array
    */
    public static function curl_get($url,$json=true)
    {
        return self::curl_request($url, 'GET', array("Expect:"), [], $json);
    }

    /* 
    POST请求-curl_post
    $url -string 请求地址
    $params - json 请求参数
    $rj -boolean 对返回值进行json_decode处理，默认true进行处理成array
    $headers -string 请求头
    */
    public static function curl_post($url,$params,$rj=true,$headers=''){
        if(!$headers){
            $headers=array(
                "Content-Type:application/x-www-form-urlencoded",
            );
        }
        return self::curl_request($url, 'POST', $headers, $params, $rj);
    }

}