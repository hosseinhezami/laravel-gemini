<?php

if (! function_exists('safe_base64_encode')) {

    function safe_base64_encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
