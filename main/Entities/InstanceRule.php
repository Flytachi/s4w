<?php

namespace Main\Entities;

use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Constraint\ForeignRepo;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Entity\Table;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Hybrid\UuidPk;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\BigInteger;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\Json;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\Timestamp;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\Uuid;
use Flytachi\Winter\K2\Ppa\Mapping\Constants\FKAction;
use Main\Repositories\InstanceRepository;

#[Table]
class InstanceRule
{
    #[UuidPk]
    public ?string $id = null;

    #[Uuid]
    #[ForeignRepo(
        InstanceRepository::class,
        FKAction::CASCADE,
        FKAction::CASCADE
    )]
    public string $instance_id;

    #[BigInteger]
    public int $max_file_size_bytes = 10485760; // 10MB

    #[Json]
    public array|string $allowed_mime_types = [];

    #[Json]
    public array|string $allowed_extensions = [];

    #[Json]
    public array|string $blocked_extensions = [];

    #[Timestamp]
    public string $created_at;

    #[Timestamp]
    public string $updated_at;
}
