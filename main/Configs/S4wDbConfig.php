<?php

namespace Main\Configs;

use Flytachi\Winter\Cdo\Config\DbConfig;
use Flytachi\Winter\Cdo\Config\PgDbConfig;
use Flytachi\Winter\K2\Ppa\Pool\PpaPoolConfigInterface;
use Flytachi\Winter\K2\Ppa\Pool\PpaPoolTrait;
use Flytachi\Winter\K2\Ppa\PpaCallTrait;

class S4wDbConfig extends PgDbConfig implements PpaPoolConfigInterface
{
    use PpaCallTrait;
    use PpaPoolTrait; // poolMaxConnections = 5, poolWaitTimeout = 3.0 (override if needed)

    public function setUp(): void
    {
        $this->host = env('DB_HOST', 'localhost');
        $this->port = env('DB_PORT', '5432');
        $this->database = env('DB_NAME', 'postgres');
        $this->username = env('DB_USER', 'postgres');
        $this->password = env('DB_PASS', '');
        $this->schema = env('DB_SCHEMA', 'public');
    }
}
