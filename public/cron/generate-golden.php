<?php
/**
 * Cron Job: Generate Daily Golden Number (00:00 Midnight)
 * Creates a new 7-digit golden number and announces to all users
 * 
 * Cron schedule: 0 0 * * *
 * Example: php /path/to/project/public/cron/generate-golden.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\App;
use App\Repositories\GoldenRepository;
use App\Repositories\TransactionRepository;
use App\Repositories\UserRepository;
use App\Services\GameService;
use App\Services\OpenAIService;
use App\Services\TelegramService;

$app = new App(dirname(__DIR__, 2));
$pdo = $app->pdo();

// Get timezone from settings
$timezone = $app->setting('timezone', 'UTC');
date_default_timezone_set($timezone);

$usersRepo = new UserRepository($pdo);
$goldens = new GoldenRepository($pdo);
$transactions = new TransactionRepository($pdo);
$game = new GameService($pdo, $usersRepo, $goldens, $transactions);

$model = $app->setting('openai_model', $app->env('OPENAI_MODEL', 'gpt-5'));
$openai = new OpenAIService($app->env('OPENAI_API_KEY', ''));

// Force create new golden number (even if one exists for today)
$latest = $game->forceCreateDailyGolden($openai, $model);

$sent = 0;
$failed = 0;

if ($latest) {
    $tg = new TelegramService($app->env('TELEGRAM_BOT_TOKEN', ''));
    $number = (string)($latest['number'] ?? '');
    $text = "\xF0\x9F\x8E\xAF Today's Golden Number: {$number}\n\n" . $openai->generateAnnouncementText($model);
    
    // Get all user IDs
    $stmt = $pdo->query('SELECT id FROM users');
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    
    // Send announcement to all users
    foreach ($ids as $uid) {
        try {
            $tg->sendMessage((int)$uid, $text);
            $sent++;
            usleep(50000); // 50ms delay between messages
        } catch (\Throwable $e) {
            $failed++;
            error_log("Failed to send golden announcement to user {$uid}: " . $e->getMessage());
        }
    }
    
    // Mark as announced
    if (!empty($latest['id'])) {
        $goldens->markAnnounced((int)$latest['id']);
    }
}

// Log results
$logDir = dirname(__DIR__, 2) . '/storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0777, true);
}
$logFile = $logDir . '/cron-generate-golden.log';
$logLine = '[' . date('Y-m-d H:i:s') . '] Generated: ' . ($latest['number'] ?? 'FAIL') . ', Sent: ' . $sent . ', Failed: ' . $failed . "\n";
@file_put_contents($logFile, $logLine, FILE_APPEND);

echo "Golden number generated: " . ($latest['number'] ?? 'FAILED') . " | Sent to {$sent} users ({$failed} failed)\n";
