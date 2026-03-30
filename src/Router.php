<?php
declare(strict_types=1);

namespace Next\Router;

/**
 * # Router com suporte a App e API REST (`/app/api`)
 *
 * ## 1) App (default)
 *
 * Mantém o comportamento atual: resolve arquivos `.php` pelo path,
 * incluindo dinâmicos por diretório/arquivo:
 *
 * - `app/users/{id}.php` → `/users/123`
 * - `app/users/{id}/index.php` → `/users/123`
 *
 * ---
 *
 * ## 2) API REST (`/api/*`)
 *
 * Qualquer rota cujo path comece com `/api` é tratada como endpoint REST.
 *
 * ### Convenção de arquivos
 *
 * O método HTTP define o arquivo, sem aparecer na URL:
 *
 * - `GET     /api/user` → `app/api/user.get.php`
 * - `POST    /api/user` → `app/api/user.post.php`
 * - `PUT     /api/user` → `app/api/user.put.php`
 * - `DELETE  /api/user` → `app/api/user.delete.php`
 * - `PATCH   /api/user` → `app/api/user.patch.php`
 * - `OPTIONS /api/user` → `app/api/user.options.php`
 * - `HEAD    /api/user` → `app/api/user.head.php`
 *
 * ### Dinâmico (mesmo padrão das páginas)
 *
 * - `GET /api/user/123` → `app/api/user/{id}.get.php`
 * - `PUT /api/user/123` → `app/api/user/{id}.put.php`
 *
 * Também aceita estilo por diretório com index:
 *
 * - `GET /api/user/123` → `app/api/user/{id}/index.get.php`
 *
 * ### Prioridade de resolução
 *
 * 1. Arquivo estático
 * 2. Arquivo dinâmico (`{id}.get.php`)
 * 3. Diretório estático com `index`
 * 4. Diretório dinâmico com `index` (`{id}/index.get.php`)
 *
 * ---
 *
 * ## 3) Método inválido / não implementado (API)
 *
 * Se o endpoint existe, mas não há arquivo para o método HTTP,
 * responde `405` com JSON:
 *```json
 * {
 *   "error": "invalid_method",
 *   "message": "Método HTTP não suportado para este endpoint.",
 *   "method": "POST",
 *   "allowed": ["GET","PUT"],
 *   "path": "/api/user/123"
 * }
 *```
 * Também envia o header HTTP:
 *
 * Allow: GET, PUT
 *
 * ---
 *
 * ## 4) Não encontrado (API)
 *
 * Se o path não existe como endpoint:
 *
 * `404` JSON:
 *
 * ```
 * {
 *   "error": "not_found",
 *   "message": "Endpoint não encontrado.",
 *   "path": "/api/x"
 * }
 * ```
 * ---
 *
 * ## 5) Variáveis disponíveis no handler
 *
 * No arquivo incluído (page ou api handler):
 *
 * - `$params` (array): parâmetros dinâmicos (`{id}`)
 * - `$query`  (array): querystring (`?a=1`)
 * - `$ctx`    (array): contexto completo (`path`, `method`, `isApi`, etc)
 *
 * Para responder JSON em handlers API:
 *```php
 * Router::json([...], 200);
 * ```
 */
final class Router
{
    private string $dir;
    private string $basePath;
    private array $tree = [];

    /** @var array<int, callable> */
    private array $globalMiddlewares = [];

    /**
     * Regras de middleware por rota/prefixo.
     * Cada item: ['type' => 'prefix'|'exact'|'file', 'pattern' => string, 'middlewares' => callable[]]
     * @var array<int, array{type:string, pattern:string, middlewares:array}>
     */
    private array $routeMiddlewares = [];

    /**
     * Bootstrap nativo para prefixos de API.
     * Cada item:
     * [
     *   'prefix' => '/api/admin/stores',
     *   'cors' => ['allow_origin_same'=>bool,'allow_methods'=>string[],'allow_headers'=>string[],'allow_credentials'=>bool],
     *   'auth' => callable|null,    // function(array $ctx): void
     *   'context' => callable|null, // function(array $ctx): array
     *   'preflight' => bool
     * ]
     *
     * @var array<int, array<string,mixed>>
     */
    private array $apiGroups = [];

    /** Pasta "api" dentro do app dir */
    private string $apiRootSegment = 'api';

    /** Métodos suportados pela convenção <name>.<method>.php */
    private const API_METHODS = ['get', 'post', 'put', 'delete', 'patch', 'options', 'head'];

