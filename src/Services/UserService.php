<?php

namespace App\Services;

use App\Repositories\UserRepository;
use PDO;

class UserService
{
    public function __construct(private PDO $pdo, private UserRepository $users) {}

    public function ensureAndResetDaily(array $tgUser, int $dailyPoints): array
    {
        $user = $this->users->upsertUser($tgUser);
        $needsReset = empty($user['last_daily_reset']) || (date('Y-m-d') !== (string)$user['last_daily_reset']);
        if ($needsReset) {
            $this->users->resetDaily((int)$user['id'], $dailyPoints);
            $user = $this->users->getById((int)$user['id']);
        }
        return $user;
    }

    public function getUser(int $id): ?array
    {
        return $this->users->getById($id);
    }
}
