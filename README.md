# PHP Next Router

Roteador de arquivos para PHP inspirado no sistema de rotas do Next.js.

A ideia principal e simples:

- cada arquivo `.php` dentro de uma pasta base vira uma rota;
- nomes dinâmicos com `{param}` geram parâmetros de rota;
- endpoints API em `/api/*` são resolvidos por método HTTP usando sufixo no nome do arquivo.

## Recursos

- Roteamento por convenção de arquivos
- Rotas dinâmicas (`{id}`, `{slug}` etc.)
- Fallback `404.php`
- API REST por método (`.get.php`, `.post.php`, ...)
- Resposta automática `405` quando o endpoint existe, mas o método não
- Middlewares globais e por rota (`use`, `group`, `exact`, `file`)
- Bootstrap de grupos de API com CORS, auth, contexto e preflight (`apiGroup`)
- Suporte a `basePath` (ex.: app servindo em `/portal`)

## Requisitos

- PHP 8.0+ (recomendado)

## Instalação

Via Composer:

```bash
composer require next/router
```

Ou em desenvolvimento local, referencie a classe diretamente no seu projeto.

## Estrutura de app

Exemplo de estrutura:

```txt
src/
  app/
	index.php
	404.php
	about.php
	users/
	  index.php
	  {id}.php
```

Mapeamento:

- `/` -> `index.php`
- `/about` -> `about.php`
- `/users` -> `users/index.php`
- `/users/123` -> `users/{id}.php` (com `$params['id'] === '123'`)

## Estrutura de API

Dentro da pasta `app`, use o segmento `api` para endpoints REST.

```txt
src/
  app/
	api/
	  users.get.php
	  users.post.php
	  users/
		{id}.get.php
		{id}.put.php
		{id}.delete.php
```

Mapeamento:

- `GET /api/users` -> `api/users.get.php`
- `POST /api/users` -> `api/users.post.php`
- `GET /api/users/10` -> `api/users/{id}.get.php`
- `PUT /api/users/10` -> `api/users/{id}.put.php`

Métodos suportados:

- `GET`, `POST`, `PUT`, `DELETE`, `PATCH`, `OPTIONS`, `HEAD`

Se o endpoint existe, mas o método não, o router responde `405` com JSON e header `Allow`.

## Uso básico

```php
<?php
declare(strict_types=1);

require __DIR__ . '/src/Router.php';

use Next\Router\Router;

$router = new Router(__DIR__ . '/src/app');
$router->start();
```

Com `basePath` (ex.: aplicação acessada em `/portal`):

```php
$router = new Router(__DIR__ . '/src/app', '/portal');
$router->start();
```

## Variáveis disponíveis nos handlers

Nos arquivos de app/API incluídos pelo router, você pode usar:

- `$params` -> parâmetros dinâmicos da rota
- `$query` -> querystring parseada
- `$ctx` -> contexto completo (path, método, arquivo, flags de API etc.)

Exemplo (`src/app/users/{id}.php`):

```php
<?php
echo 'User ID: ' . ($params['id'] ?? '');
```

Exemplo API (`src/app/api/users/{id}.get.php`):

```php
<?php

use Next\Router\Router;

Router::json([
	'id' => $params['id'] ?? null,
	'query' => $query,
]);
```

## Middlewares

Assinatura esperada:

```php
function (array $ctx, callable $next) {
	// antes
	$result = $next($ctx);
	// depois
	return $result;
}
```

Exemplo:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/src/Router.php';

use Next\Router\Router;

$router = new Router(__DIR__ . '/src/app');

// Global
$router->use(function (array $ctx, callable $next) {
	return $next($ctx);
});

// Prefixo de rota
$router->group('/admin', function (array $ctx, callable $next) {
	$isLogged = true;
	if (!$isLogged) {
		Router::abort(401, 'Unauthorized');
	}
	return $next($ctx);
});

// Rota exata
$router->exact('/login', function (array $ctx, callable $next) {
	return $next($ctx);
});

// Arquivo específico
$router->file('dashboard.php', function (array $ctx, callable $next) {
	return $next($ctx);
});

$router->start();
```

## apiGroup (CORS, auth, contexto)

Use `apiGroup` para configurar um prefixo de API com comportamento comum.

```php
$router->apiGroup('/api/admin', [
	'cors' => [
		'allow_origin_same' => true,
		'allow_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'],
		'allow_headers' => ['Content-Type', 'Authorization', 'X-Requested-With'],
		'allow_credentials' => true,
	],
	'auth' => function (array $ctx): void {
		$token = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
		if (!$token) {
			Router::json(['error' => 'unauthorized'], 401);
		}
	},
	'context' => function (array $ctx): array {
		return ['request_id' => bin2hex(random_bytes(8))];
	},
	'preflight' => true,
]);
```

## Helpers úteis

- `Router::json(array $data, int $status = 200): void`
- `Router::abort(int $status = 403, string $message = 'Forbidden'): void`
- `Router::redirect(string $to, int $status = 302): void`

## Exemplo no repositório

Existe um exemplo funcional em `exemple/hello` com estrutura em `src/app` e arquivo `web.config` para reescrita no IIS.

## Licença

MIT