    public function __construct(string $pageDir = 'src/app', string $basePath = '')
    {
        $this->dir = rtrim($pageDir, '/\\') . DIRECTORY_SEPARATOR;
        $this->basePath = '/' . trim($basePath, '/');
        if ($this->basePath === '/') $this->basePath = '';
    }

    // =========================
    // Middlewares API
    // =========================

    /**
     * Middleware global
     * Signature: function(array $ctx, callable $next): mixed
     */
    public function use(callable $middleware): self
    {
        $this->globalMiddlewares[] = $middleware;
        return $this;
    }

    /**
     * Middleware por prefixo de path (ex: "/admin" pega /admin e /admin/qualquer)
     */
    public function group(string $prefixPath, callable ...$middlewares): self
    {
        $prefixPath = $this->normalizePath($prefixPath);
        $this->routeMiddlewares[] = [
            'type' => 'prefix',
            'pattern' => $prefixPath,
            'middlewares' => $middlewares,
        ];
        return $this;
    }

    /**
     * Middleware por path exato (ex: "/login")
     */
    public function exact(string $path, callable ...$middlewares): self
    {
        $path = $this->normalizePath($path);
        $this->routeMiddlewares[] = [
            'type' => 'exact',
            'pattern' => $path,
            'middlewares' => $middlewares,
        ];
        return $this;
    }

    /**
     * Middleware por arquivo alvo (ex: "admin.php" ou "/abs/.../admin.php")
     * Útil quando você quer “atingir” uma página específica independente do path.
     */
    public function file(string $fileNameOrPath, callable ...$middlewares): self
    {
        $this->routeMiddlewares[] = [
            'type' => 'file',
            'pattern' => $fileNameOrPath,
            'middlewares' => $middlewares,
        ];
        return $this;
    }

