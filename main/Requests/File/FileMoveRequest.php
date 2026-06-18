<?php

namespace Main\Requests\File;

use Flytachi\Winter\K2\Http\Request\Validation\Regex;

class FileMoveRequest
{
    public function __construct(
        // null = корень инстанса; иначе имя существующей/новой секции.
        #[Regex('/^[A-Za-z0-9][A-Za-z0-9_-]{0,99}$/')]
        public ?string $section = null,
    ) {
    }
}
