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

/**
 * Публичная отдача файлов БЕЗ авторизации: /o/{instanceId}/[section/]{id}.
 * Отдаёт только файлы с is_public = true (root-файлы и файлы публичных секций).
 * instanceId+id — два UUID (~244 бита), enumeration невозможен. Приватные файлы
 * здесь не доступны (404) — для них /p (токен) или админский путь.
 */
#[RequestMapping('o')]
class PublicMediaController extends Controller
{
    #[Autowired]
    private MediaService $service;

    #[GetMapping('{instanceId}/{id}')]
    public function rootDownload(
        #[PathVariable, Uuid] string $instanceId,
        #[PathVariable, Uuid] string $id,
    ): ResponseFile {
        return $this->service->downloadPublicById($instanceId, $id);
    }

    #[GetMapping('{instanceId}/{section}/{id}')]
    public function sectionDownload(
        #[PathVariable, Uuid] string $instanceId,
        #[PathVariable] string $section,
        #[PathVariable, Uuid] string $id,
    ): ResponseFile {
        return $this->service->downloadPublicBySection($instanceId, $section, $id);
    }
}
