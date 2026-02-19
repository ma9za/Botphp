<?php

declare(strict_types=1);

$externalConfig = dirname(__DIR__) . '/bot_config.php';
if (is_file($externalConfig)) {
    require_once $externalConfig;
}

define('BOT_TOKEN', defined('BOT_TOKEN') ? BOT_TOKEN : (getenv('BOT_TOKEN') !== false ? (string) getenv('BOT_TOKEN') : '8432282773:AAHZmuIMka4qU6attueWz4Nk0TXrG4HUJJs'));
define('ADMIN_ID', defined('ADMIN_ID') ? ADMIN_ID : (getenv('ADMIN_ID') !== false ? (int) getenv('ADMIN_ID') : 7732118455));
define('ADMIN_PASSWORD', defined('ADMIN_PASSWORD') ? ADMIN_PASSWORD : (getenv('ADMIN_PASSWORD') !== false ? (string) getenv('ADMIN_PASSWORD') : 'admin123'));

define('DB_PATH', __DIR__ . '/database.sqlite');
define('USERS_FILE', __DIR__ . '/users.json');
define('BOT_API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');
