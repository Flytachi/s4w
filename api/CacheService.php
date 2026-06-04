<?php

namespace Api;

use Flytachi\FileStore\FileStorage;
use Flytachi\Winter\K2\Kernel;
use Flytachi\Winter\K2\Stereotype\Service;

class CacheService extends Service
{
    private const string TOKEN_STORE = 's4w.tokens';
    private const int TOKEN_TTL = 10800;

    public function getToken(string $hash): ?string
    {
        $value = $this->tokenStore()->read($hash);
        return is_string($value) ? $value : null;
    }

    public function putToken(string $hash, string $instanceId): void
    {
        $this->tokenStore()->write(
            $hash,
            $instanceId,
            time() + self::TOKEN_TTL,
        );
    }

    public function forgetToken(string $hash): void
    {
        $this->tokenStore()->del($hash);
    }

    private function tokenStore(): FileStorage
    {
        return Kernel::store(self::TOKEN_STORE);
    }
}