<?php

namespace Main\Services;

use Flytachi\Winter\Base\HttpCode;
use Flytachi\Winter\Cdo\Qb;
use Flytachi\Winter\DI\Attribute\Autowired;
use Flytachi\Winter\K2\Exception\ClientError;
use Flytachi\Winter\K2\Http\Response\ResponseFile;
use Flytachi\Winter\K2\Stereotype\Service;
use Io\FileManager;
use Main\Entities\FileRecord;
use Main\Repositories\FileBlobRepository;
use Main\Repositories\FileRecordRepository;

class MediaService extends Service
{
    #[Autowired]
    private FileRecordRepository $recordRepo;

    #[Autowired]
    private FileBlobRepository $blobRepo;

    public function downloadById(string $instanceId, string $id): ResponseFile
    {
        $record = $this->findRecord($id);

        if ($record->instance_id !== $instanceId) {
            ClientError::throw('Not found', HttpCode::NOT_FOUND);
        }
        if ($record->section !== null) {
            ClientError::throw('Not a root file', HttpCode::NOT_FOUND);
        }

        return $this->serve($record);
    }

    public function downloadBySection(string $instanceId, string $section, string $id): ResponseFile
    {
        $record = $this->findRecord($id);

        if ($record->instance_id !== $instanceId) {
            ClientError::throw('Not found', HttpCode::NOT_FOUND);
        }
        if ($record->section !== $section) {
            ClientError::throw('Not found', HttpCode::NOT_FOUND);
        }

        return $this->serve($record);
    }

    private function findRecord(string $id): FileRecord
    {
        $this->recordRepo->where(Qb::eq('id', $id));
        $record = $this->recordRepo->find();
        if (!$record) {
            ClientError::throw('File not found', HttpCode::NOT_FOUND);
        }
        return $record;
    }

    private function serve(FileRecord $record): ResponseFile
    {
        $this->blobRepo->where(Qb::eq('id', $record->blob_id));
        $blob = $this->blobRepo->find();
        if (!$blob) {
            ClientError::throw('Blob missing', HttpCode::INTERNAL_SERVER_ERROR);
        }

        if (!FileManager::blobExists($blob->instance_id, $blob->hash)) {
            ClientError::throw('Blob file vanished', HttpCode::INTERNAL_SERVER_ERROR);
        }

        return ResponseFile::binary(
            data: '',
            fileName: $this->ensureNameExtension($record->name, $blob->extension),
            mimeType: $blob->mime_type,
            isAttachment: false,
        )->header('X-Accel-Redirect', $this->internalUri($blob->instance_id, $blob->hash));
    }

    /**
     * При отдаче: если в имени нет расширения — дописываем расширение blob'а.
     * Страховка (в т.ч. для старых записей, сохранённых без расширения в имени).
     * Если расширение уже есть — не трогаем.
     */
    private function ensureNameExtension(string $name, string $ext): string
    {
        if ($ext === '') {
            return $name;
        }
        $dot = strrpos($name, '.');
        $hasExt = $dot !== false && $dot !== strlen($name) - 1;
        return $hasExt ? $name : $name . '.' . $ext;
    }

    /**
     * Внутренний URI для X-Accel-Redirect. Соответствует `internal`-локации
     * `/internal/chest/` в nginx. Сегменты безопасны для пути: instanceId —
     * UUID, hash — hex sha256 (оба без слешей и спецсимволов).
     */
    private function internalUri(string $instanceId, string $hash): string
    {
        return '/internal/' . FileManager::ROOT_FOLDER . '/' . $instanceId . '/' . $hash;
    }
}