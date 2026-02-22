<?php
namespace Chencongbao\Foundation\Traits;


trait ServiceTraits
{
    public $model;

    public $data = [];

    abstract function excute();
}
