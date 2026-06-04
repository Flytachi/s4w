<?php

namespace Main\Requests\Instance;

use Flytachi\Winter\K2\Http\Request\Validation\Max;
use Flytachi\Winter\K2\Http\Request\Validation\NotBlank;

class TokenRequest
{
    public function __construct(
        #[NotBlank, Max(70)]
        public string $name,
    ) {
    }
}