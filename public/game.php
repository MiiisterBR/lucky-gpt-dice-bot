<?php

require __DIR__ . '/../vendor/autoload.php';

use App\App;
use App\Controllers\TelegramController;
use App\Repositories\GoldenRepository;
use App\Repositories\TransactionRepository;
use App\Repositories\UserRepository;
use App\Services\GameService;
use App\Services\OpenAIService;
use App\Services\TelegramService;

$app = new App(dirname(__DIR__));
$pdo = $app->pdo();

$tg = new TelegramService($app->env('TELEGRAM_BOT_TOKEN', ''));
$users = new UserRepository($pdo);
$goldens = new GoldenRepository($pdo);
$transactions = new TransactionRepository($pdo);
$game = new GameService($pdo, $users, $goldens, $transactions);
$controller = new TelegramController($app, $tg, $game, $users, $goldens, $transactions);

$raw = file_get_contents('php://input') ?: '{}';
if (filter_var($app->env('LOG_REQUESTS', 'false'), FILTER_VALIDATE_BOOLEAN)) {
    $dir = dirname(__DIR__) . '/storage/logs';
    if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
    $file = $dir . '/telegram-' . date('Y-m-d') . '.log';
    $line = '[' . date('Y-m-d H:i:s') . "]\t" . $raw . "\n";
    @file_put_contents($file, $line, FILE_APPEND);
}
$controller->handleWebhook($raw);

echo 'OK';
