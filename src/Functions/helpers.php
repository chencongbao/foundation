<?php

use Chencongbao\Foundation\Services\Config\CacheAdminSettingService;
use Illuminate\Support\Facades\App;

if (!function_exists('bob_admin_setting')) {
    function bob_admin_setting($name,$value = "")
    {
        return App::make(CacheAdminSettingService::class)->excute($name,$value);
    }
}

if (!function_exists('bob_ip')) {
    function bob_ip($debug = false)
    {
        $data = [];
        $ip = Request::ip();
        $data['ip'] = $ip;
        if (isset($_SERVER["HTTP_ALI_CDN_REAL_IP"]) && !empty($_SERVER["HTTP_ALI_CDN_REAL_IP"])) {
            $ip = $_SERVER["HTTP_ALI_CDN_REAL_IP"];
            $data['HTTP_ALI_CDN_REAL_IP'] = $ip;
        }
        if (isset($_SERVER["HTTP_CF_CONNECTING_IP"]) && !empty($_SERVER["HTTP_CF_CONNECTING_IP"])) {
            $ip = $_SERVER["HTTP_CF_CONNECTING_IP"];
            $data['HTTP_CF_CONNECTING_IP'] = $ip;
        }
        if (isset($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
            $data['HTTP_CLIENT_IP'] = $ip;
        }
        return $ip;
    }
}
