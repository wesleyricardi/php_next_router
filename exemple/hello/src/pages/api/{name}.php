<?php
namespace Pages\Api;

class App
{
    public static function render($req)
    {
        $name = $req['name'];

        $res = (object) [
            'message' => "Hello, $name",
        ];

        echo json_encode($res);
    }
}