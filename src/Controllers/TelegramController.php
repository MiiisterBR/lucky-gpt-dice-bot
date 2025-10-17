<?php

namespace App\Controllers;

use App\App;
use App\Repositories\GoldenRepository;
use App\Repositories\UserRepository;
use App\Services\GameService;
use App\Services\OpenAIService;
use App\Services\TelegramService;

class TelegramController
{
    public function __construct(
        private App $app,
        private TelegramService $tg,
        private GameService $game,
        private UserRepository $users,
        private GoldenRepository $goldens,
    ) {}

    public function handleWebhook(string $raw): void
    {
        $update = json_decode($raw, true) ?? [];
        $message = $update['message'] ?? null;
        $callback = $update['callback_query'] ?? null;

        if ($message) {
            $chatId = $message['chat']['id'];
            $from = $message['from'] ?? [];
            $user = $this->users->upsertUser([
                'id' => $from['id'] ?? 0,
                'username' => $from['username'] ?? null,
                'first_name' => $from['first_name'] ?? null,
                'last_name' => $from['last_name'] ?? null,
            ]);

            // daily reset
            $dailyPoints = (int)($this->app->setting('daily_points', $this->app->env('DAILY_POINTS', 100)));
            $lastReset = $user['last_daily_reset'] ?? null;
            if (!$lastReset || date('Y-m-d') !== (string)$lastReset) {
                $this->users->resetDaily((int)$user['id'], $dailyPoints);
            }

            $text = trim((string)($message['text'] ?? ''));
            if (preg_match('/^\/start/', $text)) {
                $this->tg->sendMessage($chatId, "Welcome! Use the buttons below to play.", $this->tg->defaultKeyboard());
                return;
            }
            if (preg_match('/^\/guess\s+(\d{3})$/', $text, $m)) {
                $res = $this->game->makeGuess((int)$user['id'], $m[1]);
                $this->tg->sendMessage($chatId, $res['message'] ?? 'Done', $this->tg->defaultKeyboard());
                return;
            }
            if ($text === '/leaderboard') {
                $this->sendLeaderboards($chatId);
                return;
            }
            if ($text === '/status') {
                $this->sendStatus($chatId, (int)$user['id']);
                return;
            }
            // default help
            $this->tg->sendMessage($chatId, "Commands: /start, /guess 123, /leaderboard, /status", $this->tg->defaultKeyboard());
            return;
        }

        if ($callback) {
            $data = $callback['data'] ?? '';
            $from = $callback['from'] ?? [];
            $userId = (int)($from['id'] ?? 0);
            $chatId = $callback['message']['chat']['id'] ?? $userId;

            $this->users->upsertUser([
                'id' => $userId,
                'username' => $from['username'] ?? null,
                'first_name' => $from['first_name'] ?? null,
                'last_name' => $from['last_name'] ?? null,
            ]);

            $dailyPoints = (int)($this->app->setting('daily_points', $this->app->env('DAILY_POINTS', 100)));
            $user = $this->users->getById($userId);
            if (!$user || !$user['last_daily_reset'] || date('Y-m-d') !== (string)$user['last_daily_reset']) {
                $this->users->resetDaily($userId, $dailyPoints);
            }

            if ($data === 'start') {
                $diceCost = (int)($this->app->setting('dice_cost', $this->app->env('DICE_COST', 5)));
                $res = $this->game->rollIfPossible($userId, $chatId, $this->tg, $diceCost);
                $msg = $res['ok'] ? ('Rolled. Result: ' . ($res['result'] ?? '?')) : ($res['message'] ?? 'Error');
                $this->tg->sendMessage($chatId, $msg, $this->tg->defaultKeyboard());
                return;
            }
            if ($data === 'leaderboard') {
                $this->sendLeaderboards($chatId);
                return;
            }
            if ($data === 'status') {
                $this->sendStatus($chatId, $userId);
                return;
            }

            $this->tg->sendMessage($chatId, 'Unknown action', $this->tg->defaultKeyboard());
            return;
        }
    }

    private function sendLeaderboards(int|string $chatId): void
    {
        $boards = $this->game->leaderboards();
        $w = $boards['winners'];
        $l = $boards['losers'];

        $fmt = function (array $rows, string $title, string $metric) {
            $lines = ["*{$title}*"]; $rank = 1;
            foreach ($rows as $r) {
                $name = $r['username'] ?: trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: ('ID ' . $r['id']);
                $lines[] = sprintf('%d) %s — %s: %d', $rank++, $name, $metric, (int)$r[$metric]);
            }
            return implode("\n", $lines);
        };

        $text = $fmt($w, 'Top 7 Winners', 'points') . "\n\n" . $fmt($l, 'Top 7 Unlucky', 'total_lost');
        $this->tg->sendMessage($chatId, $text, $this->tg->defaultKeyboard());
    }

    private function sendStatus(int|string $chatId, int $userId): void
    {
        $u = $this->users->getById($userId);
        $remaining = $this->game->remainingRollsToday($userId);
        $lastGuess = $this->users->getLastGuess($userId);
        $lg = $lastGuess ? ($lastGuess['guess'] . ($lastGuess['correct'] ? ' ✅' : ' ❌')) : '—';

        $text = "Points: " . (int)$u['points'] . "\n" .
                "Daily points left: " . (int)$u['points_today'] . "\n" .
                "Remaining rolls today: " . $remaining . "\n" .
                "Last guess: " . $lg;
        $this->tg->sendMessage($chatId, $text, $this->tg->defaultKeyboard());
    }
}
