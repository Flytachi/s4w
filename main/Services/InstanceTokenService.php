<?php

namespace Main\Services;

use Flytachi\Winter\Base\HttpCode;
use Flytachi\Winter\Cdo\Qb;
use Flytachi\Winter\DI\Attribute\Autowired;
use Flytachi\Winter\K2\Exception\ClientError;
use Flytachi\Winter\K2\Exception\ServerError;
use Flytachi\Winter\K2\Stereotype\Service;
use Flytachi\Winter\K2\Unit\Pagination\WrapResult;
use Flytachi\Winter\K2\Unit\Wrapper;
use Api\CacheService;
use Main\Dto\TokenGenerator;
use Main\Dto\TokenRes;
use Main\Dto\TokenStatus;
use Main\Entities\InstanceToken;
use Main\Repositories\InstanceTokenRepository;
use Main\Requests\Instance\TokenRequest;
use Main\Requests\ListRequest;
use Random\RandomException;

class InstanceTokenService extends Service
{
    #[Autowired]
    private InstanceTokenRepository $repo;

    #[Autowired]
    private InstanceService $instanceService;

    #[Autowired]
    private CacheService $cache;

    public function validate(string $instanceId, string $token): TokenRes
    {
        $hash = TokenGenerator::hash($token);
        $model = $this->repo->where(Qb::and(
            Qb::eq('instance_id', $instanceId),
            Qb::eq('hash', $hash),
        ))->find();
        if (!$model) {
            ClientError::throw('Invalid token', HttpCode::NOT_FOUND);
        }
        return TokenRes::from($model);
    }

    public function getAll(string $instanceId, ListRequest $request): WrapResult
    {
        $this->repo->where(Qb::eq('instance_id', $instanceId));
        return Wrapper::paginator(
            $this->repo,
            $request->limit,
            $request->page,
            mapper: fn($item) => TokenRes::from($item),
        );
    }

    public function get(string $id, ?string $instanceId = null): InstanceToken
    {
        $this->repo->where(Qb::eq('id', $id));
        if ($instanceId !== null) {
            $this->repo->andWhere(Qb::eq('instance_id', $instanceId));
        }
        $model = $this->repo->find();
        if (!$model) {
            ClientError::throw('Token not found', HttpCode::NOT_FOUND);
        }
        return $model;
    }

    public function create(string $instanceId, TokenRequest $request): array
    {
        $instance = $this->instanceService->get($instanceId);
        $generated = $this->generateToken();

        $model = new InstanceToken;
        $model->instance_id = $instance->id;
        $model->hash = $generated['hash'];
        $model->name = $request->name;
        $model->status = TokenStatus::INACTIVE->value;
        $model->created_at = date('Y-m-d H:i:s P');
        $model->id = $this->repo->insert($model);

        return [
            'id' => $model->id,
            'token' => $generated['token'],
            'status' => TokenStatus::INACTIVE->toArray(),
        ];
    }

    public function regenerate(string $instanceId, string $id): array
    {
        $model = $this->get($id, $instanceId);
        $oldHash = $model->hash;
        $generated = $this->generateToken();

        $model->hash = $generated['hash'];
        $this->repo->update($model, Qb::eq('id', $id));
        $this->cache->forgetToken($oldHash);

        return [
            'id' => $model->id,
            'token' => $generated['token'],
            'status' => TokenStatus::from($model->status)->toArray(),
        ];
    }

    public function changeStatus(string $instanceId, string $id, TokenStatus $status): void
    {
        $model = $this->get($id, $instanceId);
        if ($model->status === $status->value) {
            ClientError::throw('Token already has this status', HttpCode::BAD_REQUEST);
        }
        $model->status = $status->value;
        $this->repo->update($model, Qb::eq('id', $id));
        $this->cache->forgetToken($model->hash);
    }

    public function delete(string $instanceId, string $id): void
    {
        $model = $this->get($id, $instanceId);
        $this->repo->delete(Qb::eq('id', $id));
        $this->cache->forgetToken($model->hash);
    }

    /**
     * @return array{token: string, hash: string}
     */
    private function generateToken(): array
    {
        try {
            $generate = TokenGenerator::generate();
        } catch (RandomException $e) {
            ServerError::throw("Failed to generate token: {$e->getMessage()}");
        }
        return $generate;
    }
}
