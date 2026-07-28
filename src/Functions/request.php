<?php

if (!function_exists('bob_ip')) {
    function bob_ip(): ?string
    {
        return request()->ip();
    }
}
