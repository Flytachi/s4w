<?php

namespace Main\Dto;

enum InstanceStatus: int
{
    case CREATED = 0;
    case PENDING = 1;
    case INACTIVE = 2;
    case ACTIVE = 3;

    public function toArray(): array
    {
        return [
            'id' => $this->value,
            'name' => $this->name,
        ];
    }
}
