<?php

namespace Main\Controllers\Middlewares;

use Flytachi\Winter\Base\HttpCode;
use Flytachi\Winter\K2\Http\Contracts\HttpRequest;
use Flytachi\Winter\K2\Http\Contracts\HttpResponse;
use Flytachi\Winter\K2\Http\Header;
use Flytachi\Winter\K2\Http\Middleware\MiddlewareException;
use Flytachi\Winter\K2\Stereotype\Middleware;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
class DefaultMiddleware extends Middleware
{
    public function before(HttpRequest $request, HttpResponse $response): void
    {
        if (Header::getBearerToken() !== env('TOKEN', '')) {
            MiddlewareException::throw('Invalid token provided', HttpCode::UNAUTHORIZED);
        }
    }
}
