<?php

use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

if (!defined('ENV_LOADED')) {
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
    define('ENV_LOADED', true);
}
