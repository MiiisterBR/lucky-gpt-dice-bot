<?php

namespace App\Repositories;

use PDO;

class GoldenRepository
{
    public function __construct(private PDO $pdo) {}

    public function create(string $number, string $source = 'openai'): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO golden_numbers (generated_at, number, source, announced) VALUES (NOW(), :n, :s, 0)');
        $stmt->execute([':n' => $number, ':s' => $source]);
        return (int)$this->pdo->lastInsertId();
    }

    public function latest(): ?array
    {
        $stmt = $this->pdo->query('SELECT * FROM golden_numbers ORDER BY generated_at DESC, id DESC LIMIT 1');
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function markAnnounced(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE golden_numbers SET announced = 1 WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }
}
