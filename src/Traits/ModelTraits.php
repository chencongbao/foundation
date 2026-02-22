<?php
namespace Bob\Foundation\Traits;


use Dcat\Admin\Traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Factories\HasFactory;


trait ModelTraits
{
    use HasDateTimeFormatter, HasFactory;
}
