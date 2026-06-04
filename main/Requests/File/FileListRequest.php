<?php

namespace Main\Requests\File;

use Flytachi\Winter\K2\Http\Request\Validation\NotBlank;
use Flytachi\Winter\K2\Http\Request\Validation\Positive;
use Flytachi\Winter\K2\Http\Request\Validation\Regex;

class FileListRequest
{
    public function __construct(
        #[Positive]
        public int $limit = 10,

        #[Positive]
        public int $page = 1,

        #[Regex('/^[A-Za-z0-9][A-Za-z0-9_-]{0,99}$/')]
        public ?string $section = null,

        #[NotBlank]
        public ?string $search = null
    ) {
    }
}
