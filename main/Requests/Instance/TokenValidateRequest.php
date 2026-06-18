<?php

namespace Main\Requests\Instance;

use Flytachi\Winter\K2\Http\Request\Validation\NotBlank;

class TokenValidateRequest
{
    public function __construct(
        #[NotBlank]
        public string $token,
    ) {
    }
}
