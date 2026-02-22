<?php

namespace Bob\Foundation\Services\Config;

use Bob\Foundation\Services\Const\CacheConstPrefixService;
use Illuminate\Support\Facades\Cache;

class CacheAdminSettingService
{

    public function excute($name,$value)
    {
        $key = CacheConstPrefixService::ADMIN_SETTING;
        if(empty($name)){
            if(Cache::has($key)){
                return Cache::get($key);
            }
            return $this->update($key);
        }
        if(is_array($name)){
            foreach($name as $k=>$v){
                admin_setting([$k=>$v]);
            }
            $this->update($key);
            return true;
        }

        if(is_string($name)){
            if(!empty($value)){
                admin_setting([$name=>$value]);
                $this->update($key);
                return true;
            }
            if(Cache::has($key)){
                $result = Cache::get($key);
            }else{
                $result = $this->update($key);
            }
            return $result[$name] ?? null;
        }
        return;
    }

    public function update($key){
        $result = [];
        if(Cache::has($key)){
            Cache::forget($key);
        }
        $row = AdminSetting::get()->pluck('value','slug');
        if(!empty($row)){
            $result = $row->toArray();
            Cache::forever($key,$row);
        }
        return $result;
    }
}
