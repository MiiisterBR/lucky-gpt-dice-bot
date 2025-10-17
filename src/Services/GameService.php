<?php

namespace App\Services;

use App\Repositories\GoldenRepository;
use App\Repositories\UserRepository;
use PDO;

class GameService
{
    public function __construct(
        private PDO $pdo,
        private UserRepository $users,
        private GoldenRepository $goldens,
    ) {}

    public function remainingRollsToday(int $userId, int $limit = 3): int
    {
        $count = $this->users->countRollsToday($userId);
        return max(0, $limit - $count);
    }

    public function rollIfPossible(int $userId, int|string $chatId, TelegramService $tg, int $diceCost): array
    {
        $user = $this->users->getById($userId);
        if (!$user) {
            return ['ok' => false, 'message' => 'User not found'];
        }
        if ($this->remainingRollsToday($userId) <= 0) {
            return ['ok' => false, 'message' => 'No rolls left for today.'];
        }
        if ((int)$user['points_today'] < $diceCost) {
            return ['ok' => false, 'message' => 'Not enough daily points.'];
        }
        $this->users->decrementPointsToday($userId, $diceCost);
        $this->users->addTotalLost($userId, $diceCost);

        $dice = $tg->sendDice($chatId);
        $result = $dice['result']['dice']['value'] ?? null;
        // Some API structures return result differently; fallback:
        if ($result === null) {
            $result = $dice['result']['value'] ?? null;
        }
        $messageId = $dice['result']['message_id'] ?? null;

        $stmt = $this->pdo->prepare('INSERT INTO rolls (user_id, telegram_message_id, result, cost) VALUES (:u, :m, :r, :c)');
        $stmt->execute([
            ':u' => $userId,
            ':m' => $messageId,
            ':r' => $result,
            ':c' => $diceCost,
        ]);

        return ['ok' => true, 'result' => (int)($result ?? 0), 'message_id' => $messageId];
    }

    public function getOrCreateGolden(OpenAIService $openAI, string $model = 'gpt-5'): array
    {
        $latest = $this->goldens->latest();
        // If older than 1 hour or not exists, generate a new one
        if (!$latest || (time() - strtotime((string)$latest['generated_at'])) >= 3600) {
            $num = $openAI->generateThreeDigit($model);
            $id = $this->goldens->create($num, 'openai');
            $latest = $this->goldens->latest();
        }
        return $latest;
    }

    public function makeGuess(int $userId, string $guess): array
    {
        if (!preg_match('/^\d{3}$/', $guess)) {
            return ['ok' => false, 'message' => 'Guess must be exactly 3 digits.'];
        }
        $latest = $this->goldens->latest();
        if (!$latest) {
            return ['ok' => false, 'message' => 'No golden number yet.'];
        }
        $correct = ((string)$latest['number'] === $guess) ? 1 : 0;

        $stmt = $this->pdo->prepare('INSERT INTO guesses (user_id, golden_id, guess, correct, reward_given) VALUES (:u, :g, :guess, :c, 0)');
        $stmt->execute([
            ':u' => $userId,
            ':g' => (int)$latest['id'],
            ':guess' => $guess,
            ':c' => $correct,
        ]);

        if ($correct) {
            // Reward 100 points and mark reward_given
            $reward = 100;
            $this->users->addPoints($userId, $reward);
            $this->users->addTotalWon($userId, $reward);
            $this->pdo->prepare('UPDATE guesses SET reward_given = 1 WHERE user_id = :u AND golden_id = :g AND guess = :guess')
                ->execute([':u' => $userId, ':g' => (int)$latest['id'], ':guess' => $guess]);
            return ['ok' => true, 'correct' => true, 'message' => 'Correct! +100 points awarded.'];
        }

        return ['ok' => true, 'correct' => false, 'message' => 'Not correct. Try again!'];
    }

    public function leaderboards(): array
    {
        return [
            'winners' => $this->users->getTopWinners(7),
            'losers' => $this->users->getTopLosers(7),
        ];
    }

