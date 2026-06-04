<?php

namespace Main\Entities;

use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Constraint\CheckEnum;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Entity\Table;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Hybrid\UuidPk;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Idx\Unique;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\BigInteger;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\SmallInteger;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\Timestamp;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\Varchar;
use Main\Dto\InstanceStatus;

#[Table]
class Instance
{
    #[UuidPk]
    public ?string $id = null;

    #[Unique]
    #[Varchar(100)]
    public string $name;

    #[Varchar(255)]
    public string $description;

    #[BigInteger]
    public int $quota_bytes = 104857600; // 100MB

    #[BigInteger]
    public int $used_bytes = 0;

    #[SmallInteger]
    #[CheckEnum(InstanceStatus::class)]
    public int $status = InstanceStatus::CREATED->value;

    #[Timestamp]
    public string $created_at;

    #[Timestamp]
    public string $updated_at;
}
