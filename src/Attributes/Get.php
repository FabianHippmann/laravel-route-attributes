<?php

namespace Spatie\RouteAttributes\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class Get extends Route
{
    public function __construct(
        public string $uri,
        public ?string $name = null,
        array|string $middleware = [],
    ) {
        parent::__construct(
            method: 'get',
            uri: $uri,
            name: $name,
            middleware: $middleware,
        );
    }
}