    public function recordUserDiceRoll(int $userId, ?int $telegramMessageId, int $result, int $diceCost): void
    {
        $this->users->decrementPointsToday($userId, $diceCost);
        $this->users->addTotalLost($userId, $diceCost);
        $stmt = $this->pdo->prepare('INSERT INTO rolls (user_id, telegram_message_id, result, cost) VALUES (:u, :m, :r, :c)');
        $stmt->execute([
            ':u' => $userId,
            ':m' => $telegramMessageId,
            ':r' => $result,
            ':c' => $diceCost,
        ]);
    }
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
        if (!preg_match('/^\d{7}$/', $num)) {
            $n = '';
            for ($i = 0; $i < 7; $i++) { $n .= (string)random_int(0, 9); }
            $num = $n;
        }
        $this->goldens->create($num, 'openai');
        return $this->goldens->forDate($today) ?: ($this->goldens->latest() ?: []);
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
        $st = $this->pdo->prepare('INSERT INTO game_sessions (user_id, golden_id, rolls_count, finished, score_awarded) VALUES (:u, :g, 0, 0, 0)');
        $st->execute([':u' => $userId, ':g' => $goldenId]);
        $id = (int)$this->pdo->lastInsertId();
        $q = $this->pdo->prepare('SELECT * FROM game_sessions WHERE id = :id');
        $q->execute([':id' => $id]);
        return $q->fetch() ?: [];
    }

    public function rollNext(int $sessionId, int $userId, int|string $chatId, TelegramService $tg, int $sleepMs = 3000): array
    {
        $q = $this->pdo->prepare('SELECT * FROM game_sessions WHERE id = :id AND user_id = :u');
        $q->execute([':id' => $sessionId, ':u' => $userId]);
        $s = $q->fetch();
        if (!$s) return ['ok' => false, 'message' => 'Session not found.'];
        if ((int)$s['finished'] === 1) return ['ok' => false, 'message' => 'Session already finished.'];
        $count = (int)$s['rolls_count'];
        if ($count >= 7) return ['ok' => false, 'message' => 'All rolls are already done.'];

        $dice = $tg->sendDice($chatId);
        $val = $dice['result']['dice']['value'] ?? ($dice['result']['value'] ?? null);
        $val = (int)($val ?? 0);
        if ($sleepMs > 0) { usleep(max(0, $sleepMs) * 1000); }

        $step = $count + 1;
        $ins = $this->pdo->prepare('INSERT INTO rolls (session_id, user_id, result, step_index, cost) VALUES (:s, :u, :r, :i, 0)');
        $ins->execute([':s' => $sessionId, ':u' => $userId, ':r' => $val, ':i' => $step]);

        $digits = (string)($s['result_digits'] ?? '');
        $digits .= (string)$val;
        $upd = $this->pdo->prepare('UPDATE game_sessions SET result_digits = :d, rolls_count = :c WHERE id = :id');
        $upd->execute([':d' => $digits, ':c' => $step, ':id' => $sessionId]);

        $finished = ($step >= 7);
        $award = 0; $exact = false; $matchCount = 0;
        if ($finished) {
            $goldSt = $this->pdo->prepare('SELECT g.* FROM golden_numbers g INNER JOIN game_sessions s ON s.golden_id = g.id WHERE s.id = :id');
            $goldSt->execute([':id' => $sessionId]);
            $g = $goldSt->fetch() ?: null;
            if ($g) {
                [$award, $exact, $matchCount] = $this->computeScore((string)$g['number'], $digits);
            }
            $this->pdo->prepare('UPDATE game_sessions SET finished = 1, score_awarded = :a WHERE id = :id')->execute([':a' => $award, ':id' => $sessionId]);
            if ($award > 0) {
                $this->users->addPoints($userId, (int)$award);
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
        $st = $this->pdo->prepare('INSERT INTO withdraw_requests (user_id, amount, status) VALUES (:u, :a, "pending")');
        $st->execute([':u' => $userId, ':a' => $amount]);
        return (int)$this->pdo->lastInsertId();
    }

    public function processWithdrawTest(int $requestId, bool $success = true): void
    {
        $status = $success ? 'success' : 'failed';
        $resp = $success ? 'stub-ok' : 'stub-error';
        $this->pdo->prepare('UPDATE withdraw_requests SET status = :s, api_response = :r WHERE id = :id')
            ->execute([':s' => $status, ':r' => $resp, ':id' => $requestId]);
    }
}
