<?php

namespace Main\Requests;

use Flytachi\Winter\K2\Http\Request\Validation\Digits;
use Flytachi\Winter\K2\Http\Request\Validation\Max;
use Flytachi\Winter\K2\Http\Request\Validation\NotBlank;
use Flytachi\Winter\K2\Http\Request\Validation\Positive;
use Flytachi\Winter\K2\Http\Request\Validation\Size;

class ListRequest
{
    public function __construct(
        #[Positive]
        public int $limit = 10,
        #[Positive]
        public int $page = 1,
        #[NotBlank]
        public ?string $search = null
    ) {
    }
}