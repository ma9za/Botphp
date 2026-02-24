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

// DeepSeek API Configuration
define('DEEPSEEK_API_KEY', getenv('DEEPSEEK_API_KEY') !== false ? (string) getenv('DEEPSEEK_API_KEY') : '');
define('DEEPSEEK_API_URL', 'https://api.deepseek.com/v1/chat/completions');

// Multi-bot Conversation Settings
define("CONVERSATION_GROUP_ID", getenv("CONVERSATION_GROUP_ID") !== false ? (int) getenv("CONVERSATION_GROUP_ID") : 0);

// Bot 1 Settings
define("BOT1_ID", getenv("BOT1_ID") !== false ? (int) getenv("BOT1_ID") : 0);
define("BOT1_TOKEN", getenv("BOT1_TOKEN") !== false ? (string) getenv("BOT1_TOKEN") : "");

// Bot 2 Settings
define("BOT2_ID", getenv("BOT2_ID") !== false ? (int) getenv("BOT2_ID") : 0);
define("BOT2_TOKEN", getenv("BOT2_TOKEN") !== false ? (string) getenv("BOT2_TOKEN") : "");

// Add more bots as needed (e.g., BOT3_ID, BOT3_TOKEN)
