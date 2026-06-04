<?php

namespace Main\Configs;

use Flytachi\Winter\Cache\Config\RedisConfig;

class S4wRedisConfig extends RedisConfig
{
    public function setUp(): void
    {
        $this->host = env('REDIS_HOST', 'localhost');
        $this->port = env('REDIS_PORT', 6379);
        $this->password = env('REDIS_PASS', '');
        $this->databaseIndex = env('REDIS_DBNAME', 0);
    }
}
