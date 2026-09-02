<?php
require __DIR__ . '/../vendor/autoload.php';

use Phue\Core\Application;
use Phue\Core\Request;
use App\Controllers\UserController;

$app = new Application(['debug' => true]);

// Register controller routes
$app->getRouter()->scanControllers(__DIR__ . '/Controllers', 'App\\Controllers');

// Capture incoming HTTP request and handle it
$request = Request::capture();
$response = $app->handle($request);

// Send HTTP headers and body back to browser/curl
$response->send();
