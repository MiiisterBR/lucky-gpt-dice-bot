<?php

require __DIR__ . '/../vendor/autoload.php';

use App\App;
use App\Controllers\TelegramController;
use App\Repositories\GoldenRepository;
use App\Repositories\UserRepository;
use App\Services\GameService;
use App\Services\OpenAIService;
use App\Services\TelegramService;

$app = new App(dirname(__DIR__));
$pdo = $app->pdo();

$tg = new TelegramService($app->env('TELEGRAM_BOT_TOKEN', ''));
$users = new UserRepository($pdo);
$goldens = new GoldenRepository($pdo);
$game = new GameService($pdo, $users, $goldens);
$controller = new TelegramController($app, $tg, $game, $users, $goldens);

$raw = file_get_contents('php://input') ?: '{}';
$controller->handleWebhook($raw);

echo 'OK';
