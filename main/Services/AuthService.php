<?php

namespace Main\Services;

use Flytachi\Jwt\Entity\JwtPayload;
use Flytachi\Jwt\Entity\PrivateKey;
use Flytachi\Jwt\JWT;
use Flytachi\Winter\Base\HttpCode;
use Flytachi\Winter\K2\Exception\ClientError;
use Flytachi\Winter\K2\Http\Response\ResponseException;
use Flytachi\Winter\K2\Kernel;
use Flytachi\Winter\K2\Stereotype\Service;
use Main\Requests\LoginRequest;

class AuthService extends Service
{
    // Анти-брутфорс логина: окно и порог неудачных попыток на один IP.
    private const string ATTEMPTS_STORE = 's4w.login_attempts';
    private const int MAX_ATTEMPTS = 5;
    private const int WINDOW = 900; // 15 минут

    public function login(string $host, LoginRequest $login, string $clientIp): array
    {
        $this->assertNotThrottled($clientIp);

        if (!$this->adminValidate($login->username, $login->password)) {
            $this->registerFailure($clientIp);
            ClientError::throw('Invalid login or password');
        }

        $this->clearAttempts($clientIp);

        $jwt = JWT::encode(
            new JwtPayload([
                'iss' => $host,
                'sub' => [
                    'user' => $login->username,
                ],
                'aud' => $host . '/api/auth',
                'iat' => time(),
                'nbf' => time(),
                'exp' => time() + 10800,
            ]),
            new PrivateKey(env('WINTER_KEY', ''), 'HS256')
        );

        return [
            'token' => $jwt,
        ];
    }

    private function adminValidate(string $user, string $pass): bool
    {
        $expectedUser = (string) env('ADMIN_LOGIN', '');
        $expectedPass = (string) env('ADMIN_PASSWORD', '');

        // Креды не настроены → доступа в web нет (fail-closed). Иначе пустой
        // ADMIN_PASSWORD пустил бы любого с пустым паролем.
        if ($expectedUser === '' || $expectedPass === '') {
            return false;
        }

        // hash_equals — сравнение за константное время (защита от timing-атак).
        return hash_equals($expectedUser, $user) && hash_equals($expectedPass, $pass);
    }

    // ── Login throttle ─────────────────────────────────────────────────────
    //
    // Счётчик неудач на IP в файловом сторе (Kernel::store) с TTL = окну.
    // Ключуем по REMOTE_ADDR (контроллер передаёт его) — не по X-Forwarded-For,
    // который подделывается клиентом. Read-modify-write не атомарен; под высокой
    // параллельностью пара лишних попыток может проскочить — для анти-брутфорса
    // некритично (для строгой атомарности — Redis INCR, ext уже в зависимостях).

    private function assertNotThrottled(string $clientIp): void
    {
        [$count, $expiry] = $this->readAttempts($clientIp);
        if ($count >= self::MAX_ATTEMPTS && $expiry > time()) {
            throw (new ResponseException(
                'Too many login attempts. Try again later.',
                HttpCode::TOO_MANY_REQUESTS,
            ))->withHeader('Retry-After', (string) max(1, $expiry - time()));
        }
    }

    private function registerFailure(string $clientIp): void
    {
        $now = time();
        [$count, $expiry] = $this->readAttempts($clientIp);
        if ($expiry <= $now) {
            // окно истекло — начинаем новое
            $count = 0;
            $expiry = $now + self::WINDOW;
        }
        $count++;
        $this->store()->write($this->key($clientIp), $count . ':' . $expiry, $expiry);
    }

    private function clearAttempts(string $clientIp): void
    {
        $this->store()->del($this->key($clientIp));
    }

    /**
     * @return array{0:int,1:int} [count, expiry]
     */
    private function readAttempts(string $clientIp): array
    {
        $raw = $this->store()->read($this->key($clientIp));
        if (!is_string($raw) || !str_contains($raw, ':')) {
            return [0, 0];
        }
        [$count, $expiry] = explode(':', $raw, 2);
        return [(int) $count, (int) $expiry];
    }

    private function key(string $clientIp): string
    {
        return 'ip_' . hash('sha256', $clientIp);
    }

    private function store(): \Flytachi\FileStore\FileStorage
    {
        return Kernel::store(self::ATTEMPTS_STORE);
    }
}
