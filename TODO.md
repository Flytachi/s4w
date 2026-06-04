# Отложенные правки по обзору main/ + io/

## #2 — Стрим в `MediaService::serve`

**Проблема.** `FileManager::blobRead` через `file_get_contents` грузит весь
файл в память. При лимите upload 100MB каждый GET держит до 100MB на воркер
PHP-FPM. Под нагрузкой — OOM.

**Решение.**
- Посмотреть API `Flytachi\Winter\K2\Http\Response\ResponseFile` — есть ли
  `::stream($path)` / `::fromPath()` / fpassthru-вариант.
- Если есть — заменить `binary(data: ...)` на стрим по пути
  `FileManager::blobPath($instanceId, $hash)`.
- Если нет — в проде делегировать nginx через `X-Accel-Redirect`: PHP
  выставляет заголовок `X-Accel-Redirect: /internal/chest/{id}/{hash}`,
  nginx-конфиг отдаёт файл сам. Контроль доступа остаётся в PHP, body —
  на nginx.

## #11 — `Algorithm::random(16)` для display-name без расширения

**Проблема.** В `FileService::upload` если `form.name` и `sourceName` пустые —
display-name генерится как `Algorithm::random(16)` без точки/расширения. UX:
скачивая файл, пользователь получает имя без `.jpg`/`.pdf`.

**Решение.** После определения mime — собрать имя
`Algorithm::random(16) . '.' . $this->mimeToExtension($mime)`. Учесть случай
пустого mimeToExtension (экзотика) — оставить без расширения.

## #12 — `MediaService` отдаёт `isAttachment: false` для всех mime

**Проблема.** Сейчас все файлы инлайн в браузере — нормально для image/pdf,
сомнительно для zip/json/csv (браузер попытается отрисовать как текст или
скачать молча).

**Решение.** Эвристика по mime:
- `image/*`, `application/pdf`, `text/html` → inline
- остальное → attachment (Content-Disposition: attachment)

Опционально — query-параметр `?download=1` принудительно ставит attachment
независимо от mime.

## Долгосрочное

### Cron для `OrphanFolderGc`
Поставить `./call script Io/Scripts/OrphanFolderGc` в cron раз в сутки.
Страховка от сбоев `InstanceDeleteJob` между DB-delete и rmdir.

### Orphan blob GC внутри папки инстанса
Файл в `storage/chest/{instanceId}/{hash}` без соответствующей строки в
`s4w_file_blobs`. Может образоваться при сбое `upload` между
`FileManager::blobWrite` и `INSERT FileBlob` (хотя текущий `casWritten`
rollback это покрывает в большинстве случаев). Аналогично `OrphanFolderGc`,
но на уровень глубже.

### #6 — `JWT_SECRET` без fail-fast (пропущено сознательно)
В `AuthService::login` и `AuthMiddleware::before` сейчас
`env('JWT_SECRET', '')` — пустой fallback. Если переменная не задана,
HS256 работает с пустым ключом → токены тривиально подделываются.

Решение: `envRequired($key)` helper, который throw'ит при отсутствии;
плюс boot-time проверка списка критичных env'ов в `Boot::configure()`.

## Замечания, которые пока без решения

- **`AuthMiddleware::validateToken`** теперь проверяет только
  `sub.user === ADMIN_LOGIN`. Когда появится multi-user — нужна таблица
  пользователей и lookup.
- **`Algorithm::random(16)`** — 16 байт = 32 hex chars. Энтропии хватает,
  но это display-name, не security-critical. Менять не надо.
