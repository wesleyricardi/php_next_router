<?php
namespace Next\Router;

class Router
{
    private static $dir;
    public static function start($page_dir = "src/pages/")
    {
        $path = isset($_GET['path']) ? $_GET['path'] : "";
        self::$dir = $page_dir;
        if (empty($path)) {
            if (file_exists($page_dir . "index.php")) {
                echo self::render("/", 'index');
            } elseif (file_exists($page_dir . "404.php")) {
                echo self::render("/", '404');
            } else {
                echo "404";
            }
            exit;
        }

        $pathArray = explode("/", $path);

        echo self::router(pathArray:$pathArray, currentPath:0);
    }

    private static function router(array $pathArray, int $currentPath, string $foldersPath = "/", array $req = [])
    {
        $nextPath = $currentPath + 1;
        $previousPath = $currentPath - 1;
        $pattern = '/^\{.*\}$/';
        $path = implode('/', array_slice($pathArray, 0, -1));

        if (!isset($pathArray[$nextPath])) {
            $fileName = !isset($pathArray[$previousPath]) ? self::findFile("/", $pathArray[$currentPath]) : self::findFile($path, $pathArray[$currentPath]);
            $namespace = str_replace(['{', '}'], '', $foldersPath);
            $namespace = str_replace('/', '\\', $namespace);
            $namespace = substr($namespace, 1);
            $className = $namespace . "App";
            if ($fileName) {
                if (preg_match($pattern, $fileName)) {
                    $key = substr($fileName, 1, strlen($fileName) - 2);
                    $req[$key] = $pathArray[$currentPath];
                    return self::render($path, $fileName, $className, $req);
                } else {
                    return self::render($path, $fileName, $className, $req);
                }
            } elseif (file_exists(self::$dir . "404.php")) {
                return self::render("/", '404');
            } else {
                return "404";
            }

        }

        $folderName = self::findFolder($foldersPath, $pathArray[$currentPath]);

        $folderName = !isset($pathArray[$previousPath]) ? self::findFolder("/", $pathArray[$currentPath]) : self::findFolder($foldersPath, $pathArray[$currentPath]);
        $foldersPath .= $folderName . "/";

        if ($folderName) {

            if (preg_match($pattern, $folderName)) {
                $key = substr($folderName, 1, strlen($folderName) - 2);
                $req[$key] = $pathArray[$currentPath];
            }
            $pathArray[$currentPath] = $folderName;
        }

        return self::router($pathArray, currentPath:$nextPath, foldersPath:$foldersPath, req:$req);
    }

    private static function render($path, $name, $className = "App", $req = [])
    {
        $className = empty($className) ? 'pages\\App' : 'pages\\' . $className;

        if (substr($path, -1) !== '/') {
            $path .= '/';
        }

        include self::$dir . $path . $name . ".php";

        if (class_exists("$name") || class_exists($className)) {
            $obj = new $className();
            if (method_exists($obj, "render")) {
                $obj->render($req);
            } else {
                echo "Erro: a classe " . $className . " não possui um método render";
            }
        } else {
            echo "Erro: a classe " . $className . " não foi encontrada";
        }
    }

    private static function findFile($path, $name)
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

    public static function findFolder($path, $name)
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

        return false;
    }

}