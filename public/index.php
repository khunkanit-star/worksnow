<?php
declare(strict_types=1);

if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/health') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['status' => 'ok', 'application' => 'worksnow']);
    exit;
}

require dirname(__DIR__) . '/index.php';
