<?php
session_start();
header('Content-Type: application/json');

require __DIR__ . '/../vendor/autoload.php';

use App\App;
use App\Repositories\UserRepository;
use App\Repositories\GoldenRepository;
use App\Repositories\TransactionRepository;
use App\Services\GameService;
use App\Services\OpenAIService;
use App\Services\TelegramService;

if (empty($_SESSION['admin_user'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$app = new App(dirname(__DIR__));
$pdo = $app->pdo();
$users = new UserRepository($pdo);
$goldens = new GoldenRepository($pdo);
$transactions = new TransactionRepository($pdo);
$game = new GameService($pdo, $users, $goldens, $transactions);

$action = $_POST['action'] ?? '';

if ($action === 'generate_golden') {
    try {
        $model = $app->setting('openai_model', $app->env('OPENAI_MODEL', 'gpt-5'));
        $openai = new OpenAIService($app->env('OPENAI_API_KEY', ''));
        
        // Check if golden exists for today
        $today = date('Y-m-d');
        $existing = $goldens->forDate($today);
        
        if ($existing && empty($_POST['force'])) {
            echo json_encode([
                'success' => false,
                'message' => 'A golden number already exists for today: ' . $existing['number'],
                'number' => $existing['number'],
                'needs_confirm' => true
            ]);
            exit;
        }
        
        // Force create new number
        $latest = $game->forceCreateDailyGolden($openai, $model);
        
        // Send announcement to users
        $tg = new TelegramService($app->env('TELEGRAM_BOT_TOKEN', ''));
        $text = $openai->generateAnnouncementText($model);
        $stmt = $pdo->query('SELECT id FROM users');
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        
        foreach ($ids as $uid) {
            try {
                $tg->sendMessage((int)$uid, $text);
                usleep(50000);
            } catch (\Throwable $e) {
                // Ignore failed sends
            }
        }
        
        if (!empty($latest['id'])) {
            $goldens->markAnnounced((int)$latest['id']);
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Golden number generated successfully!',
            'number' => $latest['number'] ?? '',
            'users_notified' => count($ids)
        ]);
        
    } catch (\Throwable $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ]);
    }
    exit;
}

if ($action === 'update_settings') {
    try {
        $deposit = trim($_POST['deposit_wallet_address'] ?? '');
        $sleepMs = (string)max(0, (int)($_POST['sleep_ms_between_rolls'] ?? 3000));
        $quietStart = trim($_POST['quiet_hours_start'] ?? '23:00');
        $quietEnd = trim($_POST['quiet_hours_end'] ?? '00:00');
        $score3 = (string)max(0, (int)($_POST['score_match_3'] ?? 10));
        $score5 = (string)max(0, (int)($_POST['score_match_5'] ?? 15));
        $scoreUnord = (string)max(0, (int)($_POST['score_all_unordered'] ?? 30));
        $scoreExact = (string)max(0, (int)($_POST['score_exact_ordered'] ?? 10000));
        $startCoins = (string)max(0, (int)($_POST['start_coins'] ?? 1000));
        $withdrawMin = (string)max(0, (int)($_POST['withdraw_min_balance'] ?? 1001));
        $openaiModel = trim($_POST['openai_model'] ?? 'gpt-5');
        $timezone = trim($_POST['timezone'] ?? 'UTC');
        
        $settings = [
            'deposit_wallet_address' => $deposit,
            'sleep_ms_between_rolls' => $sleepMs,
            'quiet_hours_start' => $quietStart,
            'quiet_hours_end' => $quietEnd,
            'score_match_3' => $score3,
            'score_match_5' => $score5,
            'score_all_unordered' => $scoreUnord,
            'score_exact_ordered' => $scoreExact,
            'start_coins' => $startCoins,
            'withdraw_min_balance' => $withdrawMin,
            'openai_model' => $openaiModel,
            'timezone' => $timezone,
        ];
        
        $stmt = $pdo->prepare('INSERT INTO settings (`key`, `value`) VALUES (:k, :v) ON DUPLICATE KEY UPDATE `value` = :v');
        foreach ($settings as $k => $v) {
            $stmt->execute([':k' => $k, ':v' => $v]);
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Settings saved successfully!'
        ]);
        
    } catch (\Throwable $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
