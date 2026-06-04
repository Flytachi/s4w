<?php

namespace Main\Requests\File;

use Flytachi\Winter\K2\Http\Request\Validation\Max;
use Flytachi\Winter\K2\Http\Request\Validation\Min;
use Flytachi\Winter\K2\Http\Request\Validation\Regex;

class FileRequest
{
    public function __construct(
        #[Max(100)]
        public ?string $name = null,

        #[Regex('/^[A-Za-z0-9][A-Za-z0-9_-]{0,99}$/')]
        public ?string $section = null,

        #[Min(0), Max(100)]
        public ?int $compress = null,

        public bool $webp = false,
    ) {
    }
}