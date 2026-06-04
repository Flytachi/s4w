<?php

namespace Main\Entities;

use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Constraint\ForeignRepo;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Entity\Table;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Hybrid\UuidPk;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Idx\Unique;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\Timestamp;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\Uuid;
use Flytachi\Winter\K2\Ppa\Mapping\Attributes\Primal\Varchar;
use Flytachi\Winter\K2\Ppa\Mapping\Constants\FKAction;
use Main\Repositories\FileBlobRepository;
use Main\Repositories\InstanceRepository;

#[Table]
class FileRecord
{
    #[UuidPk]
    public ?string $id = null;

    #[Uuid]
    #[ForeignRepo(
        InstanceRepository::class,
        FKAction::CASCADE,
        FKAction::CASCADE
    )]
    #[Unique(['name'], where: 'section IS NULL')]
    #[Unique(['section', 'name'], where: 'section IS NOT NULL')]
    public string $instance_id;

    // NULL = файл в корне инстанса
    #[Varchar(100)]
    public ?string $section = null;

    #[Uuid]
    #[ForeignRepo(
        FileBlobRepository::class,
        FKAction::RESTRICT,
        FKAction::CASCADE
    )]
    public string $blob_id;

    #[Varchar(255)]
    public string $name;

    #[Timestamp]
    public string $created_at;

    #[Timestamp]
    public string $updated_at;
}
