<?php

require __DIR__ . '/../../vendor/autoload.php';

use App\App;
use App\Repositories\GoldenRepository;
use App\Repositories\UserRepository;
use App\Services\GameService;
use App\Services\TelegramService;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$app = new App(dirname(__DIR__, 2));
$pdo = $app->pdo();

$tg = new TelegramService($app->env('TELEGRAM_BOT_TOKEN', ''));
$users = new UserRepository($pdo);
$goldens = new GoldenRepository($pdo);
$game = new GameService($pdo, $users, $goldens);

$userId = (int)($_POST['user_id'] ?? 0);
$chatId = (int)($_POST['chat_id'] ?? 0);
$diceCost = (int)($app->setting('dice_cost', $app->env('DICE_COST', 5)));

$res = $game->rollIfPossible($userId, $chatId, $tg, $diceCost);

echo json_encode($res);
