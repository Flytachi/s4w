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
use Flytachi\Winter\K2\Route\Annotation\PatchMapping;
use Flytachi\Winter\K2\Route\Annotation\PostMapping;
use Flytachi\Winter\K2\Route\Annotation\RequestMapping;
use Flytachi\Winter\K2\Stereotype\Controller;
use Main\Controllers\Middlewares\AuthMiddleware;
use Main\Dto\TokenStatus;
use Main\Requests\Instance\TokenRequest;
use Main\Requests\Instance\TokenValidateRequest;
use Main\Requests\ListRequest;
use Main\Services\InstanceTokenService;

#[AuthMiddleware]
#[RequestMapping('s4w/instances/{instanceId}/tokens')]
class InstanceTokenController extends Controller
{
    #[Autowired]
    private InstanceTokenService $service;

    // POST с токеном в теле, а не в query: иначе секрет утекает в access-логи
    // (nginx пишет $request с query-строкой), историю и прокси.
    #[PostMapping('validation')]
    public function validation(
        #[PathVariable, Uuid] string $instanceId,
        #[RequestJson, Valid] TokenValidateRequest $request,
    ): ResponseEntity {
        return ResponseEntity::ok(
            $this->service->validate($instanceId, $request->token)
        );
    }

    #[GetMapping]
    public function list(
        #[PathVariable, Uuid] string $instanceId,
        #[RequestQuery, Valid] ListRequest $request
    ): ResponseEntity {
        return ResponseEntity::ok(
            $this->service->getAll($instanceId, $request)
        );
    }

    #[PostMapping]
    public function create(
        #[PathVariable, Uuid] string $instanceId,
        #[RequestJson, Valid] TokenRequest $request,
    ): ResponseEntity {
        return ResponseEntity::ok(
            $this->service->create($instanceId, $request)
        );
    }

    #[PatchMapping('{id}')]
    public function regenerate(
        #[PathVariable, Uuid] string $instanceId,
        #[PathVariable, Uuid] string $id,
    ): ResponseEntity {
        return ResponseEntity::ok(
            $this->service->regenerate($instanceId, $id)
        );
    }

    #[PatchMapping('{id}/{status}')]
    public function change(
        #[PathVariable, Uuid] string $instanceId,
        #[PathVariable, Uuid] string $id,
        #[PathVariable] TokenStatus $status,
    ): ResponseEntity {
        $this->service->changeStatus($instanceId, $id, $status);
        return ResponseEntity::accepted();
    }

    #[DeleteMapping('{id}')]
    public function delete(
        #[PathVariable, Uuid] string $instanceId,
        #[PathVariable, Uuid] string $id,
    ): ResponseEntity {
        $this->service->delete($instanceId, $id);
        return ResponseEntity::accepted();
    }
}
