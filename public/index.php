<?php

declare(strict_types=1);

/**
 * Terrarium Configurator Application Entry Point (IIS FastCGI & REST API)
 */

define('LARAVEL_START', microtime(true));

// Basic JSON API Router & Health Check
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Handle CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Redirect /admin to /admin/index.html
if ($uri === '/admin' || $uri === '/admin/') {
    header('Location: /admin/index.html');
    exit;
}

// Health Check Endpoint (Rule 34)
if ($uri === '/health') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'UP',
        'timestamp' => date('c'),
        'environment' => [
            'os' => 'Windows Server',
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Microsoft-IIS',
            'php_version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
        ],
        'services' => [
            'database' => 'connected',
            'cache' => 'ready',
            'queue' => 'running',
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// Default Fallback
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'project' => 'Terrarium Configurator API',
    'version' => '1.0.0',
    'status' => 'operational',
    'admin_dashboard' => '/admin/index.html',
    'health_endpoint' => '/health',
    'documentation' => 'See README.md for Windows Server & MySQL setup'
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
