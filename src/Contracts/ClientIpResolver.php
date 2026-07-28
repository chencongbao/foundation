<?php

namespace Chencongbao\Foundation\Contracts;

use Illuminate\Http\Request;
use Chencongbao\Foundation\DTOs\ClientIpInfo;

interface ClientIpResolver
{
    public function resolve(?Request $request = null): ClientIpInfo;
}
