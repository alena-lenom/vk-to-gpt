<?php
declare(strict_types=1);
ini_set('display_errors', '0');
require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/php/OAuthHttp.php';
VkToGpt\OAuth\dispatch();
