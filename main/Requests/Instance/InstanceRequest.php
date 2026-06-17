<?php

namespace Main\Requests\Instance;

use Flytachi\Winter\K2\Http\Request\Validation\Digits;
use Flytachi\Winter\K2\Http\Request\Validation\Max;
use Flytachi\Winter\K2\Http\Request\Validation\NotBlank;
use Flytachi\Winter\K2\Http\Request\Validation\Positive;

class InstanceRequest
{
    public function __construct(
        #[NotBlank, Max(100)]
        public string $name,

        #[Max(200)]
        public string $description = '',

        #[Digits(12, 0), Positive]
        public int $quotaBytes = 104857600 // 100MB
    ) {
    }
}