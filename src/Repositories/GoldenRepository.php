<?php

namespace App\Repositories;

use PDO;

class GoldenRepository
{
    public function __construct(private PDO $pdo) {}

    public function create(string $number, string $source = 'openai'): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO golden_numbers (generated_at, number, valid_date, source, announced) VALUES (NOW(), :n, CURDATE(), :s, 0)');
        $stmt->execute([':n' => $number, ':s' => $source]);
        return (int)$this->pdo->lastInsertId();
    }

    public function latest(): ?array
    {
        $stmt = $this->pdo->query('SELECT * FROM golden_numbers ORDER BY generated_at DESC, id DESC LIMIT 1');
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function forDate(string $date): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM golden_numbers WHERE valid_date = :d ORDER BY id DESC LIMIT 1');
        $stmt->execute([':d' => $date]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function markAnnounced(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE golden_numbers SET announced = 1 WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }
}
