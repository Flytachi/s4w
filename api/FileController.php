<?php

namespace Api;

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
use Main\Requests\File\FileListRequest;
use Main\Requests\File\FileRequest;
use Main\Services\FileService;

#[InstanceTokenMiddleware]
#[RequestMapping('api/files')]
class FileController extends Controller
{
    #[Autowired]
    private FileService $service;

    #[Autowired]
    private AuthContext $auth;

    #[GetMapping('sections')]
    public function sectionList(): ResponseEntity
    {
        return ResponseEntity::ok([
            'list' => $this->service->listSections($this->auth->instanceId()),
        ]);
    }

    #[GetMapping]
    public function list(
        #[RequestQuery, Valid] FileListRequest $request,
        HttpRequest $http,
    ): ResponseEntity {
        return ResponseEntity::ok(
            $this->service->getAll($this->auth->instanceId(), $request, $http->getBaseUrl())
        );
    }

    #[PostMapping]
    public function create(
        #[RequestFile('file', maxSize: '100MB')] array $file,
        #[RequestForm, Valid] FileRequest $form,
        HttpRequest $http,
    ): ResponseEntity {
        return ResponseEntity::created(
            $this->service->upload(
                $this->auth->instanceId(),
                $file,
                $form,
                $http->getBaseUrl(),
            )
        );
    }

    #[GetMapping('{id}')]
    public function get(
        #[PathVariable, Uuid] string $id,
        HttpRequest $http,
    ): ResponseEntity {
        return ResponseEntity::ok(
            $this->service->getOne($this->auth->instanceId(), $id, $http->getBaseUrl())
        );
    }

    #[DeleteMapping('{id}')]
    public function delete(
        #[PathVariable, Uuid] string $id,
    ): ResponseEntity {
        $this->service->delete($this->auth->instanceId(), $id);
        return ResponseEntity::noContent();
    }
}
