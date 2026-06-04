<?php

namespace Main\Services;

use Flytachi\Winter\Cdo\Qb;
use Flytachi\Winter\DI\Attribute\Autowired;
use Flytachi\Winter\K2\Stereotype\Service;
use Flytachi\Winter\K2\Unit\Pagination\WrapResult;
use Flytachi\Winter\K2\Unit\Wrapper;
use Io\InstanceCreateJob;
use Io\InstanceDeleteJob;
use Main\Dto\InstanceRes;
use Main\Entities\Instance;
use Main\Repositories\InstanceRepository;
use Main\Requests\Instance\InstanceRequest;
use Main\Requests\ListRequest;

class InstanceService extends Service
{
    #[Autowired]
    private InstanceRepository $repo;

    public function getAll(ListRequest $request): WrapResult
    {
        if ($request->search) {
            $this->repo->where(Qb::or(
                Qb::like('name', "%{$request->search}%"),
                Qb::like('description', "%{$request->search}%")
            ));
        }

        return Wrapper::paginator(
            $this->repo,
            $request->limit,
            $request->page,
            mapper: fn($item) => InstanceRes::from($item)
        );
    }

    public function get(string $id): Instance
    {
        return $this->repo::findByIdOrThrow($id,
            message: "Instance with id {$id} not found"
        );
    }

    public function getObject(string $id): object
    {
        $object = $this->get($id);
        return InstanceRes::from($object);
    }

    public function create(InstanceRequest $request): void
    {
        $model = new Instance;
        $model->name = $request->name;
        $model->description = $request->description;
        $model->quota_bytes = $request->quotaBytes;
        $model->created_at = date('Y-m-d H:i:s P');
        $model->updated_at = $model->created_at;
        $model->id = $this->repo->insert($model);
        InstanceCreateJob::dispatch($model);
    }

    public function update(string $id, InstanceRequest $request): void
    {
        $model = $this->get($id);
        $model->name = $request->name;
        $model->description = $request->description;
        $model->quota_bytes = $request->quotaBytes;
        $model->updated_at = date('Y-m-d H:i:s P');
        $this->repo->update($model, Qb::eq('id', $id));
    }

    public function delete(string $id): void
    {
        $model = $this->get($id);
        InstanceDeleteJob::dispatch($model);
    }
}
