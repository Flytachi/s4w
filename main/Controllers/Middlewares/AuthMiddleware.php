<?php

namespace Main\Controllers\Middlewares;

use Flytachi\Jwt\Entity\PublicKey;
use Flytachi\Jwt\JWT;
use Flytachi\Jwt\JWTException;
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
        $jwt = Header::getBearerToken();
        if (!$jwt) {
            MiddlewareException::throw('Invalid token provided');
        }

        try {
            $payload = JWT::decode($jwt,
                [new PublicKey(env('WINTER_KEY', ''), 'HS256')]
            );
            if (!$this->validateToken($payload->getClaim('sub'))) {
                MiddlewareException::throw('Invalid token data');
            }
        } catch (JWTException $e) {
            if (env('DEBUG', false)) {
                throw $e;
            } else {
                MiddlewareException::throw('Invalid token provided');
            }
        }
    }

    private function validateToken(array $sub): bool
    {
        return $sub['user'] === env('ADMIN_LOGIN');
    }
}
