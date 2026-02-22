<?php



use Dcat\Admin\Traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Factories\HasFactory;


trait ModelTraits
{
    use HasDateTimeFormatter, HasFactory;
}
