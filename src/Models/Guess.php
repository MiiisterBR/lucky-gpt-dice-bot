<?php

namespace App\Models;

class Guess
{
    public int $id;
    public int $user_id;
    public int $golden_id;
    public string $guess;
    public int $correct = 0;
    public int $reward_given = 0;
    public string $created_at;
}
