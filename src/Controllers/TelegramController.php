<?php

namespace App\Controllers;

use App\App;
use App\Repositories\GoldenRepository;
use App\Repositories\TransactionRepository;
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
        private TransactionRepository $transactions,
    ) {}

    public function handleWebhook(string $raw): void
    {
        $update = json_decode($raw, true) ?? [];
        $message = $update['message'] ?? null;
        if (!$message) { return; }

        $chatId = $message['chat']['id'];
        $from = $message['from'] ?? [];
        $user = $this->users->upsertUser([
            'id' => $from['id'] ?? 0,
            'username' => $from['username'] ?? null,
            'first_name' => $from['first_name'] ?? null,
            'last_name' => $from['last_name'] ?? null,
        ]);

        if (isset($message['dice'])) { return; }

        $text = trim((string)($message['text'] ?? ''));
        if ($text === '') { return; }

        $userId = (int)$user['id'];
        $hasActive = ($this->game->getActiveSession($userId) !== null);

        // Map reply keyboard labels (no slash) to commands
        $map = [
            'start' => '/startgame',
            'next' => '/next',
            'status' => '/status',
            'leaderboard' => '/leaderboard',
            'wallet' => '/wallet',
            'deposit' => '/deposit',
            'withdraw' => '/withdraw',
        ];
        $low = strtolower(trim($text));
        if (isset($map[$low])) {
            $text = $map[$low];
        }

        if (preg_match('/^\/start$/i', $text)) {
            $this->tg->sendMessage($chatId, "Welcome to Golden Dice v2! Use /help to see all commands.", $this->tg->defaultReplyKeyboard($hasActive));
            return;
        }
        if (preg_match('/^\/help$/i', $text)) {
            $help = "📖 Available Commands:\n\n";
            $help .= "🎮 Game Commands:\n";
            $help .= "/startgame - Start a new game\n";
            $help .= "/next - Roll the next dice\n";
            $help .= "/pause - Pause current game\n";
            $help .= "/resume - Resume paused game\n\n";
            $help .= "💰 Wallet Commands:\n";
            $help .= "/wallet - Show your wallet\n";
            $help .= "/wallet <ADDRESS> - Set wallet\n";
            $help .= "/deposit - Show deposit address\n";
            $help .= "/withdraw <AMOUNT> - Withdraw coins\n\n";
            $help .= "📊 Info Commands:\n";
            $help .= "/status - Show your status\n";
            $help .= "/stats - Show your statistics\n";
            $help .= "/history - Transaction history\n";
            $help .= "/leaderboard - Top players";
            $this->tg->sendMessage($chatId, $help, $this->tg->defaultReplyKeyboard($hasActive));
            return;
        }
        if (preg_match('/^\/status$/i', $text)) {
            $this->sendStatus($chatId, $userId);
            return;
        }
        if (preg_match('/^\/leaderboard$/i', $text)) {
            $this->sendLeaderboards($chatId);
            return;
        }
        if (preg_match('/^\/wallet\s+(.+)/i', $text, $m)) {
            $addr = trim($m[1]);
            $this->users->setWalletAddress($userId, $addr);
            $msg = "✅ Wallet Address Saved!\n";
            $msg .= str_repeat('─', 30) . "\n";
            $msg .= "📍 Your Address:\n" . $addr . "\n";
            $msg .= str_repeat('─', 30) . "\n\n";
            $msg .= "💡 You can now withdraw your World Coins to this address.";
            $this->tg->sendMessage($chatId, $msg, $this->tg->defaultReplyKeyboard(true));
            return;
        }
        if (preg_match('/^\/wallet$/i', $text)) {
            $u = $this->users->getById($userId);
            $wa = $u['wallet_address'] ?? '';
            if ($wa) {
                $msg = "💳 Your Worldcoin Wallet\n";
                $msg .= str_repeat('─', 30) . "\n";
                $msg .= "📍 Address: " . $wa . "\n";
                $msg .= str_repeat('─', 30) . "\n\n";
                $msg .= "ℹ️ To update: /wallet NEW_ADDRESS";
            } else {
                $msg = "💳 Worldcoin Wallet Setup\n";
                $msg .= str_repeat('─', 30) . "\n";
                $msg .= "⚠️ No wallet address set yet.\n\n";
                $msg .= "📝 To set your wallet address:\n";
                $msg .= "Send: /wallet YOUR_ADDRESS\n\n";
                $msg .= "Example:\n/wallet 0xf00c1b680a372e81...";
            }
            $this->tg->sendMessage($chatId, $msg, $this->tg->defaultReplyKeyboard($hasActive));
            return;
        }
        if (preg_match('/^\/deposit$/i', $text)) {
            $addr = (string)$this->app->setting('deposit_wallet_address', '');
            if ($addr) {
                $msg = "💵 Deposit World Coins\n";
                $msg .= str_repeat('─', 30) . "\n";
                $msg .= "📍 Deposit Address:\n" . $addr . "\n";
                $msg .= str_repeat('─', 30) . "\n\n";
                $msg .= "ℹ️ Send your World Coins to this address.";
            } else {
                $msg = "⚠️ Deposit address is not configured yet.\nPlease contact the administrator.";
            }
            $this->tg->sendMessage($chatId, $msg, $this->tg->defaultReplyKeyboard($hasActive));
            return;
        }
        if (preg_match('/^\/withdraw\s+(\d+)/i', $text, $m)) {
            $amount = (int)$m[1];
            $coins = $this->users->getCoins($userId);
            $minBal = (int)$this->app->setting('withdraw_min_balance', 1001);
            if ($coins < $minBal) {
                $this->tg->sendMessage($chatId, "You need at least {$minBal} World Coins to withdraw.", $this->tg->defaultReplyKeyboard($hasActive));
                return;
            }
            if ($amount <= 0) {
                $this->tg->sendMessage($chatId, "Invalid amount.", $this->tg->defaultReplyKeyboard($hasActive));
                return;
            }
            if ($amount > $coins) {
                $this->tg->sendMessage($chatId, "Insufficient balance. You have {$coins} World Coins.", $this->tg->defaultReplyKeyboard($hasActive));
                return;
            }
            $reqId = $this->game->createWithdrawRequest($userId, $amount);
            $this->game->processWithdrawTest($reqId, true);
            $newBalance = $this->users->getCoins($userId);
            $msg = "✅ Withdrawal Successful!\n";
            $msg .= str_repeat('─', 30) . "\n";
            $msg .= "💸 Amount: {$amount} World Coins\n";
            $msg .= "💰 New Balance: {$newBalance} World Coins\n";
            $msg .= str_repeat('─', 30) . "\n\n";
            $msg .= "🎉 Your withdrawal has been processed!";
            $this->tg->sendMessage($chatId, $msg, $this->tg->defaultReplyKeyboard($hasActive));
            return;
        }
        if (preg_match('/^\/withdraw$/i', $text)) {
            $coins = $this->users->getCoins($userId);
            $minBal = (int)$this->app->setting('withdraw_min_balance', 1001);
            $msg = "💸 Withdraw World Coins\n";
            $msg .= str_repeat('─', 30) . "\n";
            $msg .= "💰 Your Balance: {$coins} World Coins\n";
            $msg .= "⚠️ Minimum Required: {$minBal} World Coins\n";
            $msg .= str_repeat('─', 30) . "\n\n";
            if ($coins < $minBal) {
                $msg .= "❌ You need at least {$minBal} coins to withdraw.\n";
                $msg .= "Keep playing to earn more!";
            } else {
                $msg .= "📝 To withdraw, send:\n/withdraw AMOUNT\n\n";
                $msg .= "Example: /withdraw 250";
            }
            $this->tg->sendMessage($chatId, $msg, $this->tg->defaultReplyKeyboard($hasActive));
            return;
        }
        if (preg_match('/^\/history$/i', $text)) {
            $transactions = $this->transactions->getByUser($userId, 10);
            if (empty($transactions)) {
                $this->tg->sendMessage($chatId, "📊 Transaction History\n\n🔍 No transactions yet.\nPlay games to earn coins!", $this->tg->defaultReplyKeyboard($hasActive));
                return;
            }
            $msg = "📊 Transaction History (Last 10)\n";
            $msg .= str_repeat('─', 30) . "\n\n";
            foreach ($transactions as $tx) {
                $icon = match($tx['type']) {
                    'win' => '🎉',
                    'loss' => '😔',
                    'deposit' => '💵',
                    'withdraw' => '💸',
                    'bonus' => '🎁',
                    'refund' => '🔄',
                    default => '•'
                };
                $sign = in_array($tx['type'], ['win', 'deposit', 'bonus', 'refund']) ? '+' : '-';
                $msg .= "{$icon} " . ucfirst($tx['type']) . ": {$sign}{$tx['amount']} coins\n";
                if ($tx['description']) {
                    $msg .= "   " . htmlspecialchars($tx['description']) . "\n";
                }
                $msg .= "   " . date('M d, H:i', strtotime($tx['created_at'])) . "\n\n";
            }
            $this->tg->sendMessage($chatId, $msg, $this->tg->defaultReplyKeyboard($hasActive));
            return;
        }
        if (preg_match('/^\/stats$/i', $text)) {
            $coins = $this->users->getCoins($userId);
            $totalWins = $this->transactions->getTotalWins($userId);
            $totalLosses = $this->transactions->getTotalLosses($userId);
            $winCount = $this->transactions->getWinCount($userId);
            $lossCount = $this->transactions->getLossCount($userId);
            $totalGames = $winCount + $lossCount;
            $winRate = $totalGames > 0 ? round(($winCount / $totalGames) * 100, 1) : 0;
            
            $msg = "📈 Your Statistics\n";
            $msg .= str_repeat('─', 30) . "\n";
            $msg .= "💰 Current Balance: {$coins} coins\n\n";
            $msg .= "🎮 Games Played: {$totalGames}\n";
            $msg .= "✅ Wins: {$winCount}\n";
            $msg .= "❌ Losses: {$lossCount}\n";
            $msg .= "📊 Win Rate: {$winRate}%\n\n";
            $msg .= "🏆 Total Won: {$totalWins} coins\n";
            $msg .= "💸 Total Lost: {$totalLosses} coins\n";
            $netProfit = $totalWins - $totalLosses;
            $profitIcon = $netProfit >= 0 ? '📈' : '📉';
            $profitSign = $netProfit >= 0 ? '+' : '';
            $msg .= "{$profitIcon} Net Profit: {$profitSign}{$netProfit} coins\n";
            $msg .= str_repeat('─', 30);
            
            $this->tg->sendMessage($chatId, $msg, $this->tg->defaultReplyKeyboard($hasActive));
            return;
        }
        if (preg_match('/^\/pause$/i', $text)) {
            $active = $this->game->getActiveSession($userId);
            if (!$active) {
                $this->tg->sendMessage($chatId, "⚠️ No active session to pause.\nStart a game with /startgame", $this->tg->defaultReplyKeyboard(false));
                return;
            }
            if ((int)$active['finished'] === 1) {
                $this->tg->sendMessage($chatId, "⚠️ This session is already finished.\nStart a new game with /startgame", $this->tg->defaultReplyKeyboard(false));
                return;
            }
            if ((int)$active['paused'] === 1) {
                $this->tg->sendMessage($chatId, "⚠️ This session is already paused.\nUse /resume to continue", $this->tg->defaultReplyKeyboard(false));
                return;
            }
            
            $this->game->pauseSession((int)$active['id'], $userId);
            $rollsDone = (int)$active['rolls_count'];
            $throwsLeft = (int)$active['throws_remaining'];
            
            $msg = "⏸️ Game Paused\n";
            $msg .= str_repeat('─', 30) . "\n";
            $msg .= "🎲 Progress: {$rollsDone}/7 rolls\n";
            $msg .= "🔢 Throws Left: {$throwsLeft}\n";
            $msg .= "📊 Digits: " . implode(', ', str_split($active['result_digits'] ?? '')) . "\n";
            $msg .= str_repeat('─', 30) . "\n\n";
            $msg .= "💡 Use /resume when ready to continue!";
            
            $this->tg->sendMessage($chatId, $msg, $this->tg->defaultReplyKeyboard(false));
            return;
        }
        if (preg_match('/^\/resume$/i', $text)) {
            $active = $this->game->getActiveSession($userId);
            if (!$active) {
                $this->tg->sendMessage($chatId, "⚠️ No session to resume.\nStart a new game with /startgame", $this->tg->defaultReplyKeyboard(false));
                return;
            }
            if ((int)$active['finished'] === 1) {
                $this->tg->sendMessage($chatId, "⚠️ This session is finished.\nStart a new game with /startgame", $this->tg->defaultReplyKeyboard(false));
                return;
            }
            if ((int)$active['paused'] === 0) {
                $this->tg->sendMessage($chatId, "⚠️ This session is not paused.\nUse /next to continue playing", $this->tg->defaultReplyKeyboard(true));
                return;
            }
            
            $this->game->resumeSession((int)$active['id'], $userId);
            $rollsDone = (int)$active['rolls_count'];
            $throwsLeft = (int)$active['throws_remaining'];
            
            $msg = "▶️ Game Resumed!\n";
            $msg .= str_repeat('─', 30) . "\n";
            $msg .= "🎲 Progress: {$rollsDone}/7 rolls\n";
            $msg .= "🔢 Throws Left: {$throwsLeft}\n";
            $msg .= "📊 Digits: " . implode(', ', str_split($active['result_digits'] ?? '')) . "\n";
            $msg .= str_repeat('─', 30) . "\n\n";
            $msg .= "🎯 Ready to continue! Use /next to roll";
            
            $this->tg->sendMessage($chatId, $msg, $this->tg->defaultReplyKeyboard(true));
            return;
        }
        if (preg_match('/^\/startgame$/i', $text)) {
            if ($this->isQuietHours()) {
                $s = (string)$this->app->setting('quiet_hours_start', '23:00');
                $e = (string)$this->app->setting('quiet_hours_end', '00:00');
                $this->tg->sendMessage($chatId, "Bot is inactive from {$s} to {$e}. Come back after {$e}.", $this->tg->defaultReplyKeyboard(false));
                return;
            }
            $active = $this->game->getActiveSession($userId);
            if ($active) {
                $this->tg->sendMessage($chatId, "You already have an active session. Send /next to continue (" . ((int)$active['rolls_count']) . "/7). ", $this->tg->defaultReplyKeyboard(true));
                return;
            }
            $model = (string)$this->app->setting('openai_model', $this->app->env('OPENAI_MODEL', 'gpt-5'));
            $openai = new OpenAIService((string)$this->app->env('OPENAI_API_KEY', ''));
            $golden = $this->game->getOrCreateDailyGolden($openai, $model);
            if (!$golden) { $this->tg->sendMessage($chatId, "Golden number is not ready yet.", $this->tg->defaultReplyKeyboard(false)); return; }
            $session = $this->game->startSession($userId, (int)$golden['id']);
            $sleepMs = (int)$this->app->setting('sleep_ms_between_rolls', $this->app->env('SLEEP_MS_BETWEEN_ROLLS', 3000));
            $res = $this->game->rollNext((int)$session['id'], $userId, $chatId, $this->tg, $sleepMs);
            $this->sendProgressMessage($chatId, $res, $model);
            return;
        }
        if (preg_match('/^\/next$/i', $text)) {
            $active = $this->game->getActiveSession($userId);
            if (!$active) { $this->tg->sendMessage($chatId, "No active session. Use /startgame", $this->tg->defaultReplyKeyboard(false)); return; }
            $sleepMs = (int)$this->app->setting('sleep_ms_between_rolls', $this->app->env('SLEEP_MS_BETWEEN_ROLLS', 3000));
            $model = (string)$this->app->setting('openai_model', $this->app->env('OPENAI_MODEL', 'gpt-5'));
            $res = $this->game->rollNext((int)$active['id'], $userId, $chatId, $this->tg, $sleepMs);
            $this->sendProgressMessage($chatId, $res, $model);
            return;
        }

        $this->tg->sendMessage($chatId, "Unknown command. Use /help", $this->tg->defaultReplyKeyboard($hasActive));
        return;
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

        $text = $fmt($w, 'Top 7 Winners', 'coins') . "\n\n" . $fmt($l, 'Top 7 Lowest Balances', 'coins');
        $hasActive = is_numeric((string)$chatId) ? ($this->game->getActiveSession((int)$chatId) !== null) : false;
        $this->tg->sendMessage($chatId, $text, $this->tg->defaultReplyKeyboard($hasActive));
    }

    private function sendStatus(int|string $chatId, int $userId): void
    {
        $u = $this->users->getById($userId);
        $coins = (int)($u['coins'] ?? 0);
        $wallet = (string)($u['wallet_address'] ?? '—');
        $s = $this->game->getActiveSession($userId);
        $hasActive = ($s !== null);
        $progress = $s ? ((int)$s['rolls_count'] . '/7, digits: ' . implode(', ', str_split((string)($s['result_digits'] ?? '')))) : 'No active session';
        $text = "World Coins: {$coins}\nWallet: {$wallet}\nSession: {$progress}";
        $this->tg->sendMessage($chatId, $text, $this->tg->defaultReplyKeyboard($hasActive));
    }

    private function sendProgressMessage(int|string $chatId, array $res, string $model): void
    {
        $digits = implode(', ', str_split((string)($res['result_digits'] ?? '')));
        $msg = "🎲 Roll {$res['rolls_count']}/7: {$res['last_roll']}\n📊 Progress: {$digits}";
        
        if (!empty($res['finished'])) {
            $userId = (int)($res['user_id'] ?? 0);
            $currentBalance = $userId ? $this->users->getCoins($userId) : 0;
            
            $msg .= "\n\n" . str_repeat('─', 30);
            $msg .= "\n🏁 Game Finished!";
            $msg .= "\n✅ Matched Digits: {$res['matchCount']}/7";
            $msg .= "\n🎁 Award: +{$res['award']} World Coins";
            $msg .= "\n💰 Current Balance: {$currentBalance} World Coins";
            $msg .= "\n" . str_repeat('─', 30);
            
            if (!empty($res['exact'])) {
                $openai = new OpenAIService((string)$this->app->env('OPENAI_API_KEY', ''));
                if (method_exists($openai, 'generateCongratsText')) {
                    $msg .= "\n\n🎉 " . $openai->generateCongratsText($model, (string)($res['result_digits'] ?? ''));
                }
            }
        }
        
        $hasActive = empty($res['finished']);
        $this->tg->sendMessage($chatId, $msg, $this->tg->defaultReplyKeyboard($hasActive));
    }

    private function isQuietHours(): bool
    {
        // Check manual override first
        $manualActive = (int)$this->app->setting('quiet_hours_active', 0);
        if ($manualActive === 1) {
            return true; // Manually activated
        }
        
        // Check time-based quiet hours
        $start = (string)$this->app->setting('quiet_hours_start', '23:00');
        $end = (string)$this->app->setting('quiet_hours_end', '00:00');
        $now = date('H:i');
        if ($start === $end) return false;
        if ($start < $end) {
            return ($now >= $start && $now < $end);
        }
        return ($now >= $start || $now < $end);
    }
}
