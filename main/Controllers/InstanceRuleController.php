<?php

namespace Main\Controllers;

use Flytachi\Winter\DI\Attribute\Autowired;
use Flytachi\Winter\K2\Http\Response\ResponseEntity;
use Flytachi\Winter\K2\Route\Annotation\RequestMapping;
use Flytachi\Winter\K2\Stereotype\Controller;
use Main\Controllers\Middlewares\AuthMiddleware;
use Main\Services\InstanceRuleService;

#[AuthMiddleware]
#[RequestMapping('s4w/instances/{instanceId}/rules')]
class InstanceRuleController extends Controller
{
    #[Autowired]
    private InstanceRuleService $service;

    #[RequestMapping]
    public function hello(): ResponseEntity
    {
        return ResponseEntity::ok("hello");
    }
}
