<?php

namespace Main\Requests;

use Flytachi\Winter\K2\Http\Request\Validation\NotBlank;

class LoginRequest
{
    public function __construct(
        #[NotBlank]
        public string $username,
        #[NotBlank]
        public string $password,
    ) {
    }
}
