<?php

namespace Spatie\RouteAttributes\Tests\TestClasses\Controllers\RouteAttribute;

use Spatie\RouteAttributes\Attributes\Route;

#[Route('get', 'my-invokable-singleaction-route')]
class SingleActionTestController
{
    public function __invoke()
    {
    }
}
