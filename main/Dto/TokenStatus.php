<?php

namespace Main\Dto;

enum TokenStatus: int
{
    case INACTIVE = 0;
    case ACTIVE = 1;

    public function toArray(): array
    {
        return [
            'id' => $this->value,
            'name' => $this->name,
        ];
    }
}
