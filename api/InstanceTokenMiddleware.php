<?php

namespace Api;

use Flytachi\Winter\Cdo\Qb;
use Flytachi\Winter\DI\Attribute\Autowired;
use Flytachi\Winter\K2\Http\Contracts\HttpRequest;
use Flytachi\Winter\K2\Http\Contracts\HttpResponse;
use Flytachi\Winter\K2\Http\Header;
use Flytachi\Winter\K2\Http\Middleware\MiddlewareException;
use Flytachi\Winter\K2\Stereotype\Middleware;
use Main\Dto\TokenGenerator;
use Main\Dto\TokenStatus;
use Main\Repositories\InstanceTokenRepository;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
class InstanceTokenMiddleware extends Middleware
{
    #[Autowired]
    private InstanceTokenRepository $repo;

    #[Autowired]
    private AuthContext $auth;

    #[Autowired]
    private CacheService $cache;

    public function before(HttpRequest $request, HttpResponse $response): void
    {
        $token = Header::getBearerToken();
        if (!$token) {
            MiddlewareException::throw('Token required');
        }

        $hash = TokenGenerator::hash($token);

        $cachedInstanceId = $this->cache->getToken($hash);
        if ($cachedInstanceId !== null) {
            $this->auth->setInstanceId($cachedInstanceId);
            return;
        }

        $model = $this->repo->where(Qb::eq('hash', $hash))->find();
        if (!$model) {
            MiddlewareException::throw('Invalid token');
        }
        if ($model->status !== TokenStatus::ACTIVE->value) {
            MiddlewareException::throw('Token is inactive');
        }

        $this->cache->putToken($hash, $model->instance_id);
        $this->auth->setInstanceId($model->instance_id);
    }
}
