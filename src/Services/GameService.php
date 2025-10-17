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

    // v1 legacy methods removed - now using v2 session-based game logic

    public function leaderboards(): array
    {
        return [
            'winners' => $this->users->getTopWinners(7),
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

    public function rollNext(int $sessionId, int $userId, int|string $chatId, TelegramService $tg, int $sleepMs = 4500): array
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
