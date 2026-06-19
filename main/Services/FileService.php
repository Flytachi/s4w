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
use Main\Entities\Section;
use Main\Repositories\FileBlobRepository;
use Main\Repositories\FileRecordRepository;
use Main\Repositories\InstanceRepository;
use Main\Repositories\SectionRepository;
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

    #[Autowired]
    private SectionRepository $sectionRepo;

    /**
     * Media-кэш отдачи (см. MediaCache). Инвалидируется при любой мутации
     * метаданных, влияющих на serve(): rename/move/visibility/delete/deleteSection.
     * upload НЕ инвалидирует — новый id не мог попасть в кэш.
     */
    #[Autowired]
    private MediaCache $cache;

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
        // root → публичный; в секции → наследует видимость секции (новой → приватная).
        $isPublic = $this->resolveVisibility($instanceId, $section);

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
            $record->is_public = $isPublic;
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
     * Секции инстанса с их видимостью: [{name, public}, ...].
     * Секция = строка на FileRecord; видимость = флаг её файлов (поддерживаем
     * консистентным). bool_or — на случай рассинхрона берём «публичная, если хоть
     * один файл публичный».
     */
    /**
     * Секции инстанса из таблицы s4w_sections: [{name, public}, ...].
     * Включая пустые (без файлов) — секции теперь самостоятельны.
     */
    public function listSections(string $instanceId): array
    {
        $this->sectionRepo
            ->select('name, is_public')
            ->where(Qb::eq('instance_id', $instanceId))
            ->orderBy('name');
        $rows = $this->sectionRepo->findAll();
        return array_map(
            fn($r) => ['name' => $r->name, 'public' => (bool) $r->is_public],
            $rows,
        );
    }

    /**
     * Создать секцию (папку). Может быть пустой. Видимость задаётся при создании.
     */
    public function createSection(string $instanceId, string $name, bool $isPublic): void
    {
        $now = date('Y-m-d H:i:s P');
        $section = new Section();
        $section->instance_id = $instanceId;
        $section->name = $name;
        $section->is_public = $isPublic;
        $section->created_at = $now;
        $section->updated_at = $now;
        try {
            $this->sectionRepo->insert($section);
        } catch (\Throwable $e) {
            if ($this->isUniqueViolation($e)) {
                ClientError::throw('Секция с таким именем уже есть', HttpCode::CONFLICT);
            }
            throw $e;
        }
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

        // запись удалена → её кэш отдачи недопустим (иначе /o отдаст удалённый файл)
        $this->cache->invalidate($instanceId);
    }

    /**
     * Переименование файла. UNIQUE(name|section,name) → при конфликте 409.
     */
    public function rename(string $instanceId, string $id, string $newName, string $baseUrl): FileRes
    {
        $record = $this->findRecord($instanceId, $id);
        if ($record->name !== $newName) {
            try {
                $this->recordRepo->update(
                    ['name' => $newName, 'updated_at' => date('Y-m-d H:i:s P')],
                    Qb::eq('id', $record->id),
                );
            } catch (\Throwable $e) {
                if ($this->isUniqueViolation($e)) {
                    ClientError::throw('Файл с таким именем здесь уже есть', HttpCode::CONFLICT);
                }
                throw $e;
            }
            $record->name = $newName;
            $this->cache->invalidate($instanceId); // кэш хранит имя для filename отдачи
        }
        return $this->buildRes($instanceId, $record, $this->findBlob($record->blob_id), false, $baseUrl);
    }

    /**
     * Перемещение файла между секциями. null/'' = корень. Конфликт имени в
     * целевой секции → 409.
     */
    public function move(string $instanceId, string $id, ?string $newSection, string $baseUrl): FileRes
    {
        $record = $this->findRecord($instanceId, $id);
        $target = ($newSection === '' || $newSection === null) ? null : $newSection;
        if ($record->section !== $target) {
            // Видимость следует за назначением: root → public, секция → её флаг.
            $isPublic = $this->resolveVisibility($instanceId, $target);
            try {
                $this->recordRepo->update(
                    [
                        'section' => $target,
                        'is_public' => $isPublic,
                        'updated_at' => date('Y-m-d H:i:s P'),
                    ],
                    Qb::eq('id', $record->id),
                );
            } catch (\Throwable $e) {
                if ($this->isUniqueViolation($e)) {
                    ClientError::throw('В целевой секции уже есть файл с таким именем', HttpCode::CONFLICT);
                }
                throw $e;
            }
            $record->section = $target;
            $record->is_public = $isPublic;
            // section и is_public кэшируются → старый кэш мог бы отдать файл по
            // прежней секции/видимости (в т.ч. приватный через /o)
            $this->cache->invalidate($instanceId);
        }
        return $this->buildRes($instanceId, $record, $this->findBlob($record->blob_id), false, $baseUrl);
    }

    /**
     * Переименование секции = массовый UPDATE всех записей инстанса с section=from.
     * Если в целевой секции уже есть одноимённые файлы — UNIQUE 409 (мерж запрещён).
     */
    public function renameSection(string $instanceId, string $from, string $to): void
    {
        if ($from === $to) {
            return;
        }
        $this->requireSection($instanceId, $from);
        $now = date('Y-m-d H:i:s P');
        try {
            $this->sectionRepo->update(
                ['name' => $to, 'updated_at' => $now],
                Qb::and(Qb::eq('instance_id', $instanceId), Qb::eq('name', $from)),
            );
        } catch (\Throwable $e) {
            if ($this->isUniqueViolation($e)) {
                ClientError::throw('Секция с таким именем уже есть', HttpCode::CONFLICT);
            }
            throw $e;
        }
        // переносим файлы в новое имя секции (в "to" файлов нет — секция была новой)
        $this->recordRepo->update(
            ['section' => $to, 'updated_at' => $now],
            Qb::and(Qb::eq('instance_id', $instanceId), Qb::eq('section', $from)),
        );
        // у файлов сменилось section → старый кэш дал бы 404 по новому имени секции
        $this->cache->invalidate($instanceId);
    }

    /**
     * Удаление секции: все файлы внутри (с GC через delete()) + строка секции.
     */
    public function deleteSection(string $instanceId, string $section): int
    {
        $this->recordRepo->where(Qb::and(
            Qb::eq('instance_id', $instanceId),
            Qb::eq('section', $section),
        ));
        $records = $this->recordRepo->findAll();
        $count = 0;
        foreach ($records as $record) {
            $this->delete($instanceId, $record->id);
            $count++;
        }
        $this->sectionRepo->delete(
            Qb::and(Qb::eq('instance_id', $instanceId), Qb::eq('name', $section)),
        );
        return $count;
    }

    /**
     * Переключить видимость секции: обновляем авторитетную строку секции + денорм.
     * копию is_public на всех её файлах (для быстрой отдачи /o).
     */
    public function setSectionVisibility(string $instanceId, string $section, bool $public): void
    {
        $this->requireSection($instanceId, $section);
        $now = date('Y-m-d H:i:s P');
        $this->sectionRepo->update(
            ['is_public' => $public, 'updated_at' => $now],
            Qb::and(Qb::eq('instance_id', $instanceId), Qb::eq('name', $section)),
        );
        $this->recordRepo->update(
            ['is_public' => $public, 'updated_at' => $now],
            Qb::and(Qb::eq('instance_id', $instanceId), Qb::eq('section', $section)),
        );
        // КРИТИЧНО: public→private должен мгновенно убрать файлы секции из /o.
        // Бамп версии разом обесценивает кэш всех файлов секции (без перечисления id).
        $this->cache->invalidate($instanceId);
    }

    /**
     * Видимость файла по назначению: root → public; секция → её авторитетный флаг.
     * Секция обязана существовать (строгий select, без автосоздания).
     */
    private function resolveVisibility(string $instanceId, ?string $section): bool
    {
        if ($section === null) {
            return true;
        }
        return $this->requireSection($instanceId, $section)->is_public;
    }

    /**
     * Находит секцию или бросает 422 (нет автосоздания).
     */
    private function requireSection(string $instanceId, string $name): Section
    {
        $this->sectionRepo->where(Qb::and(
            Qb::eq('instance_id', $instanceId),
            Qb::eq('name', $name),
        ));
        $section = $this->sectionRepo->find();
        if (!$section) {
            ClientError::throw("Секция «{$name}» не найдена", HttpCode::UNPROCESSABLE_ENTITY);
        }
        return $section;
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
        $isPublic = (bool) $record->is_public;
        return new FileRes(
            id: $record->id,
            name: $record->name,
            section: $record->section,
            size: $blob->size_bytes,
            mime: $blob->mime_type,
            extension: $blob->extension,
            hash: $blob->hash,
            deduplicated: $deduplicated,
            isPublic: $isPublic,
            createdAt: $record->created_at,
            privateUrl: $this->privateUrl(
                $instanceId, $record->section, $record->id, env('UPLOAD_HOST', $baseUrl)
            ),
            publicUrl: $isPublic ?
                $this->publicUrl($instanceId, $record->section, $record->id, env('UPLOAD_HOST', $baseUrl))
                : null,
        );
    }

    private function urlTail(?string $section, string $id): string
    {
        return $section === null ? $id : rawurlencode($section) . '/' . $id;
    }

    /**
     * Приватный URL (требует авторизацию):
     *   admin  → {baseUrl}/s4w/instances/{id}/media/[section/]{id}  (JWT)
     *   tenant → {baseUrl}/p/[section/]{id}                          (instance-токен)
     */
    private function privateUrl(string $instanceId, ?string $section, string $id, string $baseUrl): string
    {
        $base = rtrim($baseUrl, '/');
        $tail = $this->urlTail($section, $id);
        return $this->adminMode
            ? $base . '/s4w/instances/' . $instanceId . '/media/' . $tail
            : $base . '/p/' . $tail;
    }

    /**
     * Публичный URL (без токена): {baseUrl}/o/{instanceId}/[section/]{id}.
     * Возвращается только для публичных файлов (root + публичные секции).
     */
    private function publicUrl(string $instanceId, ?string $section, string $id, string $baseUrl): string
    {
        return rtrim($baseUrl, '/') . '/o/' . $instanceId . '/' . $this->urlTail($section, $id);
    }

    public function adminModeOn(): void
    {
        $this->adminMode = true;
    }
}
