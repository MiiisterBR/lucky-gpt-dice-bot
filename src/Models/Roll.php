<?php

namespace App\Models;

class Roll
{
    public int $id;
    public int $user_id;
    public ?int $telegram_message_id = null;
    public ?int $result = null;
    public int $cost = 5;
    public string $created_at;
}
