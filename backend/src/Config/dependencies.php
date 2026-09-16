<?php

declare(strict_types=1);

use App\Middleware\JwtAuthMiddleware;
use App\Services\DynamicAllocationEngine;
use App\Services\JwtService;
use App\Services\RecurringTransactionService;
use App\Services\SyncEncryptionService;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Slim\Psr7\Factory\ResponseFactory;

return [
    ResponseFactoryInterface::class => fn () => new ResponseFactory(),

    JwtService::class => fn () => new JwtService(),

    SyncEncryptionService::class => fn () => new SyncEncryptionService(),

    DynamicAllocationEngine::class => fn () => new DynamicAllocationEngine(),

    RecurringTransactionService::class => fn () => new RecurringTransactionService(),

    JwtAuthMiddleware::class => function (ContainerInterface $c) {
        return new JwtAuthMiddleware($c->get(JwtService::class), $c->get(ResponseFactoryInterface::class));
    },
];
