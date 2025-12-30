<?php

namespace OsarisUk\Cart\Helpers;

use Illuminate\Support\Facades\Auth;

class GetUserId
{
    public function __invoke()
    {
        return Auth::id();
    }
}