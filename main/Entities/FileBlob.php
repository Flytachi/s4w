<?php

namespace Main\Entities;

use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Constraint\ForeignRepo;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Entity\Table;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Hybrid\UuidPk;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Idx\Unique;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\BigInteger;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\Char;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\Timestamp;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\Uuid;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\Varchar;
use Flytachi\Winter\K2\Ppa\Mapping\Constants\FKAction;
use Main\Repositories\InstanceRepository;

#[Table]
class FileBlob
{
    #[UuidPk]
    public ?string $id = null;

    #[Uuid]
    #[ForeignRepo(
        InstanceRepository::class,
        FKAction::CASCADE,
        FKAction::CASCADE
    )]
    #[Unique(['hash'])]
    public string $instance_id;

    #[Char(64)]
    public string $hash;

    #[BigInteger]
    public int $size_bytes;

    #[Varchar(127)]
    public string $mime_type;

    #[Varchar(32)]
    public string $extension;

    #[BigInteger]
    public int $ref_count = 0;

    #[Timestamp]
    public string $created_at;
}
