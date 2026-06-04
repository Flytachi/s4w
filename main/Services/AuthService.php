<?php

namespace Main\Services;

use Flytachi\Jwt\Entity\JwtPayload;
use Flytachi\Jwt\Entity\PrivateKey;
use Flytachi\Jwt\JWT;
use Flytachi\Winter\K2\Exception\ClientError;
use Flytachi\Winter\K2\Http\Header;
use Flytachi\Winter\K2\Stereotype\Service;
use Main\Requests\LoginRequest;

class AuthService extends Service
{
    public function login(LoginRequest $login): array
    {
        if (!$this->adminValidate($login->username, $login->password)) {
            ClientError::throw('Invalid login or password');
        }

        $host = 'https://localhost:8007';
        $jwt = JWT::encode(
            new JwtPayload([
                'iss' => $host,
                'sub' => [
                    'user' => $login->username,
                    'ip' => Header::getIpAddress(),
                    'agent' => Header::getUserAgent(),
                    'origin' => Header::getOrigin(),
                    'referer' => Header::getReferer(),
                ],
                'aud' => $host . '/api/auth',
                'iat' => time(),
                'nbf' => time(),
                'exp' => time() + 10800,
            ]),
            new PrivateKey(env('JWT_SECRET', ''), 'HS256')
        );

        return [
            'token' => $jwt,
        ];
    }

    private function adminValidate(string $user, string $pass): bool
    {
        return $user === env('ADMIN_LOGIN') && $pass === env('ADMIN_PASSWORD');
    }
}
