<?php

namespace Main\Dto;

use Random\RandomException;

class TokenGenerator
{
    private const string PREFIX = 's4w_';
    private const int TOKEN_BYTES = 32; // 256 бит энтропии

    /**
     * Генерирует новый токен.
     *
     * @return array{token: string, hash: string}
     *   token — отдаётся клиенту ОДИН РАЗ (после этого восстановить нельзя)
     *   hash  — сохраняется в БД (Char(64))
     * @throws RandomException
     */
    public static function generate(): array
    {
        $random = bin2hex(random_bytes(self::TOKEN_BYTES));
        $token  = self::PREFIX . $random;
        $hash   = hash('sha256', $token);

        return [
            'token' => $token, // ~69 символов: "stor_" + 64 hex
            'hash'  => $hash,  // ровно 64 символа hex
        ];
    }

    /**
     * Хеширует входящий токен для поиска в БД.
     */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Безопасное сравнение хешей (защита от timing attacks).
     */
    public static function verify(string $token, string $storedHash): bool
    {
        return hash_equals($storedHash, self::hash($token));
    }
}