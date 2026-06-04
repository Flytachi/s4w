<?php

namespace Api;

use Flytachi\Winter\DI\Attribute\Request;

/**
 * Request-scoped: один экземпляр на HTTP-запрос (в Swoole — на корутину).
 * Заполняется InstanceTokenMiddleware, читается контроллерами/сервисами.
 */
#[Request]
class AuthContext
{
    private ?string $instanceId = null;

    public function setInstanceId(string $id): void
    {
        $this->instanceId = $id;
    }

    public function instanceId(): string
    {
        if ($this->instanceId === null) {
            throw new \LogicException('AuthContext is empty — middleware did not run');
        }
        return $this->instanceId;
    }
}