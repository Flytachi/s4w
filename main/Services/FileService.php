<?php

namespace Main\Services;

use Flytachi\Winter\Base\Algorithm;
use Flytachi\Winter\Base\HttpCode;
use Flytachi\Winter\Cdo\Qb;
use Flytachi\Winter\DI\Attribute\Autowired;
use Flytachi\Winter\K2\Exception\ClientError;
use Flytachi\Winter\K2\Stereotype\Service;
use Flytachi\Winter\K2\Unit\Wrapper;
use Io\FileManager;
use Main\Dto\FileRes;
use Main\Entities\FileBlob;
use Main\Entities\FileRecord;
use Main\Repositories\FileBlobRepository;
use Main\Repositories\FileRecordRepository;
use Main\Repositories\InstanceRepository;
use Main\Requests\File\FileListRequest;
use Main\Requests\File\FileRequest;

class FileService extends Service
{
    private bool $adminMode = false;

    #[Autowired]
    private InstanceRepository $instanceRepo;

    #[Autowired]
    private FileBlobRepository $blobRepo;

    #[Autowired]
    private FileRecordRepository $recordRepo;

    public function upload(
        string $instanceId,
        array $file,
        FileRequest $form,
    ): FileRes {
        $tmpPath = $file['tmp_name'] ?? null;
        if (!$tmpPath || !is_file($tmpPath)) {
            ClientError::throw('Upload tmp file missing', HttpCode::BAD_REQUEST);
        }

        $section = $form->section; // просто строка, без резолва в БД

        $sourceName = (string) ($file['name'] ?? '');
        $finalName = $form->name
            ?: ($sourceName !== '' ? $sourceName : Algorithm::random(16));

        $mime = $this->detectMime($tmpPath);
        $processedPath = $this->processImage($tmpPath, $mime, $form);
        $processedIsOwned = $processedPath !== $tmpPath;

        // post-processing → реальный mime/расширение (вне транзакции, на $form->...)
        if ($form->webp && str_starts_with($mime, 'image/') && $mime !== 'image/webp') {
            $mime = 'image/webp';
            $finalName = $this->replaceExtension($finalName, 'webp');
        }
        $extension = $this->extractExtension($finalName);

        $hash = hash_file('sha256', $processedPath);
        $size = (int) filesize($processedPath);

        $db = $this->blobRepo->db();
        $db->beginTransaction();
        $casWritten = false;

        try {
            // dedup lookup под FOR UPDATE — лочит существующий blob от параллельного delete
            $this->blobRepo
                ->where(Qb::and(
                    Qb::eq('instance_id', $instanceId),
                    Qb::eq('hash', $hash),
                ))
                ->forBy('UPDATE');
            $blob = $this->blobRepo->find();
            $deduplicated = $blob !== null;

            if (!$deduplicated) {
                // Lock instance + read used_bytes под FOR UPDATE — защита от lost-update
                // при параллельных операциях с разными blob одного инстанса
                $this->instanceRepo
                    ->where(Qb::eq('id', $instanceId))
                    ->forBy('UPDATE');
                $instance = $this->instanceRepo->find()
                    ?? throw new \RuntimeException('Instance vanished mid-transaction');

                if ($instance->used_bytes + $size > $instance->quota_bytes) {
                    ClientError::throw(
                        "Quota exceeded: {$instance->used_bytes} + {$size} > {$instance->quota_bytes}",
                        HttpCode::REQUEST_ENTITY_TOO_LARGE,
                    );
                }

                FileManager::blobWrite($instanceId, $hash, $processedPath);
                $processedIsOwned = false;
                $casWritten = true;

                $blob = new FileBlob();
                $blob->instance_id = $instanceId;
                $blob->hash = $hash;
                $blob->size_bytes = $size;
                $blob->mime_type = $mime;
                $blob->extension = $extension;
                $blob->ref_count = 1;
                $blob->created_at = date('Y-m-d H:i:s P');
                $blob->id = $this->blobRepo->insert($blob);

                $this->instanceRepo->update(
                    ['used_bytes' => $instance->used_bytes + $size],
                    Qb::eq('id', $instanceId),
                );
            } else {
                $this->blobRepo->update(
                    ['ref_count' => $blob->ref_count + 1],
                    Qb::eq('id', $blob->id),
                );
            }

            // авто-суффикс при коллизии имени в этой секции/корне
            $finalName = $this->resolveNameCollision($instanceId, $section, $finalName);

            $record = new FileRecord();
            $record->instance_id = $instanceId;
            $record->section = $section;
            $record->blob_id = $blob->id;
            $record->name = $finalName;
            $record->created_at = date('Y-m-d H:i:s P');
            $record->updated_at = $record->created_at;
            $record->id = $this->recordRepo->insert($record);

            $db->commit();

            return $this->buildRes($instanceId, $record, $blob, $deduplicated);
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            // транзакция откатилась — если CAS-файл уже записан, он сирота, чистим
            if ($casWritten) {
                FileManager::blobDelete($instanceId, $hash);
            }
            throw $e;
        } finally {
            if ($processedIsOwned && is_file($processedPath)) {
                @unlink($processedPath);
            }
        }
    }

