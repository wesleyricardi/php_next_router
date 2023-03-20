#### Next Router

The Next router is a lightweight and user-friendly PHP router that allows developers to easily create routers by simply creating a file inside the src/pages/ directory, is based NextJs router system. This provides a simple and intuitive way to handle routing in PHP applications, making it easier for developers to create clean and user-friendly URLs for their users. With the Next router, developers can focus on building their applications, rather than spending time and effort on complex routing systems.

## Installation

To install the PHP Next router, simply run the following command in your terminal:

composer require next/router

## Usage

To use the PHP Router library, include the Router::start() function in your PHP file. This function handles the routing and rendering of the requested page.

<?php
require_once 'vendor/autoload.php';

Create a pages folder inside the src directory or set a custom path with Router::start("custom/path/"). Now, any file inside the pages directory or your custom path can become a router. For example, src/pages/hello.php can be accessed by localhost/index.php?path=hello. To access a file inside another page, use localhost/index.php?path=pagename/filename.

For dynamic paths, you can name the folder or file start with { and end with } (e.g., {name}.php). To access the file, you must create a class App with a method render and a namespace where the file is located, like:

<?php
namespace Pages;

class App
{
    public static function render($req)
    {
        echo "Home page";
    }
}

?>

If the folder or file name starts with { and ends with }, the namespace needs to be without { and }. The $req variable is an array, and to get the value of the dynamic path, you need to use the name inside {}. For example, if you have the path /src/pages/product/{category}/{name}.php, to get the category, you need to use $req["category"], and to get the name, use $req["name"].

## Conclusion

In summary, the PHP Next router provides a simple and efficient way to handle routing in PHP applications. By mapping URL paths to specific PHP files and classes, developers can create clean and user-friendly URLs for their users.
