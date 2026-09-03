<?php

require __DIR__ . '/../vendor/autoload.php';

use Phue\Core\Application;
use Phue\Core\Request;

// Initialize Application Kernel with Root Directory
$app = new Application(dirname(__DIR__));

// Register application controllers
$app->getRouter()->scanControllers($app->getBasePath() . '/App/Controllers', 'App\\Controllers');

// Capture HTTP Request & Handle
$response = $app->handle(Request::capture());
$response->send();
