<?php

namespace Main\Configs;

use Flytachi\Winter\Cache\Store\RedisStore;

class HStore extends RedisStore
{
    protected static string $redisConfigClassName = S4wRedisConfig::class;

    public static function main(): \Redis
    {
        return self::init(env('REDIS_DBNAME', 0));
    }
}
