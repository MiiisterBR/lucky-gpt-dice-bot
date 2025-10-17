<?php

namespace App\Models;

class User
{
    public int $id;
    public ?string $username = null;
    public ?string $first_name = null;
    public ?string $last_name = null;
    public int $points = 0;
    public int $points_today = 0;
    public ?string $last_daily_reset = null;
    public int $total_won = 0;
    public int $total_lost = 0;
}
