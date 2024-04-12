<?php
namespace Next\Router;

class Router
{
    private static $dir;
    public static function start(string $page_dir = "src/pages/"): void
    {
        $request_url = $_SERVER['REQUEST_URI'];
        $request_url = explode("?", $request_url);

        $path = $request_url[0];

        $query = [];
        if (array_key_exists(1, $request_url)) {
          $array_query = explode("&", $request_url[1]);
          foreach ($array_query as &$value) {
            $query_field = explode("=", $value);
            if (array_key_exists(1, $query_field)) {
              $query[$query_field[0]] = $query_field[1];
            } else {
              $query[$query_field[0]] = "";
            }
          }
        }

        self::$dir = $page_dir;
        if ($path == "/") {
            if (file_exists($page_dir . "index.php")) {
                render("$page_dir/index.php", [], $query);
            } else {
                self::throwNotFound();
            }
            exit;
        }

        $pathArray = explode("/", $path);

        self::router($pathArray, currentPath:0, query:$query);
    }

    private static function router(array $pathArray, int $currentPath, string $foldersPath = "", array $req = [], array $query = []): void
    {
        $nextPath = $currentPath + 1;
        $pattern = '/^\{.*\}$/';
        $path = implode('/', array_slice($pathArray, 0, -1)) . "/";

        if (!array_key_exists($nextPath, $pathArray)) {
            $fileName = $currentPath > 0 ? self::findFile($foldersPath, $pathArray[$currentPath]) : self::findFile("/", $pathArray[$currentPath]);
            if ($fileName) {
                if (preg_match($pattern, $fileName)) {
                    $key = substr($fileName, 1, -1);
                    $req[$key] = $pathArray[$currentPath];
                }
                render(self::$dir . $path . $fileName . ".php", $req, $query);
            } else {
                self::throwNotFound();
            }

            exit;
        }

        $folderName = self::findFolder($foldersPath, $pathArray[$currentPath]);

        if ($folderName && preg_match($pattern, $folderName)) {
            $key = substr($folderName, 1, -1);

            $req[$key] = $pathArray[$currentPath];
        }

        $foldersPath .= "/" . $folderName;
        $pathArray[$currentPath] = $folderName;

        self::router($pathArray, $nextPath, $foldersPath, $req, $query);
    }

    private static function throwNotFound(): void
    {
        if (file_exists(self::$dir . "404.php")) {
            render(self::$dir . "/404.php");
            exit;
        } else {
            echo "404";
            exit;
        }
    }

    private static function findFile(string $path, string $name): string | bool
    {

        if (file_exists(self::$dir . "$path/$name.php")) {
            return $name;
        }

        $pattern = '/^{.*}\.php$/';
        $files = glob(self::$dir . "$path/*.php");

        foreach ($files as $file) {
            $filename = basename($file);

            if (preg_match($pattern, $filename)) {
                return pathinfo($filename, PATHINFO_FILENAME);
            }
        }

        return false;
    }

    public static function findFolder(string $path, string $name): string
    {

        $fullPath = self::$dir . $path;
        if (is_dir($fullPath . "/" . $name)) {
            return $name;
        }

        $pattern = '/^{.*}$/';

        $folders = glob($fullPath . "/*", GLOB_ONLYDIR);

        foreach ($folders as $folder) {
            $foldername = basename($folder);

            if (preg_match($pattern, $foldername) && $foldername != "." && $foldername != "..") {
                return $foldername;
            }
        }

        return $name;
    }

}

function render(string $_FILE_PATH, array $_GET_REQUEST = [], array $_GET_QUERY = []): void
{
    include $_FILE_PATH;
}
