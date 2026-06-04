<?php


use Flytachi\Winter\Base\HttpCode;
use Flytachi\Winter\K2\Http\Response\ResponseEntity;
use Flytachi\Winter\K2\Http\Response\ResponseView;
use Flytachi\Winter\K2\Route\Annotation\GetMapping;
use Flytachi\Winter\K2\Route\Annotation\RequestMapping;
use Flytachi\Winter\K2\Stereotype\Controller;

#[RequestMapping('web/auth')]
class AuthController extends Controller
{
    #[GetMapping]
    public function loginPage(): ResponseView
    {
        return ResponseView::view('auth/login');
    }

    #[GetMapping('logout')]
    public function logout(): ResponseEntity
    {
        return ResponseEntity::status(HttpCode::SEE_OTHER)
            ->header('Location', '/web/auth')
            ->header('Set-Cookie', 's4w_token=; Max-Age=0; Path=/; HttpOnly; SameSite=Lax');
    }
}
