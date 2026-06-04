<?php


use Flytachi\Winter\K2\Http\Response\ResponseView;
use Flytachi\Winter\K2\Route\Annotation\GetMapping;
use Flytachi\Winter\K2\Route\Annotation\RequestMapping;
use Flytachi\Winter\K2\Stereotype\Controller;

#[RequestMapping('web')]
class MainController extends Controller
{
    #[GetMapping('main')]
    public function index(): ResponseView
    {
        return $this->page('main/index');
    }

    #[GetMapping('storages')]
    public function storage(): ResponseView
    {
        return $this->page('storages/index');
    }

    #[GetMapping('analytics')]
    public function analytic(): ResponseView
    {
        return $this->page('analytics/index');
    }

    #[GetMapping('clients')]
    public function client(): ResponseView
    {
        return $this->page('clients/index');
    }

    #[GetMapping('files')]
    public function file(): ResponseView
    {
        return $this->page('files/index');
    }

    private function page(string $view): ResponseView
    {
        return ResponseView::render('layouts/index', $view);
    }
}
