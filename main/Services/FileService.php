<?php

namespace Main\Services;

use Flytachi\Winter\Base\Algorithm;
use Flytachi\Winter\Base\HttpCode;
use Flytachi\Winter\Cdo\Qb;
use Flytachi\Winter\DI\Attribute\Autowired;
use Flytachi\Winter\K2\Exception\ClientError;
use Flytachi\Winter\K2\Stereotype\Service;
use Flytachi\Winter\K2\Unit\Pagination\WrapResult;
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
        string $baseUrl,
    ): FileRes {
        $tmpPath = $file['tmp_name'] ?? null;
        if (!$tmpPath || !is_file($tmpPath)) {
            ClientError::throw('Upload tmp file missing', HttpCode::BAD_REQUEST);
        }

        // App-level лимит размера. Единый источник правды — env UPLOAD_MAX_FILESIZE
        // (тот же, что docker entrypoint выставляет в php.ini и nginx). Здесь даёт
        // явный 413 с понятным сообщением; PHP-уровень (upload_max_filesize) —
        // жёсткая стена на тот же лимит.
        $maxBytes = $this->maxUploadBytes();
        $incomingSize = (int) (filesize($tmpPath) ?: ($file['size'] ?? 0));
        if ($maxBytes > 0 && $incomingSize > $maxBytes) {
            ClientError::throw(
                'File exceeds upload limit of ' . env('UPLOAD_MAX_FILESIZE', '100M'),
                HttpCode::REQUEST_ENTITY_TOO_LARGE,
            );
        }

        $section = $form->section; // просто строка, без резолва в БД

        $sourceName = (string) ($file['name'] ?? '');
        $finalName = $form->name
            ?: ($sourceName !== '' ? $sourceName : Algorithm::random(16));

        $mime = $this->detectMime($tmpPath);
        $processedPath = $this->processImage($tmpPath, $mime, $form);
        $processedIsOwned = $processedPath !== $tmpPath;

        if ($form->webp && str_starts_with($mime, 'image/') && $mime !== 'image/webp') {
            $mime = 'image/webp';
            $finalName = $this->replaceExtension($finalName, 'webp');
        }

        // extension у blob'а — характеристика контента. Источник истины — mime
        // (детектируется по байтам через finfo). Имя файла из multipart — это
        // user-input, может врать (photo.png внутри JPEG). Используем его только
        // как fallback, если mime неизвестный.
        $extension = $this->mimeToExtension($mime);
        // finfo для текстовых форматов почти всегда отдаёт text/plain и НЕ различает
        // csv/tsv/json/xml/md/... — по байтам это просто текст, поэтому csv → txt.
        // Для text/plain доверяем расширению исходного имени (если оно конкретнее
        // txt) и уточняем по нему mime.
        if ($mime === 'text/plain') {
            $srcExt = strtolower($this->extractExtension($sourceName));
            if ($srcExt !== '' && $srcExt !== 'txt') {
                $extension = $srcExt;
                $mappedMime = $this->extensionToMime($srcExt);
                if ($mappedMime !== '') {
                    $mime = $mappedMime;
                }
            }
        } elseif ($extension === '') {
            $extension = $this->extractExtension($sourceName);
        }

        // Display-name должен нести расширение, иначе скачивание даёт файл без него.
        // Если пользователь задал имя без расширения (или сгенерили random) —
        // дописываем определённое по контенту. Имя с уже имеющимся расширением
        // не трогаем (в т.ч. webp-конвертацию выше).
        $finalName = $this->ensureExtension($finalName, $extension);

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

            $record = new FileRecord();
            $record->instance_id = $instanceId;
            $record->section = $section;
            $record->blob_id = $blob->id;
            $record->created_at = date('Y-m-d H:i:s P');
            $record->updated_at = $record->created_at;

            // SAVEPOINT-retry: на UNIQUE(instance_id, [section,] name) возможна гонка
            // с параллельным upload'ом того же имени. SELECT-based pre-check race-prone
            // (между SELECT и INSERT окно), поэтому пробуем INSERT и при 23505
            // откатываемся к savepoint'у и инкрементим суффикс.
            $baseName = $finalName;
            $candidate = $finalName;
            $inserted = false;
            for ($i = 0; $i < 10; $i++) {
                $record->name = $candidate;
                $db->exec('SAVEPOINT s4w_rec_ins');
                try {
                    $record->id = $this->recordRepo->insert($record);
                    $db->exec('RELEASE SAVEPOINT s4w_rec_ins');
                    $inserted = true;
                    $finalName = $candidate;
                    break;
                } catch (\Throwable $e) {
                    if (!$this->isUniqueViolation($e)) {
                        // не наша гонка — внешний catch откатит транзакцию целиком
                        throw $e;
                    }
                    $db->exec('ROLLBACK TO SAVEPOINT s4w_rec_ins');
                    $candidate = $this->suffixed($baseName, $i + 1);
                }
            }
            if (!$inserted) {
                ClientError::throw(
                    'Name conflict, retry limit exceeded',
                    HttpCode::CONFLICT,
                );
            }
            $db->commit();

            return $this->buildRes($instanceId, $record, $blob, $deduplicated, $baseUrl);
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

    /**
     * Лимит загрузки в байтах из env UPLOAD_MAX_FILESIZE.
     * Формат PHP-shorthand: '100M', '2G', '500K' или чистые байты. 0 — без лимита.
     * Бинарные единицы (1K = 1024), как у PHP ini.
     */
    private function maxUploadBytes(): int
    {
        $raw = trim((string) env('UPLOAD_MAX_FILESIZE', '100M'));
        if ($raw === '' || !preg_match('/^(\d+(?:\.\d+)?)\s*([KMGkmg]?)/', $raw, $m)) {
            return 0;
        }
        $value = (float) $m[1];
        return (int) match (strtoupper($m[2])) {
            'K'     => $value * 1024,
            'M'     => $value * 1024 * 1024,
            'G'     => $value * 1024 * 1024 * 1024,
            default => $value,
        };
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
        // compress: 0|null — без сжатия, 100 — максимальное.
        // Внутри GD ожидает quality: 100 — без потерь, 0 — максимально пожатый.
        // Инвертируем: quality = 100 - compress.
        $compress = max(0, min(100, $form->compress ?? 0));
        $needsCompress = $compress > 0;
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
        $quality = $needsCompress ? 100 - $compress : 80;

        $ok = $needsWebp
            ? imagewebp($im, $outPath, $quality)
            : match ($mime) {
                'image/jpeg' => imagejpeg($im, $outPath, $quality),
                'image/png'  => imagepng($im, $outPath, $this->qualityToPngLevel($quality)),
                'image/webp' => imagewebp($im, $outPath, $quality),
                'image/gif'  => imagegif($im, $outPath),
                default      => false,
            };

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

    /**
     * Распознаёт UNIQUE-violation (SQLSTATE 23505 для PostgreSQL).
     * Идём по цепочке previous до PDOException — Repository оборачивает
     * CDOException, тот оборачивает PDOException.
     */
    private function isUniqueViolation(\Throwable $e): bool
    {
        for ($cur = $e; $cur !== null; $cur = $cur->getPrevious()) {
            if ($cur instanceof \PDOException && (string) $cur->getCode() === '23505') {
                return true;
            }
        }
        return false;
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

    private function mimeToExtension(string $mime): string
    {
        return match ($mime) {
            'image/jpeg'                                                              => 'jpg',
            'image/png'                                                               => 'png',
            'image/gif'                                                               => 'gif',
            'image/webp'                                                              => 'webp',
            'image/svg+xml'                                                           => 'svg',
            'image/bmp'                                                               => 'bmp',
            'image/tiff'                                                              => 'tiff',
            'application/pdf'                                                         => 'pdf',
            'application/zip'                                                         => 'zip',
            'application/gzip'                                                        => 'gz',
            'application/json'                                                        => 'json',
            'application/xml', 'text/xml'                                             => 'xml',
            'application/msword'                                                      => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel'                                                => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'       => 'xlsx',
            'text/plain'                                                              => 'txt',
            'text/csv'                                                                => 'csv',
            'text/html'                                                               => 'html',
            'audio/mpeg'                                                              => 'mp3',
            'video/mp4'                                                               => 'mp4',
            default                                                                   => '',
        };
    }

    /**
     * Обратное к mimeToExtension для текстовых форматов, которые finfo не различает
     * (видит как text/plain). Возвращает '' если уточнения нет.
     */
    private function extensionToMime(string $ext): string
    {
        return match (strtolower($ext)) {
            'csv'            => 'text/csv',
            'tsv'            => 'text/tab-separated-values',
            'json'           => 'application/json',
            'xml'            => 'application/xml',
            'md', 'markdown' => 'text/markdown',
            'html', 'htm'    => 'text/html',
            'yaml', 'yml'    => 'application/yaml',
            'css'            => 'text/css',
            'js', 'mjs'      => 'text/javascript',
            default          => '',
        };
    }

    /**
     * Гарантирует, что имя оканчивается на корректное (по контенту) расширение.
     * Если уже оканчивается на него — оставляем (db.sql + sql → db.sql).
     * Иначе дописываем (db.sq + sql → db.sq.sql; report + csv → report.csv).
     * Пустой $ext (контент не распознан) — no-op.
     */
    private function ensureExtension(string $name, string $ext): string
    {
        if ($ext === '') {
            return $name;
        }
        if (strtolower($this->extractExtension($name)) === strtolower($ext)) {
            return $name;
        }
        return $name . '.' . $ext;
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

    public function getOne(string $instanceId, string $id, string $baseUrl): FileRes
    {
        $record = $this->findRecord($instanceId, $id);
        $blob = $this->findBlob($record->blob_id);
        return $this->buildRes($instanceId, $record, $blob, deduplicated: false, baseUrl: $baseUrl);
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

    public function getAll(string $instanceId, FileListRequest $request, string $baseUrl): WrapResult
    {
        $where = [Qb::eq('instance_id', $instanceId)];
        $where[] = $request->section !== null
            ? Qb::eq('section', $request->section)
            : Qb::isNull('section');
        if ($request->search) {
            $where[] = Qb::like('name', "%{$request->search}%");
        }
        $this->recordRepo->where(Qb::and(...$where));

        $page = Wrapper::paginator(
            $this->recordRepo,
            $request->limit,
            $request->page,
        );

        if ($page->data === []) {
            return $page;
        }

        // Batch-load blob'ов одним IN-запросом вместо N findBlob() в маппере.
        // array_unique — потому что несколько FileRecord могут ссылаться на один
        // и тот же FileBlob (дедупликация по hash).
        $blobIds = array_values(array_unique(
            array_map(fn(FileRecord $r) => $r->blob_id, $page->data)
        ));
        $blobMap = $this->loadBlobsByIds($blobIds);

        return new WrapResult(
            meta: $page->meta,
            data: array_map(
                fn(FileRecord $r) => $this->buildRes(
                    $instanceId,
                    $r,
                    $blobMap[$r->blob_id]
                        ?? throw new \RuntimeException("Blob missing for record {$r->id}"),
                    false,
                    $baseUrl,
                ),
                $page->data,
            ),
        );
    }

    /**
     * @param string[] $ids
     * @return array<string, FileBlob>
     */
    private function loadBlobsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $this->blobRepo->where(Qb::in('id', $ids));
        $blobs = $this->blobRepo->findAll();
        $map = [];
        foreach ($blobs as $b) {
            $map[$b->id] = $b;
        }
        return $map;
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

    private function buildRes(
        string $instanceId,
        FileRecord $record,
        FileBlob $blob,
        bool $deduplicated,
        string $baseUrl,
    ): FileRes {
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
            url: $this->buildUrl($instanceId, $record->section, $record->id, $baseUrl),
        );
    }

    /**
     * URL для запроса файла обратно. Клиент должен слать Bearer токен.
     *   root:    {baseUrl}/media/{id}
     *   section: {baseUrl}/media/{section}/{id}
     * Под admin-mode (path-based scoping) добавляется префикс /s4w/instances/{id}.
     */
    private function buildUrl(string $instanceId, ?string $section, string $id, string $baseUrl): string
    {
        $base = rtrim($baseUrl, '/');
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
