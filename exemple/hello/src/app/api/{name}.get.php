<?php

declare(strict_types=1);

use Next\Router\Router;

$name = $params['name'] ?? 'guest';

Router::json([
    'message' => "Hello, $name",
]);
