<?php

namespace Main\Controllers;

use Flytachi\Winter\DI\Attribute\Autowired;
use Flytachi\Winter\K2\Http\Contracts\HttpRequest;
use Flytachi\Winter\K2\Http\Request\Annotation\PathVariable;
use Flytachi\Winter\K2\Http\Request\Annotation\RequestFile;
use Flytachi\Winter\K2\Http\Request\Annotation\RequestForm;
use Flytachi\Winter\K2\Http\Request\Annotation\RequestJson;
use Flytachi\Winter\K2\Http\Request\Annotation\RequestQuery;
use Flytachi\Winter\K2\Http\Request\Validation\Uuid;
use Flytachi\Winter\K2\Http\Request\Validation\Valid;
use Flytachi\Winter\K2\Http\Response\ResponseEntity;
use Flytachi\Winter\K2\Route\Annotation\DeleteMapping;
use Flytachi\Winter\K2\Route\Annotation\GetMapping;
use Flytachi\Winter\K2\Route\Annotation\PatchMapping;
use Flytachi\Winter\K2\Route\Annotation\PostMapping;
use Flytachi\Winter\K2\Route\Annotation\RequestMapping;
use Flytachi\Winter\K2\Stereotype\Controller;
use Main\Controllers\Middlewares\AuthMiddleware;
use Main\Requests\File\FileListRequest;
use Main\Requests\File\FileMoveRequest;
use Main\Requests\File\FileRenameRequest;
use Main\Requests\File\FileRequest;
use Main\Requests\File\SectionCreateRequest;
use Main\Requests\File\SectionRenameRequest;
use Main\Requests\File\SectionVisibilityRequest;
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

    #[PatchMapping('sections')]
    public function renameSection(
        #[PathVariable, Uuid] string $instanceId,
        #[RequestJson, Valid] SectionRenameRequest $request,
    ): ResponseEntity {
        $this->service->adminModeOn();
        $this->service->renameSection($instanceId, $request->from, $request->to);
        return ResponseEntity::accepted();
    }

    #[PostMapping('sections')]
    public function createSection(
        #[PathVariable, Uuid] string $instanceId,
        #[RequestJson, Valid] SectionCreateRequest $request,
    ): ResponseEntity {
        $this->service->adminModeOn();
        $this->service->createSection($instanceId, $request->name, $request->public);
        return ResponseEntity::created();
    }

    #[PatchMapping('sections/visibility')]
    public function setSectionVisibility(
        #[PathVariable, Uuid] string $instanceId,
        #[RequestJson, Valid] SectionVisibilityRequest $request,
    ): ResponseEntity {
        $this->service->adminModeOn();
        $this->service->setSectionVisibility($instanceId, $request->section, $request->public);
        return ResponseEntity::accepted();
    }

    #[DeleteMapping('sections/{section}')]
    public function deleteSection(
        #[PathVariable, Uuid] string $instanceId,
        #[PathVariable] string $section,
    ): ResponseEntity {
        $this->service->adminModeOn();
        $this->service->deleteSection($instanceId, $section);
        return ResponseEntity::accepted();
    }

    #[PatchMapping('{id}/rename')]
    public function rename(
        #[PathVariable, Uuid] string $instanceId,
        #[PathVariable, Uuid] string $id,
        #[RequestJson, Valid] FileRenameRequest $request,
        HttpRequest $http,
    ): ResponseEntity {
        $this->service->adminModeOn();
        return ResponseEntity::ok(
            $this->service->rename($instanceId, $id, $request->name, $http->getBaseUrl())
        );
    }

    #[PatchMapping('{id}/move')]
    public function move(
        #[PathVariable, Uuid] string $instanceId,
        #[PathVariable, Uuid] string $id,
        #[RequestJson, Valid] FileMoveRequest $request,
        HttpRequest $http,
    ): ResponseEntity {
        $this->service->adminModeOn();
        return ResponseEntity::ok(
            $this->service->move($instanceId, $id, $request->section, $http->getBaseUrl())
        );
    }
}
