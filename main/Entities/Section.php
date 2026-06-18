<?php

namespace Main\Entities;

use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Constraint\ForeignRepo;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Entity\Table;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Hybrid\UuidPk;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Idx\Unique;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\Boolean;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\Timestamp;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\Uuid;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\Varchar;
use Flytachi\Winter\K2\Ppa\Mapping\Constants\FKAction;
use Main\Repositories\InstanceRepository;

#[Table]
class Section
{
    #[UuidPk]
    public ?string $id = null;

    #[Uuid]
    #[ForeignRepo(
        InstanceRepository::class,
        FKAction::CASCADE,
        FKAction::CASCADE
    )]
    #[Unique(['name'])]
    public string $instance_id;

    #[Varchar(100)]
    public string $name;

    #[Boolean]
    public bool $is_public = false;

    #[Timestamp]
    public string $created_at;

    #[Timestamp]
    public string $updated_at;
}
