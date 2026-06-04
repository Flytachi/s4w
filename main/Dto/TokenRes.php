<?php

namespace Main\Dto;

use Main\Entities\Instance;
use Main\Entities\InstanceToken;

class TokenRes
{
    public function __construct(
        public string $id,
        public string $name,
        public array $status,
        public string $createdAt,
    ) {
    }

    public static function from(InstanceToken $model): self
    {
        return new self(
            $model->id,
            $model->name,
            TokenStatus::from($model->status)->toArray(),
            $model->created_at,
        );
    }
}