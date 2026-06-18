<?php

namespace Main\Controllers\Middlewares;

use Flytachi\Jwt\Entity\PublicKey;
use Flytachi\Jwt\JWT;
use Flytachi\Jwt\JWTException;
use Flytachi\Winter\Base\HttpCode;
use Flytachi\Winter\K2\Http\Contracts\HttpRequest;
use Flytachi\Winter\K2\Http\Contracts\HttpResponse;
use Flytachi\Winter\K2\Http\Header;
use Flytachi\Winter\K2\Http\Middleware\MiddlewareException;
use Flytachi\Winter\K2\Stereotype\Middleware;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
class AuthMiddleware extends Middleware
{
    public function before(HttpRequest $request, HttpResponse $response): void
    {
        // Любая проблема с токеном (нет / невалиден / протух) → 401, чтобы фронт
        // редиректил на логин. MiddlewareException::throw без кода даёт 400
        // (httpCode=0 → дефолт 400), поэтому 401 передаём явно.
        $jwt = Header::getBearerToken();
        if (!$jwt) {
            MiddlewareException::throw('Invalid token provided', HttpCode::UNAUTHORIZED);
        }

        try {
            $payload = JWT::decode($jwt,
                [new PublicKey(env('WINTER_KEY', ''), 'HS256')]
            );
            if (!$this->validateToken($payload->getClaim('sub'))) {
                MiddlewareException::throw('Invalid token data', HttpCode::UNAUTHORIZED);
            }
        } catch (JWTException $e) {
            // Протухший/битый JWT — это 401, а не 500. $e сохраняем как previous
            // для отладочного лога, но клиенту отдаём корректный статус.
            MiddlewareException::throw('Invalid token provided', HttpCode::UNAUTHORIZED, $e);
        }
    }

    private function validateToken(array $sub): bool
    {
        return $sub['user'] === env('ADMIN_LOGIN');
    }
}
