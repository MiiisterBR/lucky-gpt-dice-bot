<?php

namespace App;

use Dotenv\Dotenv;
use PDO;

class App
{
    private PDO $pdo;
    private string $rootDir;
    private array $settingsCache = [];

    public function __construct(string $rootDir)
    {
        $this->rootDir = rtrim($rootDir, DIRECTORY_SEPARATOR);

        if (file_exists($this->rootDir.'/.env')) {
            $dotenv = Dotenv::createImmutable($this->rootDir);
            $dotenv->safeLoad();
        }

        $host = $_ENV['DB_HOST'] ?? 'localhost';
        $name = $_ENV['DB_NAME'] ?? 'telegram_game';
        $user = $_ENV['DB_USER'] ?? 'root';
        $pass = $_ENV['DB_PASS'] ?? '';

        $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";
        $this->pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function env(string $key, $default = null)
    {
        return $_ENV[$key] ?? $default;
    }

    public function setting(string $key, $default = null): mixed
    {
        if (array_key_exists($key, $this->settingsCache)) {
            return $this->settingsCache[$key];
        }
        try {
            $stmt = $this->pdo->prepare('SELECT `value` FROM settings WHERE `key` = :k');
            $stmt->execute([':k' => $key]);
            $val = $stmt->fetchColumn();
            if ($val === false || $val === null) {
                return $default;
            }
            return $this->settingsCache[$key] = $val;
        } catch (\Throwable $e) {
            return $default;
        }
    }
}
