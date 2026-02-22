<?php
if (!function_exists('bob_admin_setting')) {
    function bob_admin_setting($name,$value = "")
    {
        return App::make(CacheAdminSettingService::class)->excute($name,$value);
    }
}
