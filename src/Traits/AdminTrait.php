<?php
namespace Bob\Foundation\Traits;

use Dcat\Admin\Http\Auth\Permission;
use Dcat\Admin\Layout\Content;

trait AdminTrait
{

    public function destroy($id)
    {
        Permission::error();
    }

    public function show($id, Content $content)
    {
        Permission::error();
    }
}
