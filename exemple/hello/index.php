<?php
declare(strict_types=1);

require __DIR__ . '/../../src/Router.php';

use Next\Router\Router;

$router = new Router(__DIR__ . '/src/app');
$router->start();