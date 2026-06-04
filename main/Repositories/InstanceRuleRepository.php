<?php

namespace Main\Repositories;

use Flytachi\Winter\K2\Ppa\Stereotype\Repository;
use Main\Configs\S4wDbConfig;
use Main\Entities\InstanceRule;

class InstanceRuleRepository extends Repository
{
    protected string $dbConfigClassName = S4wDbConfig::class;
    public static string $table = 's4w_instance_rules';
    protected string $entityClassName = InstanceRule::class;
}
