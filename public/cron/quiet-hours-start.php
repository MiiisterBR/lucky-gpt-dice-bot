<?php
/**
 * Cron Job: Quiet Hours Start (23:00)
 * Send notification to all users that bot is going inactive
 * 
 * Cron schedule: 0 23 * * *
 * Example: php /path/to/project/public/cron/quiet-hours-start.php
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\App;
use App\Services\TelegramService;

$app = new App(dirname(__DIR__, 2));
$pdo = $app->pdo();

// Get timezone from settings
$timezone = $app->setting('timezone', 'UTC');
date_default_timezone_set($timezone);

$tg = new TelegramService($app->env('TELEGRAM_BOT_TOKEN', ''));

// Get quiet hours from settings
$quietStart = $app->setting('quiet_hours_start', '23:00');
$quietEnd = $app->setting('quiet_hours_end', '00:00');

// Prepare message
$message = "🌙 Bot Maintenance Notice\n\n";
$message .= "The bot will be inactive from {$quietStart} to {$quietEnd} for daily maintenance.\n\n";
$message .= "⚠️ You cannot start new games during this time.\n";
$message .= "✅ Ongoing games can still be completed.\n\n";
$message .= "🕐 We'll be back at {$quietEnd} with a fresh new Golden Number!\n";
$message .= "See you soon! 🎲";

// Get all user IDs
$stmt = $pdo->query('SELECT id FROM users');
$userIds = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

$sent = 0;
$failed = 0;

foreach ($userIds as $userId) {
    try {
        $tg->sendMessage((int)$userId, $message);
        $sent++;
        usleep(50000); // 50ms delay between messages to avoid rate limits
    } catch (\Throwable $e) {
        $failed++;
        error_log("Failed to send quiet hours notification to user {$userId}: " . $e->getMessage());
    }
}

// Log results
$logDir = dirname(__DIR__, 2) . '/storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0777, true);
}
$logFile = $logDir . '/cron-quiet-start.log';
$logLine = '[' . date('Y-m-d H:i:s') . '] Sent: ' . $sent . ', Failed: ' . $failed . "\n";
@file_put_contents($logFile, $logLine, FILE_APPEND);

echo "Quiet hours notification sent to {$sent} users ({$failed} failed)\n";
