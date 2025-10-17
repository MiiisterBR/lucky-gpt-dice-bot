<?php

require __DIR__ . '/../../vendor/autoload.php';

use App\App;
use App\Repositories\GoldenRepository;
use App\Repositories\UserRepository;
use App\Services\GameService;
use App\Services\OpenAIService;
use App\Services\TelegramService;

$app = new App(dirname(__DIR__, 2));
$pdo = $app->pdo();

$usersRepo = new UserRepository($pdo);
$goldens = new GoldenRepository($pdo);
$game = new GameService($pdo, $usersRepo, $goldens);

$model = $app->setting('openai_model', $app->env('OPENAI_MODEL', 'gpt-5'));
$openai = new OpenAIService($app->env('OPENAI_API_KEY', ''));
$latest = $game->getOrCreateGolden($openai, $model);

if ($latest && (int)$latest['announced'] === 0) {
    $tg = new TelegramService($app->env('TELEGRAM_BOT_TOKEN', ''));
    $stmt = $pdo->query('SELECT id FROM users');
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    foreach ($ids as $uid) {
        $tg->sendMessage((int)$uid, 'A new golden number has been created! Try to guess it!', [
            [
                ['text' => 'Start', 'callback_data' => 'start'],
                ['text' => 'Leaderboard', 'callback_data' => 'leaderboard'],
                ['text' => 'Status', 'callback_data' => 'status']
            ]
        ]);
        usleep(50000); // basic rate limiting
    }
    $goldens->markAnnounced((int)$latest['id']);
}

echo "OK\n";
