<?php

namespace App\Handlers;

class ExitHandler extends BaseHandler
{
    /**
     * @param  array<int, string>  $tokens
     */
    public function handle(array $tokens): string
    {
        return '__EXIT__';
    }
}
