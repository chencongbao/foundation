<?php
namespace Bob\Foundation\Traits;


trait ServiceTraits
{
    public $model;

    public $data = [];

    abstract function excute();
}
