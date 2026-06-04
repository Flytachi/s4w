<?php

namespace Io;

use Flytachi\Winter\Cdo\Qb;
use Flytachi\Winter\DI\Attribute\Autowired;
use Flytachi\Winter\K2\Stereotype\Job;
use Main\Dto\InstanceStatus;
use Main\Entities\Instance;
use Main\Repositories\InstanceRepository;

class InstanceCreateJob extends Job
{
    #[Autowired]
    private InstanceRepository $repo;

    public function resolution(mixed $data = null): void
    {
        if ($data instanceof Instance || is_string($data)) {
            if (is_string($data)) {
                $data = $this->repo->where(Qb::eq('id', $data))->find();
            }
            $this->create($data);
        } else {
            $this->logger->error('Invalid data provided: ' . json_encode($data));
        }
    }

    private function create(Instance $instance): void
    {
        if ($instance->status === InstanceStatus::PENDING->value) {
            throw new \Exception('Invalid instance status provided: ' . InstanceStatus::from($instance->status)->name);
        }

        try {
            $this->repo->update(
                ['status' => InstanceStatus::PENDING->value, 'updated_at' => date('Y-m-d H:i:s P')],
                Qb::eq('id', $instance->id)
            );

            $manager = new FileManager();
            $manager->mkdir($instance->id);

            $this->repo->update(
                ['status' => InstanceStatus::ACTIVE->value, 'updated_at' => date('Y-m-d H:i:s P')],
                Qb::eq('id', $instance->id)
            );
        } catch (\Throwable $e) {
            $this->repo->update(
                ['status' => InstanceStatus::INACTIVE->value, 'updated_at' => date('Y-m-d H:i:s P')],
                Qb::eq('id', $instance->id)
            );
            $this->logger->error('Failed to update instance status: ' . $e->getMessage());
        }
    }
}
