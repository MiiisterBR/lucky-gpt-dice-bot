<?php

namespace App\Repositories;

use PDO;

class TransactionRepository
{
    public function __construct(private PDO $pdo) {}

    /**
     * Create a new transaction
     */
    public function create(
        int $userId, 
        string $type, 
        int $amount, 
        ?int $goldenId = null, 
        ?int $sessionId = null,
        ?string $description = null,
        string $status = 'completed'
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO transactions (user_id, type, amount, golden_id, session_id, description, status) 
             VALUES (:user_id, :type, :amount, :golden_id, :session_id, :description, :status)'
        );
        
        $stmt->execute([
            ':user_id' => $userId,
            ':type' => $type,
            ':amount' => $amount,
            ':golden_id' => $goldenId,
            ':session_id' => $sessionId,
            ':description' => $description,
            ':status' => $status
        ]);
        
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Get all transactions for a user
     */
    public function getByUser(int $userId, ?int $limit = null): array
    {
        $sql = 'SELECT * FROM transactions WHERE user_id = :user_id ORDER BY created_at DESC';
        if ($limit) {
            $sql .= ' LIMIT ' . (int)$limit;
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Get transaction by ID
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM transactions WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Update transaction status
     */
    public function updateStatus(int $id, string $status): bool
    {
        $stmt = $this->pdo->prepare('UPDATE transactions SET status = :status WHERE id = :id');
        return $stmt->execute([':id' => $id, ':status' => $status]);
    }

    /**
     * Get total wins for user
     */
    public function getTotalWins(int $userId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(amount), 0) FROM transactions 
             WHERE user_id = :user_id AND type = "win" AND status = "completed"'
        );
        $stmt->execute([':user_id' => $userId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Get total losses for user
     */
    public function getTotalLosses(int $userId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(amount), 0) FROM transactions 
             WHERE user_id = :user_id AND type = "loss" AND status = "completed"'
        );
        $stmt->execute([':user_id' => $userId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Get win count for user
     */
    public function getWinCount(int $userId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM transactions 
             WHERE user_id = :user_id AND type = "win" AND status = "completed"'
        );
        $stmt->execute([':user_id' => $userId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Get loss count for user
     */
    public function getLossCount(int $userId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM transactions 
             WHERE user_id = :user_id AND type = "loss" AND status = "completed"'
        );
        $stmt->execute([':user_id' => $userId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Get pending withdrawals
     */
    public function getPendingWithdrawals(): array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM transactions 
             WHERE type = "withdraw" AND status = "pending" 
             ORDER BY created_at ASC'
        );
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Get leaderboard by total wins
     */
    public function getLeaderboardByWins(int $limit = 10): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT 
                t.user_id,
                u.username,
                u.first_name,
                u.last_name,
                u.coins,
                SUM(CASE WHEN t.type = "win" THEN t.amount ELSE 0 END) as total_wins,
                COUNT(CASE WHEN t.type = "win" THEN 1 END) as win_count,
                COUNT(CASE WHEN t.type = "loss" THEN 1 END) as loss_count
             FROM transactions t
             INNER JOIN users u ON t.user_id = u.id
             WHERE t.status = "completed" AND t.type IN ("win", "loss")
             GROUP BY t.user_id, u.username, u.first_name, u.last_name, u.coins
             ORDER BY total_wins DESC
             LIMIT :limit'
        );
        $stmt->execute([':limit' => $limit]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Get transaction history for a session
     */
    public function getBySession(int $sessionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM transactions WHERE session_id = :session_id ORDER BY created_at ASC'
        );
        $stmt->execute([':session_id' => $sessionId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Delete transactions by golden_id (cascade handled by FK)
     */
    public function deleteByGoldenId(int $goldenId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM transactions WHERE golden_id = :golden_id');
        return $stmt->execute([':golden_id' => $goldenId]);
    }
}
