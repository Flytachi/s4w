<?php

namespace Main\Services;

use Flytachi\FileStore\FileStorage;
use Flytachi\Winter\K2\Kernel;

/**
 * Кэш метаданных отдачи файлов (FileStore) для MediaService.
 *
 * Зачем: горячий путь отдачи (/o, /p, admin media) на каждый запрос делает два
 * SELECT'а (FileRecord + FileBlob). Метаданные меняются редко, читаются часто —
 * кэшируем то, что нужно serve()'у, и снимаем нагрузку с пула БД (max 5 коннектов).
 *
 * Инвалидация — версионированием ключа, НЕ точечным удалением:
 *   ключ записи = r_{instanceId}_{version}_{id}, version — счётчик на инстанс.
 *   Любая мутация метаданных инстанса (delete/rename/move/visibility/deleteSection)
 *   делает invalidate() → version++. Все ранее закэшированные записи (со старой
 *   версией в ключе) мгновенно становятся недостижимыми и тихо истекают по TTL.
 *
 * Почему так, а не del() по id: бамп версии — O(1), не требует перечисления id
 * секции при смене видимости, и его НЕЛЬЗЯ «пропустить» частично (один write
 * вместо N). Это критично для безопасности: пропущенная инвалидация при
 * public→private или delete означала бы отдачу приватного/удалённого файла через
 * /o до истечения TTL. TTL здесь — лишь верхняя граница (blast radius) на случай
 * сбоя записи версии, а не основной механизм консистентности.
 */
final class MediaCache
{
    private const string STORE = 's4w.media';

    private const int TTL = 10800; // 3 часа

    private function store(): FileStorage
    {
        return Kernel::store(self::STORE);
    }

    /**
     * Текущая версия кэша инстанса. 0 — ещё не инициализирована (всё мимо кэша
     * считается с нуля). Хранится без TTL (переживает TTL записей); сбрасывается
     * только полной очисткой стора — тогда и записи сброшены, рассинхрона нет.
     */
    public function version(string $instanceId): int
    {
        $v = $this->store()->read($this->verKey($instanceId));
        return is_int($v) ? $v : 0;
    }

    /**
     * Инвалидация всего media-кэша инстанса: version++. Best-effort — сбой записи
     * не должен ронять уже закоммиченную мутацию; TTL записей бэкстопит staleness.
     */
    public function invalidate(string $instanceId): void
    {
        try {
            $next = $this->version($instanceId) + 1; // всегда ≥1: FileStore не пишет empty(0)
            $this->store()->write($this->verKey($instanceId), $next); // без TTL — персистентна
        } catch (\Throwable) {
            // глушим намеренно: устаревание ограничено TTL записи (см. класс-док)
        }
    }

    /**
     * @return array<string,mixed>|null закэшированные метаданные отдачи или null
     */
    public function get(string $instanceId, int $version, string $id): ?array
    {
        try {
            $hit = $this->store()->read($this->recKey($instanceId, $version, $id));
        } catch (\Throwable) {
            return null;
        }
        return is_array($hit) ? $hit : null;
    }

    /**
     * @param array<string,mixed> $meta
     */
    public function put(string $instanceId, int $version, string $id, array $meta): void
    {
        try {
            $this->store()->write(
                $this->recKey($instanceId, $version, $id),
                $meta,
                time() + self::TTL,
            );
        } catch (\Throwable) {
            // кэш — оптимизация; промах записи лишь означает следующий читающий
            // запрос снова сходит в БД
        }
    }

    private function verKey(string $instanceId): string
    {
        return 'v_' . $instanceId;
    }

    private function recKey(string $instanceId, int $version, string $id): string
    {
        return 'r_' . $instanceId . '_' . $version . '_' . $id;
    }
}
