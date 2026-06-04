<?php

namespace Main\Controllers;

use Flytachi\Winter\DI\Attribute\Autowired;
use Flytachi\Winter\K2\Http\Request\Annotation\PathVariable;
use Flytachi\Winter\K2\Http\Request\Annotation\RequestJson;
use Flytachi\Winter\K2\Http\Request\Annotation\RequestQuery;
use Flytachi\Winter\K2\Http\Request\Validation\Uuid;
use Flytachi\Winter\K2\Http\Request\Validation\Valid;
use Flytachi\Winter\K2\Http\Response\ResponseEntity;
use Flytachi\Winter\K2\Route\Annotation\DeleteMapping;
use Flytachi\Winter\K2\Route\Annotation\GetMapping;
use Flytachi\Winter\K2\Route\Annotation\PostMapping;
use Flytachi\Winter\K2\Route\Annotation\PutMapping;
use Flytachi\Winter\K2\Route\Annotation\RequestMapping;
use Flytachi\Winter\K2\Stereotype\Controller;
use Main\Controllers\Middlewares\AuthMiddleware;
use Main\Requests\Instance\InstanceRequest;
use Main\Requests\ListRequest;
use Main\Services\InstanceService;

#[AuthMiddleware]
#[RequestMapping('s4w/instances')]
class InstanceController extends Controller
{
    #[Autowired]
    private InstanceService $service;

    #[GetMapping]
    public function list(
        #[RequestQuery, Valid] ListRequest $request
    ): ResponseEntity {
        return ResponseEntity::ok(
            $this->service->getAll($request)
        );
    }

    #[PostMapping]
    public function create(
        #[RequestJson, Valid] InstanceRequest $request
    ): ResponseEntity {
        $this->service->create($request);
        return ResponseEntity::accepted();
    }

    #[GetMapping('{id}')]
    public function get(
        #[PathVariable, Uuid] string $id
    ): ResponseEntity {
        return ResponseEntity::ok(
            $this->service->getObject($id)
        );
    }

    #[PutMapping('{id}')]
    public function update(
        #[PathVariable, Uuid] string $id,
        #[RequestJson, Valid] InstanceRequest $request
    ): ResponseEntity {
        $this->service->update($id, $request);
        return ResponseEntity::accepted();
    }

    #[DeleteMapping('{id}')]
    public function delete(
        #[PathVariable, Uuid] string $id
    ): ResponseEntity {
        $this->service->delete($id);
        return ResponseEntity::accepted();
    }
}
