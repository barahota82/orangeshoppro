<?php

function input() {
    return json_decode(file_get_contents("php://input"), true);
}

function success($data=[]) {
    echo json_encode(['success'=>true,'data'=>$data]);
    exit;
}

function error($msg) {
    echo json_encode(['success'=>false,'message'=>$msg]);
    exit;
}
