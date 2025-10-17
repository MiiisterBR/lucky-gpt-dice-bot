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
}
