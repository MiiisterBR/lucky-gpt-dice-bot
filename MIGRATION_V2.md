# Migration Guide: v1 → v2

## Overview

This document explains the changes between v1 and v2 of the Telegram Golden Dice Bot.

## Major Changes

### 1. Game Logic Completely Changed

**v1 (old):**
- Hourly 3-digit golden number
- Users guess the number manually
- Daily points system (100 points/day)
- Each dice roll costs 5 points

**v2 (new):**
- Daily 7-digit golden number (generated at midnight)
- 7 dice rolls per session (results 1–6)
- Scoring based on matching digits:
  - 3 matching digits (unordered) → 10 coins (configurable)
  - 5 matching digits (unordered) → 15 coins
  - All 7 digits, wrong order → 30 coins
  - Exact 7-digit ordered match → 10,000 coins
- No daily points; users earn coins only by matching

### 2. Currency & Wallet System

**New:**
- Currency name: "World Coin"
- Users start with 1,000 coins
- Can set/update Worldcoin wallet address
- Deposit functionality (shows admin-configured address)
- Withdraw functionality (minimum balance: 1,001 coins)

### 3. UX Changes

**Old:** Inline keyboards (buttons in messages)  
**New:** Persistent Reply Keyboard (buttons at bottom of chat)

**Reply Keyboard Layout:**
```
[ Start / Next ]  [ Status ]
[ Leaderboard ]   [ Wallet ]
[ Deposit ]       [ Withdraw ]
```

- **Start** button becomes **Next** when a session is active
- Buttons map to commands (e.g., "Start" → `/startgame`)

### 4. Commands

**Removed:**
- `/guess <number>` (no longer needed)

**Added:**
- `/startgame` — Start a new 7-dice session
- `/next` — Roll next dice (up to 7)
- `/wallet [<ADDRESS>]` — View or set wallet address
- `/deposit` — Show deposit address
- `/withdraw <AMOUNT>` — Create withdrawal request

**Kept:**
- `/start`, `/help`, `/status`, `/leaderboard`

### 5. Database Schema Changes

**New Tables:**
- `game_sessions` — tracks 7-roll sessions
- `withdraw_requests` — withdrawal history

**Modified Tables:**
- `users`: removed `points`, `points_today`, `total_lost`, `last_daily_reset`; added `coins`, `wallet_address`
- `golden_numbers`: `number` now CHAR(7), added `valid_date`
- `rolls`: added `session_id`, `step_index` (1–7)

**Removed Tables:**
- `guesses` (no longer used)

**Settings:**
All settings moved to DB `settings` table (managed via Admin panel):
- `start_coins`, `withdraw_min_balance`, `deposit_wallet_address`
- `sleep_ms_between_rolls`, `quiet_hours_start`, `quiet_hours_end`
- `score_match_3`, `score_match_5`, `score_all_unordered`, `score_exact_ordered`
- `openai_model`

### 6. Quiet Hours

**New Feature:**
- Bot inactive 23:00–00:00 for new sessions
- Ongoing sessions can be completed
- Configurable via Admin panel

### 7. Admin Panel

**Enhanced:**
- Comprehensive settings form (all v2 keys)
- "Generate Golden Number" now creates 7-digit number
- View withdraw requests (coming soon)
- Users list shows `coins` and `wallet_address`

## Migration Steps

### 1. Backup Data (IMPORTANT!)

```bash
mysqldump -u root -p telegram_game > backup_v1.sql
```

### 2. Re-import v2 Schema

**WARNING:** This will DROP all v1 tables and data!

```bash
mysql -u root -p telegram_game < migrations/game.sql
```

### 3. Update `.env`

Remove legacy keys:
```
# Remove these
DAILY_POINTS=100
DICE_COST=5
```

Add (optional, all have defaults in DB):
```
SLEEP_MS_BETWEEN_ROLLS=3000
```

### 4. Deploy Code

- Upload all updated files
- Run `composer install` if not already done
- Verify permissions on `storage/logs/` (if using logging)

### 5. Update Bot Commands (BotFather)

Send to @BotFather:
```
/setcommands
```

Then paste:
```
help - Show all available commands
status - Show your World Coins, session progress, and wallet
startgame - Start a new 7-dice session
next - Roll the next dice (up to 7 rolls)
wallet - View or update your Worldcoin wallet address
deposit - Show the deposit address
withdraw - Create a withdrawal request
leaderboard - View top winners
```

### 6. Set Up Cron (Daily, Midnight)

```cron
0 0 * * * php /path/to/project/public/cron/generate-golden.php > /dev/null 2>&1
```

### 7. Configure Admin Settings

Visit `/admin` and set:
- **Deposit Wallet Address** (for users to deposit)
- **Scoring values** (if you want different from defaults)
- **Quiet Hours** (default 23:00–00:00)
- **Sleep Between Rolls** (default 3000 ms)

## Testing Checklist

- [ ] Send `/start` → should see persistent keyboard
- [ ] Send `/startgame` → first roll happens, button changes to "Next"
- [ ] Send `/next` 6 times → should complete 7 rolls and award coins
- [ ] After 7 rolls, button should change back to "Start"
- [ ] Send `/status` → should show coins and session progress
- [ ] Send `/wallet 0x123...` → should save wallet address
- [ ] Send `/wallet` → should display saved address
- [ ] Send `/deposit` → should show deposit address (if configured)
- [ ] Send `/withdraw 100` → should validate and create request
- [ ] Visit `/admin` → should see new settings form
- [ ] Click "Generate Golden Number" → should create 7-digit number
- [ ] Test quiet hours (23:00–00:00) → `/startgame` should be blocked

## Troubleshooting

**Keyboard not showing:**
- Send `/start` to trigger it
- Ensure `TelegramService::defaultReplyKeyboard()` is called with every message

**Scoring not working:**
- Check Admin settings → ensure score values are set
- Verify `game_sessions.finished` is being marked as 1

**Withdraw failing:**
- Check user has >= `withdraw_min_balance` (default 1001)
- Check DB table `withdraw_requests` exists

**Golden number not 7 digits:**
- Check `OpenAIService::generateSevenDigit()` is being called
- Verify fallback is generating 7 digits (0–9)

## Notes

- **Language:** All code, comments, and bot messages are in English.
- **No inline keyboards:** All UI is via Reply Keyboard.
- **Worldcoin:** Integration is conceptual; actual API not yet implemented.

## Support

- README.md — full setup guide
- app_structure.md — architecture and design
- migrations/game.sql — v2 database schema
