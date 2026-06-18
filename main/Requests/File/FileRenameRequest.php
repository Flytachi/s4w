<?php

namespace Main\Requests\File;

use Flytachi\Winter\K2\Http\Request\Validation\Max;
use Flytachi\Winter\K2\Http\Request\Validation\NotBlank;

class FileRenameRequest
{
    public function __construct(
        #[NotBlank, Max(255)]
        public string $name,
    ) {
    }
}
