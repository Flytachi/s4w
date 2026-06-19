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

    #[Autowired]
    private MediaCache $cache;

    public function downloadById(string $instanceId, string $id, bool $download = false): ResponseFile
    {
        $meta = $this->resolve($instanceId, $id);

        if ($meta['section'] !== null) {
            ClientError::throw('Not a root file', HttpCode::NOT_FOUND);
        }

        return $this->serve($meta, $download);
    }

    public function downloadBySection(string $instanceId, string $section, string $id, bool $download = false): ResponseFile
    {
        $meta = $this->resolve($instanceId, $id);

        if ($meta['section'] !== $section) {
            ClientError::throw('Not found', HttpCode::NOT_FOUND);
        }

        return $this->serve($meta, $download);
    }

    /**
     * Публичная отдача (без токена): только если файл помечен public.
     * Несуществующий/приватный/чужой → 404 (не раскрываем существование).
     */
    public function downloadPublicById(string $instanceId, string $id, bool $download = false): ResponseFile
    {
        $meta = $this->resolve($instanceId, $id);
        if ($meta['section'] !== null || !$meta['is_public']) {
            ClientError::throw('Not found', HttpCode::NOT_FOUND);
        }
        return $this->serve($meta, $download);
    }

    public function downloadPublicBySection(string $instanceId, string $section, string $id, bool $download = false): ResponseFile
    {
        $meta = $this->resolve($instanceId, $id);
        if ($meta['section'] !== $section || !$meta['is_public']) {
            ClientError::throw('Not found', HttpCode::NOT_FOUND);
        }
        return $this->serve($meta, $download);
    }

    /**
     * Метаданные отдачи по (instanceId, id): сначала FileStore-кэш, при промахе —
     * два SELECT'а (FileRecord + FileBlob), результат кладём в кэш под текущей
     * версией инстанса. Ключ кэша уже привязан к instanceId, поэтому хит —
     * заведомо «свой»; на промахе сверяем instance_id записи и при чужом/несуществ.
     * бросаем 404 (и НЕ кэшируем — чтобы не плодить негативные записи).
     *
     * @return array{section: ?string, is_public: bool, name: string,
     *               blob_instance_id: string, hash: string, mime: string, extension: string}
     */
    private function resolve(string $instanceId, string $id): array
    {
        $version = $this->cache->version($instanceId);
        $hit = $this->cache->get($instanceId, $version, $id);
        if ($hit !== null) {
            return $hit;
        }

        $record = $this->findRecord($id);
        if ($record->instance_id !== $instanceId) {
            ClientError::throw('Not found', HttpCode::NOT_FOUND);
        }

        $this->blobRepo->where(Qb::eq('id', $record->blob_id));
        $blob = $this->blobRepo->find();
        if (!$blob) {
            ClientError::throw('Blob missing', HttpCode::INTERNAL_SERVER_ERROR);
        }

        $meta = [
            'section'          => $record->section,
            'is_public'        => (bool) $record->is_public,
            'name'             => $record->name,
            'blob_instance_id' => $blob->instance_id,
            'hash'             => $blob->hash,
            'mime'             => $blob->mime_type,
            'extension'        => $blob->extension,
        ];
        $this->cache->put($instanceId, $version, $id, $meta);
        return $meta;
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

    /**
     * @param array{section: ?string, is_public: bool, name: string,
     *              blob_instance_id: string, hash: string, mime: string, extension: string} $meta
     */
    private function serve(array $meta, bool $forceAttachment = false): ResponseFile
    {
        // Физический blob проверяем всегда (даже на кэш-хите): дёшево (stat) и
        // ловит исчезнувший файл / рассинхрон кэша с хранилищем.
        if (!FileManager::blobExists($meta['blob_instance_id'], $meta['hash'])) {
            ClientError::throw('Blob file vanished', HttpCode::INTERNAL_SERVER_ERROR);
        }

        // ?download=1 → всегда attachment; иначе inline только для безопасно
        // отображаемых типов, остальное (zip/office/данные + html/svg) — attachment.
        $isAttachment = $forceAttachment || !$this->isInlineMime($meta['mime']);

        return ResponseFile::binary(
            data: '',
            fileName: $this->ensureNameExtension($meta['name'], $meta['extension']),
            mimeType: $meta['mime'],
            isAttachment: $isAttachment,
        )->header('X-Accel-Redirect', $this->internalUri($meta['blob_instance_id'], $meta['hash']));
    }

    /**
     * Какие mime безопасно отдавать inline (показывать в браузере).
     * text/html и image/svg+xml СОЗНАТЕЛЬНО не inline — могут нести скрипт
     * (stored-XSS в origin сервиса), отдаём их attachment.
     */
    private function isInlineMime(string $mime): bool
    {
        $mime = strtolower(trim($mime));
        if ($mime === 'image/svg+xml') {
            return false;
        }
        return str_starts_with($mime, 'image/')
            || str_starts_with($mime, 'video/')
            || str_starts_with($mime, 'audio/')
            || $mime === 'application/pdf'
            || $mime === 'text/plain';
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