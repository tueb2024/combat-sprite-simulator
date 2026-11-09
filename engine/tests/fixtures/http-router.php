<?php

// php -S router for tests/Http/HttpTest.php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/json') {
    header('Content-Type: application/json');
    header('X-Test: yes');
    echo json_encode(['method' => $_SERVER['REQUEST_METHOD'], 'body' => file_get_contents('php://input'),
        'type' => $_SERVER['CONTENT_TYPE'] ?? '', 'ua' => $_SERVER['HTTP_USER_AGENT'] ?? '']);
} elseif ($path === '/redirect') {
    header('Location: /json', true, 302);
} elseif ($path === '/slow') {
    sleep(3);
    echo 'late';
} elseif ($path === '/big') {
    echo str_repeat('0123456789', 50000);
} else {
    http_response_code(404);
    echo 'not found';
}
