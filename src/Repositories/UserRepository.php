<?php

namespace App\Repositories;

use PDO;

class UserRepository
{
    public function __construct(private PDO $pdo) {}

    public function getById(int|string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function upsertUser(array $tgUser): array
    {
        $existing = $this->getById($tgUser['id']);
        if ($existing) {
            $stmt = $this->pdo->prepare('UPDATE users SET username = :u, first_name = :f, last_name = :l WHERE id = :id');
            $stmt->execute([
                ':u' => $tgUser['username'] ?? null,
                ':f' => $tgUser['first_name'] ?? null,
                ':l' => $tgUser['last_name'] ?? null,
                ':id' => $tgUser['id'],
            ]);
            return $this->getById($tgUser['id']);
        }
        $stmt = $this->pdo->prepare('INSERT INTO users (id, username, first_name, last_name, points, points_today, total_won, total_lost, last_daily_reset) VALUES (:id, :u, :f, :l, 0, 0, 0, 0, NULL)');
        $stmt->execute([
            ':id' => $tgUser['id'],
            ':u' => $tgUser['username'] ?? null,
            ':f' => $tgUser['first_name'] ?? null,
            ':l' => $tgUser['last_name'] ?? null,
        ]);
        return $this->getById($tgUser['id']);
    }

    public function resetDaily(int $userId, int $dailyPoints): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET points_today = :p, last_daily_reset = CURDATE() WHERE id = :id');
        $stmt->execute([':p' => $dailyPoints, ':id' => $userId]);
    }

    public function decrementPointsToday(int $userId, int $cost): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET points_today = GREATEST(points_today - :c, 0) WHERE id = :id');
        $stmt->execute([':c' => $cost, ':id' => $userId]);
    }

    public function addPoints(int $userId, int $amount): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET points = points + :a WHERE id = :id');
        $stmt->execute([':a' => $amount, ':id' => $userId]);
    }

    public function addTotalLost(int $userId, int $amount): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET total_lost = total_lost + :a WHERE id = :id');
        $stmt->execute([':a' => $amount, ':id' => $userId]);
    }

    public function addTotalWon(int $userId, int $amount): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET total_won = total_won + :a WHERE id = :id');
        $stmt->execute([':a' => $amount, ':id' => $userId]);
    }

    public function countRollsToday(int $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM rolls WHERE user_id = :id AND DATE(created_at) = CURDATE()');
        $stmt->execute([':id' => $userId]);
        return (int)$stmt->fetchColumn();
    }

    public function getTopWinners(int $limit = 7): array
    {
        $stmt = $this->pdo->query('SELECT id, username, first_name, last_name, points FROM users ORDER BY points DESC LIMIT ' . (int)$limit);
        return $stmt->fetchAll();
    }

    public function getTopLosers(int $limit = 7): array
    {
        $stmt = $this->pdo->query('SELECT id, username, first_name, last_name, total_lost FROM users ORDER BY total_lost DESC LIMIT ' . (int)$limit);
        return $stmt->fetchAll();
    }

    public function getLastGuess(int $userId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM guesses WHERE user_id = :id ORDER BY id DESC LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
