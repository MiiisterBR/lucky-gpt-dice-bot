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
        $stmt = $this->pdo->prepare('INSERT INTO users (id, username, first_name, last_name) VALUES (:id, :u, :f, :l)');
        $stmt->execute([
            ':id' => $tgUser['id'],
            ':u' => $tgUser['username'] ?? null,
            ':f' => $tgUser['first_name'] ?? null,
            ':l' => $tgUser['last_name'] ?? null,
        ]);
        return $this->getById($tgUser['id']);
    }

    public function addPoints(int $userId, int $amount): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET coins = coins + :a WHERE id = :id');
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
        $stmt = $this->pdo->query('SELECT id, username, first_name, last_name, coins FROM users ORDER BY coins DESC LIMIT ' . (int)$limit);
        return $stmt->fetchAll();
    }

    public function getTopLosers(int $limit = 7): array
    {
        $stmt = $this->pdo->query('SELECT id, username, first_name, last_name, coins FROM users ORDER BY coins ASC LIMIT ' . (int)$limit);
        return $stmt->fetchAll();
    }

    public function getLastGuess(int $userId): ?array
    {
        return null;
    }

    public function setWalletAddress(int $userId, string $address): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET wallet_address = :w WHERE id = :id');
        $stmt->execute([':w' => $address, ':id' => $userId]);
    }

    public function deductCoins(int $userId, int $amount): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET coins = GREATEST(coins - :a, 0) WHERE id = :id');
        $stmt->execute([':a' => $amount, ':id' => $userId]);
    }

    public function getCoins(int $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT coins FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        return (int)($stmt->fetchColumn() ?: 0);
    }
}
