<?php

namespace Main\Repositories;

use Flytachi\Winter\K2\Ppa\Stereotype\Repository;
use Main\Configs\S4wDbConfig;
use Main\Entities\FileRecord;

class FileRecordRepository extends Repository
{
    protected string $dbConfigClassName = S4wDbConfig::class;
    public static string $table = 's4w_file_records';
    protected string $entityClassName = FileRecord::class;
}