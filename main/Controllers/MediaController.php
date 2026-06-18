<?php

namespace Main\Controllers;

use Flytachi\Winter\DI\Attribute\Autowired;
use Flytachi\Winter\K2\Http\Contracts\HttpRequest;
use Flytachi\Winter\K2\Http\Request\Annotation\PathVariable;
use Flytachi\Winter\K2\Http\Request\Validation\Uuid;
use Flytachi\Winter\K2\Http\Response\ResponseFile;
use Flytachi\Winter\K2\Route\Annotation\GetMapping;
use Flytachi\Winter\K2\Route\Annotation\RequestMapping;
use Flytachi\Winter\K2\Stereotype\Controller;
use Main\Controllers\Middlewares\AuthMiddleware;
use Main\Services\MediaService;

#[AuthMiddleware]
#[RequestMapping('s4w/instances/{instanceId}/media')]
class MediaController extends Controller
{
    #[Autowired]
    private MediaService $service;

    #[GetMapping('{id}')]
    public function rootDownload(
        #[PathVariable, Uuid] string $instanceId,
        #[PathVariable, Uuid] string $id,
        HttpRequest $http,
    ): ResponseFile {
        return $this->service->downloadById(
            $instanceId, $id, self::isDownload($http)
        );
    }

    #[GetMapping('{section}/{id}')]
    public function sectionDownload(
        #[PathVariable, Uuid] string $instanceId,
        #[PathVariable] string $section,
        #[PathVariable, Uuid] string $id,
        HttpRequest $http,
    ): ResponseFile {
        return $this->service->downloadBySection(
            $instanceId, $section, $id, self::isDownload($http)
        );
    }

    private static function isDownload(HttpRequest $http): bool
    {
        return ($http->getQueryParams()['download'] ?? null) === '1';
    }
}
