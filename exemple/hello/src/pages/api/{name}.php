<?php

$name = $_GET_REQUEST['name'];

$res = (object) [
    'message' => "Hello, $name",
];

echo json_encode($res);