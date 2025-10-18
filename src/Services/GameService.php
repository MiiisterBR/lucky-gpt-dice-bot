<?php

namespace App\Services;

use App\Repositories\GoldenRepository;
use App\Repositories\TransactionRepository;
use App\Repositories\UserRepository;
use PDO;

class GameService
{
    public function __construct(
        private PDO $pdo,
        private UserRepository $users,
        private GoldenRepository $goldens,
        private TransactionRepository $transactions,
    ) {}

    // v1 legacy methods removed - now using v2 session-based game logic

    public function leaderboards(): array
    {
        return [
            'winners' => $this->transactions->getLeaderboardByWins(7),
            'losers' => $this->users->getTopLosers(7),
        ];
    }

    // v1 recordUserDiceRoll removed - now handled in rollNext()
    private function setting(string $key, $default = null): mixed
    {
        try {
            $st = $this->pdo->prepare('SELECT `value` FROM settings WHERE `key` = :k');
            $st->execute([':k' => $key]);
            $v = $st->fetchColumn();
            return ($v === false || $v === null) ? $default : $v;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    public function getOrCreateDailyGolden(OpenAIService $openAI, string $model = 'gpt-5'): array
    {
        $today = date('Y-m-d');
        $existing = $this->goldens->forDate($today);
        if ($existing) return $existing;
        $num = method_exists($openAI, 'generateSevenDigit') ? $openAI->generateSevenDigit($model) : '';
        // Validate that all digits are between 1-6 (dice values)
        if (!preg_match('/^[1-6]{7}$/', $num)) {
            $n = '';
            for ($i = 0; $i < 7; $i++) { $n .= (string)random_int(1, 6); }
            $num = $n;
        }
        $this->goldens->create($num, 'openai');
        return $this->goldens->forDate($today) ?: ($this->goldens->latest() ?: []);
    }

    public function forceCreateDailyGolden(OpenAIService $openAI, string $model = 'gpt-5'): array
    {
        // Admin can force create a new golden number even if one exists for today
        $num = method_exists($openAI, 'generateSevenDigit') ? $openAI->generateSevenDigit($model) : '';
        // Validate that all digits are between 1-6 (dice values)
        if (!preg_match('/^[1-6]{7}$/', $num)) {
            $n = '';
            for ($i = 0; $i < 7; $i++) { $n .= (string)random_int(1, 6); }
            $num = $n;
        }
        $this->goldens->create($num, 'openai');
        return $this->goldens->latest() ?: [];
    }

    public function getActiveSession(int $userId): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM game_sessions WHERE user_id = :u AND finished = 0 ORDER BY id DESC LIMIT 1');
        $st->execute([':u' => $userId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public function startSession(int $userId, int $goldenId): array
    {
        // Get game start cost
        $startCost = (int)$this->setting('game_start_cost', 0);
        
        // Deduct cost if any
        if ($startCost > 0) {
            $currentCoins = $this->users->getCoins($userId);
            if ($currentCoins < $startCost) {
                throw new \Exception("Insufficient balance. Need {$startCost} coins to start a game.");
            }
            $this->users->addPoints($userId, -$startCost);
            
            // Record transaction
            $this->transactions->create(
                $userId,
                'loss',
                $startCost,
                $goldenId,
                null,
                "Game start fee: {$startCost} coins"
            );
        }
        
        $st = $this->pdo->prepare(
            'INSERT INTO game_sessions (user_id, golden_id, rolls_count, throws_remaining, finished, score_awarded, paused) 
             VALUES (:u, :g, 0, 7, 0, 0, 0)'
        );
        $st->execute([':u' => $userId, ':g' => $goldenId]);
        $id = (int)$this->pdo->lastInsertId();
        $q = $this->pdo->prepare('SELECT * FROM game_sessions WHERE id = :id');
        $q->execute([':id' => $id]);
        return $q->fetch() ?: [];
    }

    public function rollNext(int $sessionId, int $userId, int|string $chatId, TelegramService $tg, int $sleepMs = 4500): array
    {
        $q = $this->pdo->prepare('SELECT * FROM game_sessions WHERE id = :id AND user_id = :u');
        $q->execute([':id' => $sessionId, ':u' => $userId]);
        $s = $q->fetch();
        if (!$s) return ['ok' => false, 'message' => 'Session not found.'];
        if ((int)$s['finished'] === 1) return ['ok' => false, 'message' => 'Session already finished.'];
        $count = (int)$s['rolls_count'];
        if ($count >= 7) return ['ok' => false, 'message' => 'All rolls are already done.'];

        // Get roll cost
        $rollCost = (int)$this->setting('roll_cost', 0);
        
        // Deduct cost if any
        if ($rollCost > 0) {
            $currentCoins = $this->users->getCoins($userId);
            if ($currentCoins < $rollCost) {
                return ['ok' => false, 'message' => "Insufficient balance. Need {$rollCost} coins to roll."];
            }
            $this->users->addPoints($userId, -$rollCost);
            
            // Record transaction
            $this->transactions->create(
                $userId,
                'loss',
                $rollCost,
                (int)$s['golden_id'],
                $sessionId,
                "Roll fee: {$rollCost} coins"
            );
        }

        $dice = $tg->sendDice($chatId);
        $val = $dice['result']['dice']['value'] ?? ($dice['result']['value'] ?? null);
        $val = (int)($val ?? 0);
        if ($sleepMs > 0) { usleep(max(0, $sleepMs) * 1000); }

        $step = $count + 1;
        $ins = $this->pdo->prepare('INSERT INTO rolls (session_id, user_id, result, step_index, cost) VALUES (:s, :u, :r, :i, 0)');
        $ins->execute([':s' => $sessionId, ':u' => $userId, ':r' => $val, ':i' => $step]);

        $digits = (string)($s['result_digits'] ?? '');
        $digits .= (string)$val;
        $throwsRemaining = 7 - $step;
        
        $upd = $this->pdo->prepare('UPDATE game_sessions SET result_digits = :d, rolls_count = :c, throws_remaining = :t WHERE id = :id');
        $upd->execute([':d' => $digits, ':c' => $step, ':t' => $throwsRemaining, ':id' => $sessionId]);

        $finished = ($step >= 7);
        $award = 0; $exact = false; $matchCount = 0; $goldenId = (int)($s['golden_id'] ?? 0);
        
        if ($finished) {
            $goldSt = $this->pdo->prepare('SELECT g.* FROM golden_numbers g INNER JOIN game_sessions s ON s.golden_id = g.id WHERE s.id = :id');
            $goldSt->execute([':id' => $sessionId]);
            $g = $goldSt->fetch() ?: null;
            if ($g) {
                [$award, $exact, $matchCount] = $this->computeScore((string)$g['number'], $digits);
            }
            $this->pdo->prepare('UPDATE game_sessions SET finished = 1, score_awarded = :a WHERE id = :id')
                ->execute([':a' => $award, ':id' => $sessionId]);
            
            // Record transaction and update balance
            if ($award > 0) {
                $this->users->addPoints($userId, (int)$award);
                $this->transactions->create(
                    $userId, 
                    'win', 
                    $award, 
                    $goldenId, 
                    $sessionId, 
                    "Won {$matchCount}/7 digits match"
                );
            } else {
                // Record loss (0 coins)
                $this->transactions->create(
                    $userId, 
                    'loss', 
                    0, 
                    $goldenId, 
                    $sessionId, 
                    "Lost: {$matchCount}/7 digits match"
                );
            }
        }

        return [
            'ok' => true,
            'finished' => $finished,
            'last_roll' => $val,
            'rolls_count' => $step,
            'result_digits' => $digits,
            'award' => $award,
            'exact' => $exact,
            'matchCount' => $matchCount,
            'user_id' => $userId,
            // Extra context for UI
            'golden_id' => $goldenId,
            'session_id' => $sessionId,
            'golden_number' => isset($g['number']) ? (string)$g['number'] : null,
        ];
    }

    private function computeScore(string $golden, string $digits): array
    {
        $exact = ($digits === $golden);
        $setR = array_values(array_unique(str_split($digits)));
        $setG = array_values(array_unique(str_split($golden)));
        $in = 0;
        foreach ($setR as $d) { if (in_array($d, $setG, true)) { $in++; } }

        $match3 = (int)$this->setting('score_match_3', 10);
        $match5 = (int)$this->setting('score_match_5', 15);
        $allUnordered = (int)$this->setting('score_all_unordered', 30);
        $exactScore = (int)$this->setting('score_exact_ordered', 10000);

        if ($exact) return [$exactScore, true, $in];
        if ($in >= 7) return [$allUnordered, false, $in];
        if ($in >= 5) return [$match5, false, $in];
        if ($in >= 3) return [$match3, false, $in];
        return [0, false, $in];
    }

    public function createWithdrawRequest(int $userId, int $amount): int
    {
        // Deduct balance immediately and create transaction
        $this->users->addPoints($userId, -$amount);
        
        $transactionId = $this->transactions->create(
            $userId,
            'withdraw',
            $amount,
            null,
            null,
            "Withdrawal request for {$amount} coins",
            'pending'
        );
        
        return $transactionId;
    }

    public function processWithdrawTest(int $transactionId, bool $success = true): void
    {
        $status = $success ? 'completed' : 'failed';
        $this->transactions->updateStatus($transactionId, $status);
        
        // If failed, refund the amount
        if (!$success) {
            $transaction = $this->transactions->getById($transactionId);
            if ($transaction) {
                $this->users->addPoints((int)$transaction['user_id'], (int)$transaction['amount']);
                $this->transactions->create(
                    (int)$transaction['user_id'],
                    'refund',
                    (int)$transaction['amount'],
                    null,
                    null,
                    "Refund for failed withdrawal #{$transactionId}"
                );
            }
        }
    }
    
    public function pauseSession(int $sessionId, int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE game_sessions SET paused = 1, paused_at = NOW() 
             WHERE id = :id AND user_id = :user_id AND finished = 0'
        );
        return $stmt->execute([':id' => $sessionId, ':user_id' => $userId]);
    }
    
    public function resumeSession(int $sessionId, int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE game_sessions SET paused = 0, paused_at = NULL 
             WHERE id = :id AND user_id = :user_id AND finished = 0'
        );
        return $stmt->execute([':id' => $sessionId, ':user_id' => $userId]);
    }
    
    public function stopSession(int $sessionId, int $userId): bool
    {
        // Mark session as finished without any award
        $stmt = $this->pdo->prepare(
            'UPDATE game_sessions SET finished = 1, score_awarded = 0 
             WHERE id = :id AND user_id = :user_id AND finished = 0'
        );
        return $stmt->execute([':id' => $sessionId, ':user_id' => $userId]);
    }
}