    private function detectMime(string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return 'application/octet-stream';
        }
        return finfo_file($finfo, $path) ?: 'application/octet-stream';
    }

    private function processImage(string $srcPath, string $mime, FileRequest $form): string
    {
        $needsCompress = $form->compress !== null;
        $needsWebp = $form->webp;

        if (!$needsCompress && !$needsWebp) {
            return $srcPath;
        }

        if (!str_starts_with($mime, 'image/')) {
            ClientError::throw(
                "compress/webp not supported for {$mime}",
                HttpCode::UNPROCESSABLE_ENTITY,
            );
        }

        if ($mime === 'image/webp' && $needsWebp && !$needsCompress) {
            return $srcPath;
        }

        $im = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($srcPath),
            'image/png'  => @imagecreatefrompng($srcPath),
            'image/gif'  => @imagecreatefromgif($srcPath),
            'image/webp' => @imagecreatefromwebp($srcPath),
            default      => false,
        };
        if (!$im) {
            ClientError::throw("Unsupported image format: {$mime}", HttpCode::UNPROCESSABLE_ENTITY);
        }

        // GIF и палитровые PNG: imagewebp иногда возвращает true но пишет 0 байт.
        // Принудительно переводим в truecolor перед webp-энкодом.
        if ($needsWebp && !imageistruecolor($im)) {
            imagepalettetotruecolor($im);
        }

        $outPath = $srcPath . '.processed';
        $quality = $form->compress ?? 80;

        $ok = $needsWebp
            ? imagewebp($im, $outPath, $quality)
            : match ($mime) {
                'image/jpeg' => imagejpeg($im, $outPath, $quality),
                'image/png'  => imagepng($im, $outPath, $this->qualityToPngLevel($quality)),
                'image/webp' => imagewebp($im, $outPath, $quality),
                'image/gif'  => imagegif($im, $outPath),
                default      => false,
            };
        imagedestroy($im);

        if (!$ok || !is_file($outPath) || filesize($outPath) === 0) {
            @unlink($outPath);
            ClientError::throw(
                'Image processing produced empty output',
                HttpCode::UNPROCESSABLE_ENTITY,
            );
        }

        return $outPath;
    }

    private function qualityToPngLevel(int $quality): int
    {
        return 9 - (int) round($quality / 100 * 9);
    }

    private function resolveNameCollision(string $instanceId, ?string $section, string $name): string
    {
        $candidate = $name;
        $i = 1;
        while ($this->nameExists($instanceId, $section, $candidate)) {
            $candidate = $this->suffixed($name, $i);
            $i++;
        }
        return $candidate;
    }

    private function nameExists(string $instanceId, ?string $section, string $name): bool
    {
        $where = $section === null
            ? Qb::and(
                Qb::eq('instance_id', $instanceId),
                Qb::isNull('section'),
                Qb::eq('name', $name),
            )
            : Qb::and(
                Qb::eq('instance_id', $instanceId),
                Qb::eq('section', $section),
                Qb::eq('name', $name),
            );
        $this->recordRepo->where($where);
        return $this->recordRepo->count() > 0;
    }

    private function suffixed(string $name, int $i): string
    {
        $dot = strrpos($name, '.');
        if ($dot === false) {
            return $name . " ($i)";
        }
        return substr($name, 0, $dot) . " ($i)" . substr($name, $dot);
    }

    private function extractExtension(string $name): string
    {
        $dot = strrpos($name, '.');
        if ($dot === false || $dot === strlen($name) - 1) {
            return '';
        }
        return substr($name, $dot + 1);
    }

    private function replaceExtension(string $name, string $newExt): string
    {
        $dot = strrpos($name, '.');
        $base = $dot === false ? $name : substr($name, 0, $dot);
        return $base . '.' . $newExt;
    }

    // ───────────────────────────────────────────────────────────────────────
    //  GET ONE / LIST / DELETE
    // ───────────────────────────────────────────────────────────────────────

    public function getOne(string $instanceId, string $id): FileRes
    {
        $record = $this->findRecord($instanceId, $id);
        $blob = $this->findBlob($record->blob_id);
        return $this->buildRes($instanceId, $record, $blob, deduplicated: false);
    }

    /**
     * Список уникальных названий секций, использованных в файлах этого инстанса.
     * Секция = строка на FileRecord, нет отдельной таблицы.
     */
    public function listSections(string $instanceId): array
    {
        $this->recordRepo
            ->select('DISTINCT section')
            ->where(Qb::and(
                Qb::eq('instance_id', $instanceId),
                Qb::isNotNull('section'),
            ));
        $rows = $this->recordRepo->findAll();
        return array_map(fn($r) => $r->section, $rows);
    }

    public function getAll(string $instanceId, FileListRequest $request): array
    {
        $where = [Qb::eq('instance_id', $instanceId)];
        $where[] = $request->section !== null
            ? Qb::eq('section', $request->section)
            : Qb::isNull('section');
        if ($request->search) {
            $where[] = Qb::like('name', "%{$request->search}%");
        }
        $this->recordRepo->where(Qb::and(...$where));

        $wrapper = Wrapper::paginator($this->recordRepo, $request->limit, $request->page);
        $wrapper['list'] = array_map(
            fn(FileRecord $r) => $this->buildRes($instanceId, $r, $this->findBlob($r->blob_id), false),
            $wrapper['list'],
        );
        return $wrapper;
    }

    /**
     * Удаление файла + GC под транзакцией:
     *   1. BEGIN
     *   2. SELECT FileRecord WHERE id=? AND instance_id=? FOR UPDATE
     *      ← лочит запись; дубликат delete будет ждать, после commit — 404
     *   3. SELECT blob WHERE id=? FOR UPDATE
     *      ← лочит blob от параллельного upload/delete по этому контенту
     *   4. DELETE FileRecord
     *   5. blob.ref_count -= 1
     *      - > 0 → UPDATE blob.ref_count
     *      - = 0 → DELETE blob row + UPDATE instance.used_bytes
     *   6. COMMIT
     *   7. После commit: unlink физ. файла (если blob был удалён в БД)
     *
     * unlink делается ПОСЛЕ commit намеренно: если транзакция откатилась,
     * файл не должен пропасть. Если unlink упадёт после commit — будет
     * orphan-файл, на который никто не ссылается (cleanup background-ом).
     */
    public function delete(string $instanceId, string $id): void
    {
        $db = $this->blobRepo->db();
        $db->beginTransaction();

        $blobToUnlink = null;

        try {
            // Lock record — дубликат delete будет ждать, после commit найдёт пустоту → 404
            $this->recordRepo
                ->where(Qb::and(
                    Qb::eq('id', $id),
                    Qb::eq('instance_id', $instanceId),
                ))
                ->forBy('UPDATE');
            $record = $this->recordRepo->find();
            if (!$record) {
                ClientError::throw('File not found', HttpCode::NOT_FOUND);
            }

            // Lock blob — сериализуется с параллельным upload/delete этого blob
            $this->blobRepo
                ->where(Qb::eq('id', $record->blob_id))
                ->forBy('UPDATE');
            $blob = $this->blobRepo->find();

            $this->recordRepo->delete(Qb::eq('id', $record->id));

            if ($blob) {
                $newCount = $blob->ref_count - 1;
                if ($newCount > 0) {
                    $this->blobRepo->update(
                        ['ref_count' => $newCount],
                        Qb::eq('id', $blob->id),
                    );
                } else {
                    $this->blobRepo->delete(Qb::eq('id', $blob->id));

                    // Lock instance + read used_bytes под FOR UPDATE — защита от lost-update
                    $this->instanceRepo
                        ->where(Qb::eq('id', $instanceId))
                        ->forBy('UPDATE');
                    $instance = $this->instanceRepo->find()
                        ?? throw new \RuntimeException('Instance vanished mid-transaction');

                    $this->instanceRepo->update(
                        ['used_bytes' => max(0, $instance->used_bytes - $blob->size_bytes)],
                        Qb::eq('id', $instanceId),
                    );
                    $blobToUnlink = $blob;
                }
            }

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        // unlink физ. файла — вне транзакции, только после успешного commit
        if ($blobToUnlink !== null) {
            FileManager::blobDelete($blobToUnlink->instance_id, $blobToUnlink->hash);
        }
    }

    private function findRecord(string $instanceId, string $id): FileRecord
    {
        $this->recordRepo->where(Qb::and(
            Qb::eq('id', $id),
            Qb::eq('instance_id', $instanceId),
        ));
        $record = $this->recordRepo->find();
        if (!$record) {
            ClientError::throw('File not found', HttpCode::NOT_FOUND);
        }
        return $record;
    }

    private function findBlob(string $blobId): FileBlob
    {
        $this->blobRepo->where(Qb::eq('id', $blobId));
        $blob = $this->blobRepo->find();
        if (!$blob) {
            ClientError::throw('Blob missing for record', HttpCode::INTERNAL_SERVER_ERROR);
        }
        return $blob;
    }

    private function buildRes(string $instanceId, FileRecord $record, FileBlob $blob, bool $deduplicated): FileRes
    {
        return new FileRes(
            id: $record->id,
            name: $record->name,
            section: $record->section,
            size: $blob->size_bytes,
            mime: $blob->mime_type,
            extension: $blob->extension,
            hash: $blob->hash,
            deduplicated: $deduplicated,
            createdAt: $record->created_at,
            url: $this->buildUrl($instanceId, $record->section, $record->id),
        );
    }

    /**
     * URL для запроса файла обратно. Клиент должен слать Bearer токен.
     *   root:    {APP_URL}/media/{id}
     *   section: {APP_URL}/media/{section}/{id}
     */
    private function buildUrl(string $instanceId, ?string $section, string $id): string
    {
        $base = rtrim((string) env('APP_URL', ''), '/');
        $path = $section === null
            ? '/media/' . $id
            : '/media/' . rawurlencode($section) . '/' . $id;
        if ($this->adminMode) {
            $path = '/s4w/instances/'. $instanceId . $path;
        }
        return $base . $path;
    }

    public function adminModeOn(): void
    {
        $this->adminMode = true;
    }
}
