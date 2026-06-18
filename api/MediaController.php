<?php

namespace Api;

use Flytachi\Winter\DI\Attribute\Autowired;
use Flytachi\Winter\K2\Http\Request\Annotation\PathVariable;
use Flytachi\Winter\K2\Http\Request\Validation\Uuid;
use Flytachi\Winter\K2\Http\Response\ResponseFile;
use Flytachi\Winter\K2\Route\Annotation\GetMapping;
use Flytachi\Winter\K2\Route\Annotation\RequestMapping;
use Flytachi\Winter\K2\Stereotype\Controller;
use Main\Services\MediaService;

// Приватная отдача (требует instance-токен): /p/{id}, /p/{section}/{id}.
// Публичная (root + публичные секции, без токена) — в PublicMediaController (/o).
#[InstanceTokenMiddleware]
#[RequestMapping('p')]
class MediaController extends Controller
{
    #[Autowired]
    private MediaService $service;

    #[Autowired]
    private AuthContext $auth;

    #[GetMapping('{id}')]
    public function rootDownload(
        #[PathVariable, Uuid] string $id,
    ): ResponseFile {
        return $this->service->downloadById(
            $this->auth->instanceId(), $id
        );
    }

    #[GetMapping('{section}/{id}')]
    public function sectionDownload(
        #[PathVariable] string $section,
        #[PathVariable, Uuid] string $id,
    ): ResponseFile {
        return $this->service->downloadBySection(
            $this->auth->instanceId(), $section, $id
        );
    }
}
