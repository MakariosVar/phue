<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Phue\Core\Application;
use Phue\Core\Asset\AssetRegistry;
use Phue\Core\Request;

// Initialize Application Kernel with Root Directory
$app = new Application(dirname(__DIR__));

/** @var AssetRegistry $registry */
$registry = $app->getContainer()->get(AssetRegistry::class);

// 1. App-level Source Code (/assets -> App/Frontend)
$registry->registerSource('/assets', $app->getBasePath() . '/App/Frontend');
$registry->registerSource('/assets/app', $app->getBasePath() . '/App/Assets');

// 2. Downloaded 3rd-Party Vendor Dependencies (/assets/vendor -> storage/assets/vendor)
$registry->registerSource('/assets/vendor', $app->getBasePath() . '/storage/assets/vendor');

// Register application controllers
$app->getRouter()->scanControllers($app->getBasePath() . '/App/Controllers', 'App\\Controllers');

// Capture HTTP Request & Handle
$response = $app->handle(Request::capture());
$response->send();