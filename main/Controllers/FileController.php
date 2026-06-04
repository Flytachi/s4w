<?php

namespace Main\Controllers;

use Flytachi\Winter\DI\Attribute\Autowired;
use Flytachi\Winter\K2\Http\Contracts\HttpRequest;
use Flytachi\Winter\K2\Http\Request\Annotation\PathVariable;
use Flytachi\Winter\K2\Http\Request\Annotation\RequestFile;
use Flytachi\Winter\K2\Http\Request\Annotation\RequestForm;
use Flytachi\Winter\K2\Http\Request\Annotation\RequestQuery;
use Flytachi\Winter\K2\Http\Request\Validation\Uuid;
use Flytachi\Winter\K2\Http\Request\Validation\Valid;
use Flytachi\Winter\K2\Http\Response\ResponseEntity;
use Flytachi\Winter\K2\Route\Annotation\DeleteMapping;
use Flytachi\Winter\K2\Route\Annotation\GetMapping;
use Flytachi\Winter\K2\Route\Annotation\PostMapping;
use Flytachi\Winter\K2\Route\Annotation\RequestMapping;
use Flytachi\Winter\K2\Stereotype\Controller;
use Main\Controllers\Middlewares\AuthMiddleware;
use Main\Requests\File\FileListRequest;
use Main\Requests\File\FileRequest;
use Main\Services\FileService;

#[AuthMiddleware]
#[RequestMapping('s4w/instances/{instanceId}/files')]
class FileController extends Controller
{
    #[Autowired]
    private FileService $service;

    #[GetMapping('sections')]
    public function sectionList(
        #[PathVariable, Uuid] string $instanceId
    ): ResponseEntity {
        $this->service->adminModeOn();
        return ResponseEntity::ok([
            'list' => $this->service->listSections($instanceId),
        ]);
    }

    #[GetMapping]
    public function list(
        #[PathVariable, Uuid] string $instanceId,
        #[RequestQuery, Valid] FileListRequest $request,
        HttpRequest $http,
    ): ResponseEntity {
        $this->service->adminModeOn();
        return ResponseEntity::ok(
            $this->service->getAll($instanceId, $request, $http->getBaseUrl())
        );
    }

    #[PostMapping]
    public function create(
        #[PathVariable, Uuid] string $instanceId,
        #[RequestFile('file')] array $file,
        #[RequestForm, Valid] FileRequest $form,
        HttpRequest $http,
    ): ResponseEntity {
        $this->service->adminModeOn();
        return ResponseEntity::created(
            $this->service->upload(
                $instanceId,
                $file,
                $form,
                $http->getBaseUrl(),
            )
        );
    }

    #[GetMapping('{id}')]
    public function get(
        #[PathVariable, Uuid] string $instanceId,
        #[PathVariable, Uuid] string $id,
        HttpRequest $http,
    ): ResponseEntity {
        $this->service->adminModeOn();
        return ResponseEntity::ok(
            $this->service->getOne($instanceId, $id, $http->getBaseUrl())
        );
    }

    #[DeleteMapping('{id}')]
    public function delete(
        #[PathVariable, Uuid] string $instanceId,
        #[PathVariable, Uuid] string $id,
    ): ResponseEntity {
        $this->service->adminModeOn();
        $this->service->delete($instanceId, $id);
        return ResponseEntity::noContent();
    }
}
