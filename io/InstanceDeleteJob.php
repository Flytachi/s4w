<?php

namespace Io;

use Flytachi\Winter\Cdo\Qb;
use Flytachi\Winter\DI\Attribute\Autowired;
use Flytachi\Winter\K2\Stereotype\Job;
use Main\Entities\Instance;
use Main\Repositories\InstanceRepository;

class InstanceDeleteJob extends Job
{
    #[Autowired]
    private InstanceRepository $repo;

    public function resolution(mixed $data = null): void
    {
        if ($data instanceof Instance || is_string($data)) {
            if (is_string($data)) {
                $data = $this->repo->where(Qb::eq('id', $data))->find();
            }
            if ($data === null) {
                // уже удалён — идемпотентный no-op
                return;
            }
            $this->delete($data);
        } else {
            $this->logger->error('Invalid data provided: ' . json_encode($data));
        }
    }

    /**
     * Порядок намеренный: DB → FS.
     *  - Сначала удаляем row инстанса. CASCADE FK уносит file_records / file_blobs / instance_tokens.
     *    Если упадём здесь — файлы целы, БД консистентна.
     *  - Потом удаляем папку. Если упадём здесь — БД консистентна, на диске orphan-папка.
     *    Её подберёт фоновый GC ({@see \Io\Scripts\OrphanFolderGc}).
     *
     * Идемпотентен: rmdir сейчас no-op на отсутствующей папке; repo->delete с тем же id
     * вернёт 0 affected rows.
     */
    private function delete(Instance $instance): void
    {
        try {
            $this->repo->delete(Qb::eq('id', $instance->id));

            $manager = new FileManager();
            $manager->rmdir($instance->id);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to delete instance: ' . $e->getMessage());
            throw $e;
        }
    }
}
