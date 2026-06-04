<?php

namespace Main\Controllers;

use Flytachi\Winter\K2\Http\Adapter\FpmRequest;
use Flytachi\Winter\K2\Http\Contracts\HttpRequest;
use Flytachi\Winter\K2\Http\Header;
use Flytachi\Winter\K2\Http\Request\Annotation\RequestJson;
use Flytachi\Winter\K2\Http\Request\Validation\Valid;
use Flytachi\Winter\K2\Http\Response\ResponseEntity;
use Flytachi\Winter\K2\Route\Annotation\GetMapping;
use Flytachi\Winter\K2\Route\Annotation\PostMapping;
use Flytachi\Winter\K2\Route\Annotation\RequestMapping;
use Flytachi\Winter\K2\Stereotype\Controller;
use Main\Controllers\Middlewares\DefaultMiddleware;
use Main\Requests\LoginRequest;
use Main\Services\AuthService;

#[RequestMapping('s4w/auth')]
class AuthController extends Controller
{
    #[DefaultMiddleware]
    #[PostMapping]
    public function login(
        #[RequestJson, Valid] LoginRequest $login,
        HttpRequest $request
    ): ResponseEntity {
        return ResponseEntity::ok(
            (new AuthService)->login(
                $request->getBaseUrl(),
                $login
            )
        );
    }

    #[GetMapping]
    public function logout(): ResponseEntity
    {
        return ResponseEntity::ok();
    }
}
