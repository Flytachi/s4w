<?php

namespace Main\Entities;

use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Constraint\CheckEnum;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Constraint\ForeignRepo;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Entity\Table;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Hybrid\UuidPk;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Idx\Unique;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\Char;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\SmallInteger;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\Timestamp;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\Uuid;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\Varchar;
use Flytachi\Winter\K2\Ppa\Mapping\Constants\FKAction;
use Main\Dto\TokenStatus;
use Main\Repositories\InstanceRepository;

#[Table]
class InstanceToken
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

    #[Unique]
    #[Char(64)]
    public string $hash;

    #[Varchar(100)]
    public string $name;

    #[SmallInteger]
    #[CheckEnum(TokenStatus::class)]
    public int $status = TokenStatus::ACTIVE->value;

    #[Timestamp]
    public string $created_at;
}
