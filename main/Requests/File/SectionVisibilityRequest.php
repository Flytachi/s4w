<?php

namespace Main\Requests\File;

use Flytachi\Winter\K2\Http\Request\Validation\NotBlank;
use Flytachi\Winter\K2\Http\Request\Validation\Regex;

class SectionVisibilityRequest
{
    public function __construct(
        #[NotBlank, Regex('/^[A-Za-z0-9][A-Za-z0-9_-]{0,99}$/')]
        public string $section,

        public bool $public = false,
    ) {
    }
}
