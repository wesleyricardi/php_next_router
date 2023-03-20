<?php
namespace Pages;

class App
{
    public static function render($req)
    {
        $name = $req['name'];

        echo "Hello, $name";
    }
}