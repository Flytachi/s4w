<?php

namespace Main\Dto;

use Main\Entities\Instance;

class InstanceRes
{
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        public array $bytes,
        public array $status,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }

    public static function from(Instance $model): self
    {
        return new self(
            $model->id,
            $model->name,
            $model->description,
            [
                'quota' => $model->quota_bytes,
                'used' => $model->used_bytes,
            ],
            InstanceStatus::from($model->status)->toArray(),
            $model->created_at,
            $model->updated_at,
        );
    }
}