    /**
     * Bootstrap nativo para endpoints API por prefixo.
     *
     * Config:
     * - cors.allow_origin_same (bool, default true)
     * - cors.allow_methods (array<int,string>, default GET,POST,PUT,PATCH,DELETE,OPTIONS,HEAD)
     * - cors.allow_headers (array<int,string>, default Content-Type,Authorization,X-Requested-With)
     * - cors.allow_credentials (bool, default true)
     * - auth (callable(array $ctx): void)
     * - context (callable(array $ctx): array)
     * - preflight (bool, default true)
     */
    public function apiGroup(string $prefixPath, array $config): self
    {
        $prefixPath = $this->normalizePath($prefixPath);
        $cors = is_array($config['cors'] ?? null) ? $config['cors'] : [];

        $this->apiGroups[] = [
            'prefix' => $prefixPath,
            'cors' => [
                'allow_origin_same' => (bool)($cors['allow_origin_same'] ?? true),
                'allow_methods' => array_values($cors['allow_methods'] ?? ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD']),
                'allow_headers' => array_values($cors['allow_headers'] ?? ['Content-Type', 'Authorization', 'X-Requested-With']),
                'allow_credentials' => (bool)($cors['allow_credentials'] ?? true),
            ],
            'auth' => is_callable($config['auth'] ?? null) ? $config['auth'] : null,
            'context' => is_callable($config['context'] ?? null) ? $config['context'] : null,
            'preflight' => (bool)($config['preflight'] ?? true),
        ];

        return $this;
    }

    // =========================
    // Flow
    // =========================

    public function start(): void
    {
        [$segments, $query, $path] = $this->parseRequest();

        $this->tree = $this->buildTree($this->dir);

        // root: index.php
        if (count($segments) === 0) {
            $file = $this->dir . 'index.php';
            if (is_file($file)) {
                $this->dispatch($path, $file, [], $query);
                return;
            }
            $this->notFound();
            return;
        }

        // =========================
        // API: /api/*
        // =========================
        if (isset($segments[0]) && $segments[0] === $this->apiRootSegment) {
            $match = $this->matchApi($segments, $this->tree);

            if ($match === null) {
                $this->apiNotFound($path);
                return;
            }

            [$file, $params, $allowed] = $match;

            // endpoint encontrado, mas método não implementado
            if ($file === '__METHOD_NOT_ALLOWED__') {
                $this->apiMethodNotAllowed($path, $allowed);
                return;
            }

            $this->dispatch($path, $file, $params, $query, true);
            return;
        }

        // =========================
        // Páginas normais
        // =========================
        $match = $this->match($segments, $this->tree);

        if ($match === null) {
            $this->notFound();
            return;
        }

        [$file, $params] = $match;
        $this->dispatch($path, $file, $params, $query);
    }

    private function dispatch(string $path, string $file, array $params, array $query, bool $isApi = false): void
    {
        $ctx = [
            'path' => $path,                  // path já sem basePath (ex: "/admin/users" ou "/api/user")
            'basePath' => $this->basePath,    // ex: "/portal" ou ""
            'file' => $file,                  // abs path do php renderizado
            'params' => $params,
            'query' => $query,
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'isApi' => $isApi,
        ];

        if ($isApi) {
            $ctx = $this->applyApiGroupBootstrap($ctx);
        }

        $middlewares = $this->collectMiddlewares($ctx);

        $final = function(array $ctx) {
            // Se for API, por padrão mandamos JSON (handler pode sobrescrever)
            if (!headers_sent() && ($ctx['isApi'] ?? false)) {
                header('Content-Type: application/json; charset=utf-8');
            }

            $this->render($ctx['file'], $ctx['params'], $ctx['query'], $ctx);
            return null;
        };

        $runner = $this->pipeline($middlewares, $final);

        $runner($ctx);
        exit;
    }

    private function applyApiGroupBootstrap(array $ctx): array
    {
        $group = $this->findApiGroupConfig((string)($ctx['path'] ?? '/'));
        if ($group === null) {
            return $ctx;
        }

        $cors = is_array($group['cors'] ?? null) ? $group['cors'] : [];
        $allowMethods = array_values($cors['allow_methods'] ?? []);
        $allowHeaders = array_values($cors['allow_headers'] ?? []);

        if (!headers_sent()) {
            if (count($allowMethods) > 0) {
                header('Access-Control-Allow-Methods: ' . implode(', ', $allowMethods));
            }
            if (count($allowHeaders) > 0) {
                header('Access-Control-Allow-Headers: ' . implode(', ', $allowHeaders));
            }
        }

        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $sameOrigin = $host !== '' ? ($scheme . '://' . $host) : '';
        $allowOriginSame = (bool)($cors['allow_origin_same'] ?? true);
        $allowCredentials = (bool)($cors['allow_credentials'] ?? true);

        if ($allowOriginSame && is_string($origin) && $origin !== '' && $sameOrigin !== '' && $origin === $sameOrigin) {
            if (!headers_sent()) {
                header('Access-Control-Allow-Origin: ' . $origin);
                header('Vary: Origin');
                if ($allowCredentials) {
                    header('Access-Control-Allow-Credentials: true');
                }
            }
        }

        if ((bool)($group['preflight'] ?? true) && strtoupper((string)($ctx['method'] ?? 'GET')) === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        $auth = $group['auth'] ?? null;
        if (is_callable($auth)) {
            $auth($ctx);
        }

        $contextBuilder = $group['context'] ?? null;
        if (is_callable($contextBuilder)) {
            $extra = $contextBuilder($ctx);
            if (is_array($extra) && count($extra) > 0) {
                $ctx = array_merge($ctx, $extra);
            }
        }

        return $ctx;
    }

    private function findApiGroupConfig(string $path): ?array
    {
        $path = $this->normalizePath($path);
        $best = null;
        $bestLen = -1;

        foreach ($this->apiGroups as $group) {
            $prefix = $this->normalizePath((string)($group['prefix'] ?? '/'));
            if (!($path === $prefix || str_starts_with($path, $prefix . '/'))) {
                continue;
            }

            $len = strlen($prefix);
            if ($len > $bestLen) {
                $best = $group;
                $bestLen = $len;
            }
        }

        return $best;
    }

    /** @return array<int, callable> */
    private function collectMiddlewares(array $ctx): array
    {
        $list = $this->globalMiddlewares;

        foreach ($this->routeMiddlewares as $rule) {
            $type = $rule['type'];
            $pattern = (string)$rule['pattern'];

            if ($type === 'prefix') {
                $p = $this->normalizePath($pattern);
                if ($ctx['path'] === $p || str_starts_with($ctx['path'], $p . '/')) {
                    foreach ($rule['middlewares'] as $mw) $list[] = $mw;
                }
                continue;
            }

            if ($type === 'exact') {
                $p = $this->normalizePath($pattern);
                if ($ctx['path'] === $p) {
                    foreach ($rule['middlewares'] as $mw) $list[] = $mw;
                }
                continue;
            }

            if ($type === 'file') {
                if ($ctx['file'] === $pattern || basename($ctx['file']) === $pattern) {
                    foreach ($rule['middlewares'] as $mw) $list[] = $mw;
                }
                continue;
            }
        }

        return $list;
    }

    /**
     * Pipeline estilo Express:
     * $mw($ctx, $next)
     */
    private function pipeline(array $middlewares, callable $final): callable
    {
        $next = $final;

        for ($i = count($middlewares) - 1; $i >= 0; $i--) {
            $mw = $middlewares[$i];
            $prevNext = $next;

            $next = function(array $ctx) use ($mw, $prevNext) {
                return $mw($ctx, $prevNext);
            };
        }

        return $next;
    }

    private function normalizePath(string $path): string
    {
        $path = '/' . trim($path, '/');
        return $path === '//' ? '/' : $path;
    }

    private function parseRequest(): array
    {
        // IIS may not always expose REQUEST_URI the same way Apache does.
        $uri = $_SERVER['REQUEST_URI']
            ?? $_SERVER['HTTP_X_ORIGINAL_URL']
            ?? $_SERVER['UNENCODED_URL']
            ?? $_SERVER['ORIG_PATH_INFO']
            ?? '/';
        $parts = parse_url($uri);

        $path = $parts['path'] ?? '/';
        $path = rawurldecode($path);

        // remove basePath (/portal)
        if ($this->basePath !== '' && str_starts_with($path, $this->basePath . '/')) {
            $path = substr($path, strlen($this->basePath));
        } elseif ($this->basePath !== '' && $path === $this->basePath) {
            $path = '/';
        }

        $path = '/' . trim($path, '/');
        if ($path === '//') $path = '/';

        $segments = $path === '/' ? [] : explode('/', trim($path, '/'));

        foreach ($segments as $seg) {
            if ($seg === '' || $seg === '.' || $seg === '..') {
                $this->notFound();
            }
        }

        $query = [];
        parse_str($parts['query'] ?? '', $query);

        return [$segments, $query, $path];
    }

    private function buildTree(string $dir): array
    {
        $node = ['_files' => [], '_dirs' => []];

        $items = @scandir($dir);
        if ($items === false) return $node;

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;

            $full = $dir . $item;

            if (is_dir($full)) {
                $node['_dirs'][$item] = $this->buildTree($full . DIRECTORY_SEPARATOR);
                continue;
            }

            if (is_file($full) && str_ends_with($item, '.php')) {
                // "user.post.php" vira key "user.post"
                // "{id}.get.php" vira key "{id}.get"
                $name = substr($item, 0, -4);
                $node['_files'][$name] = $full;
            }
        }

        return $node;
    }

    // =========================
    // Match normal (páginas)
    // =========================

    private function match(array $segments, array $tree): ?array
    {
        $params = [];
        $node = $tree;

        for ($i = 0; $i < count($segments) - 1; $i++) {
            $seg = $segments[$i];

            if (isset($node['_dirs'][$seg])) {
                $node = $node['_dirs'][$seg];
                continue;
            }

            $dynKey = $this->findDynamicKey(array_keys($node['_dirs']));
            if ($dynKey !== null) {
                $params[$this->paramName($dynKey)] = $seg;
                $node = $node['_dirs'][$dynKey];
                continue;
            }

            return null;
        }

        $last = $segments[count($segments) - 1];

        if (isset($node['_files'][$last])) {
            return [$node['_files'][$last], $params];
        }

        $dynFile = $this->findDynamicKey(array_keys($node['_files']));
        if ($dynFile !== null) {
            $params[$this->paramName($dynFile)] = $last;
            return [$node['_files'][$dynFile], $params];
        }

        if (isset($node['_dirs'][$last])) {
            $sub = $node['_dirs'][$last];
            if (isset($sub['_files']['index'])) {
                return [$sub['_files']['index'], $params];
            }
        }

        $dynDir = $this->findDynamicKey(array_keys($node['_dirs']));
        if ($dynDir !== null) {
            $sub = $node['_dirs'][$dynDir];
            if (isset($sub['_files']['index'])) {
                $params[$this->paramName($dynDir)] = $last;
                return [$sub['_files']['index'], $params];
            }
        }

        return null;
    }

    // =========================
    // Match API (/api/*)
    // =========================

    /**
     * Retorna:
     * - [absFilePath, params, allowedMethods] se ok
     * - ['__METHOD_NOT_ALLOWED__', params, allowedMethods] se path existe mas método não
     * - null se não encontrou o endpoint
     *
     * @return array{0:string,1:array,2:array<int,string>}|null
     */
    private function matchApi(array $segments, array $tree): ?array
    {
        $method = strtolower($_SERVER['REQUEST_METHOD'] ?? 'get');

        // método "fora da convenção"
        if (!in_array($method, self::API_METHODS, true)) {
            return ['__METHOD_NOT_ALLOWED__', [], []];
        }

        $params = [];
        $node = $tree;

        // entra no dir "api"
        if (!isset($node['_dirs'][$this->apiRootSegment])) {
            return null;
        }
        $node = $node['_dirs'][$this->apiRootSegment];

        // segmentos após "api"
        $apiSegs = array_slice($segments, 1);

        // /api -> index.<method>.php
        if (count($apiSegs) === 0) {
            return $this->resolveApiFile($node, 'index', $method, $params);
        }

        // navega dirs até o "penúltimo"
        for ($i = 0; $i < count($apiSegs) - 1; $i++) {
            $seg = $apiSegs[$i];

            if (isset($node['_dirs'][$seg])) {
                $node = $node['_dirs'][$seg];
                continue;
            }

            $dynKey = $this->findDynamicKey(array_keys($node['_dirs']));
            if ($dynKey !== null) {
                $params[$this->paramName($dynKey)] = $seg;
                $node = $node['_dirs'][$dynKey];
                continue;
            }

            return null;
        }

        $last = $apiSegs[count($apiSegs) - 1];

        // PRIORIDADE:
        // 1) arquivo estático
        // 2) arquivo dinâmico ({id}.get.php)
        // 3) diretório estático + index
        // 4) diretório dinâmico + index

        // 1 e 2: tenta resolver "last.<method>" e depois "{id}.<method>"
        $resolved = $this->resolveApiFile($node, $last, $method, $params);
        if ($resolved !== null) return $resolved;

        // 3: diretório estático -> index.<method>
        if (isset($node['_dirs'][$last])) {
            $sub = $node['_dirs'][$last];
            $resolved = $this->resolveApiFile($sub, 'index', $method, $params);
            if ($resolved !== null) return $resolved;
        }

        // 4: diretório dinâmico -> {id}/index.<method>
        $dynDir = $this->findDynamicKey(array_keys($node['_dirs']));
        if ($dynDir !== null) {
            $sub = $node['_dirs'][$dynDir];
            $tmpParams = $params;
            $tmpParams[$this->paramName($dynDir)] = $last;

            $resolved = $this->resolveApiFile($sub, 'index', $method, $tmpParams);
            if ($resolved !== null) return $resolved;
        }

        return null;
    }

    /**
     * Resolve handler API por baseName + method.
     * Suporta:
     * - user.get.php     (key "user.get")
     * - {id}.get.php     (key "{id}.get")  ✅
     *
     * Se o endpoint existe mas método não existe:
     * - retorna '__METHOD_NOT_ALLOWED__' com lista allowed.
     *
     * @return array{0:string,1:array,2:array<int,string>}|null
     */
    private function resolveApiFile(array $node, string $baseName, string $method, array $params): ?array
    {
        // 1) handler estático: "user.<method>"
        $key = $baseName . '.' . $method;
        if (isset($node['_files'][$key])) {
            return [$node['_files'][$key], $params, $this->listAllowedApiMethodsStaticBase($node, $baseName)];
        }

        // 2) handler dinâmico (arquivo) na mesma pasta: "{id}.<method>"
        $dynBase = $this->findDynamicApiBaseForMethod($node, $method);
        if ($dynBase !== null) {
            $dynKey = $dynBase . '.' . $method;

            if (isset($node['_files'][$dynKey])) {
                // baseName aqui é o valor do segmento (ex: "123"), então vira params["id"]="123"
                $params[$this->paramName($dynBase)] = $baseName;

                return [
                    $node['_files'][$dynKey],
                    $params,
                    $this->listAllowedApiMethodsDynamicBase($node, $dynBase),
                ];
            }
        }

        // 3) método não existe, mas endpoint existe (estático)?
        $allowedStatic = $this->listAllowedApiMethodsStaticBase($node, $baseName);
        if (count($allowedStatic) > 0) {
            return ['__METHOD_NOT_ALLOWED__', $params, $allowedStatic];
        }

        // 4) método não existe, mas endpoint existe (dinâmico "{id}.*")?
        $dynAny = $this->findDynamicApiBaseAny($node);
        if ($dynAny !== null) {
            $allowedDyn = $this->listAllowedApiMethodsDynamicBase($node, $dynAny);
            if (count($allowedDyn) > 0) {
                return ['__METHOD_NOT_ALLOWED__', $params, $allowedDyn];
            }
        }

        return null;
    }

    /** Acha base dinâmica "{id}" que tenha "{id}.<method>" no node */
    private function findDynamicApiBaseForMethod(array $node, string $method): ?string
    {
        $suffix = '.' . $method;

        foreach (array_keys($node['_files'] ?? []) as $k) {
            if (!str_ends_with($k, $suffix)) continue;

            $base = substr($k, 0, -strlen($suffix)); // antes do ".get"
            if ($this->isDynamic($base)) {
                return $base;
            }
        }

        return null;
    }

    /** Acha qualquer base dinâmica "{id}" existente (em qualquer método) */
    private function findDynamicApiBaseAny(array $node): ?string
    {
        foreach (array_keys($node['_files'] ?? []) as $k) {
            $pos = strrpos($k, '.');
            if ($pos === false) continue;

            $base = substr($k, 0, $pos);
            $m = substr($k, $pos + 1);

            if (!in_array($m, self::API_METHODS, true)) continue;
            if ($this->isDynamic($base)) return $base;
        }
        return null;
    }

    /** Allowed methods para base estático (ex: "user") em UPPERCASE */
    private function listAllowedApiMethodsStaticBase(array $node, string $baseName): array
    {
        $allowed = [];
        foreach (self::API_METHODS as $m) {
            $k = $baseName . '.' . $m;
            if (isset($node['_files'][$k])) $allowed[] = strtoupper($m);
        }
        return $allowed;
    }

    /** Allowed methods para base dinâmico (ex: "{id}") em UPPERCASE */
    private function listAllowedApiMethodsDynamicBase(array $node, string $dynBase): array
    {
        $allowed = [];
        foreach (self::API_METHODS as $m) {
            $k = $dynBase . '.' . $m;
            if (isset($node['_files'][$k])) $allowed[] = strtoupper($m);
        }
        return $allowed;
    }

    // =========================
    // Dinâmicos (páginas)
    // =========================

    private function findDynamicKey(array $keys): ?string
    {
        foreach ($keys as $k) {
            if ($this->isDynamic($k)) return $k;
        }
        return null;
    }

    private function isDynamic(string $name): bool
    {
        return strlen($name) >= 3 && $name[0] === '{' && $name[strlen($name)-1] === '}';
    }

    private function paramName(string $dyn): string
    {
        return trim($dyn, '{}');
    }

    // =========================
    // Not found / render
    // =========================

    private function notFound(): void
    {
        $file = $this->dir . '404.php';
        if (is_file($file)) {
            $this->render($file, [], [], []);
            exit;
        }
        http_response_code(404);
        echo "404";
        exit;
    }

    private function apiNotFound(string $path): void
    {
        self::json([
            'error' => 'not_found',
            'message' => 'Endpoint não encontrado.',
            'path' => $path,
        ], 404);
    }

    /** @param array<int,string> $allowed */
    private function apiMethodNotAllowed(string $path, array $allowed): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        if (!headers_sent()) {
            if (count($allowed) > 0) {
                header('Allow: ' . implode(', ', $allowed));
            }
        }

        self::json([
            'error' => 'invalid_method',
            'message' => 'Método HTTP não suportado para este endpoint.',
            'method' => $method,
            'allowed' => $allowed,
            'path' => $path,
        ], 405);
    }

    private function render(string $file, array $params, array $query, array $ctx = []): void
    {
        // expõe variáveis pro handler
        $params = $params;
        $query = $query;
        $ctx = $ctx;

        include $file;
        exit;
    }

    // Helpers opcionais pra middleware/handlers
    public static function abort(int $status = 403, string $message = 'Forbidden'): void
    {
        http_response_code($status);
        echo $message;
        exit;
    }

    public static function redirect(string $to, int $status = 302): void
    {
        header('Location: ' . $to, true, $status);
        exit;
    }

    /** Helper JSON (ótimo pros handlers em app/api/*.php) */
    public static function json(array $data, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
