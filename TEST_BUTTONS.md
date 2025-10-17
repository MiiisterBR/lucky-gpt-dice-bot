# Button Test Guide

## Problem: Buttons not working

### Possible causes:

1. **Case sensitivity issue** - Buttons send "Start" but mapping expects "start"
   - Fixed with `strtolower(trim($text))`

2. **Whitespace issue** - Button text has extra spaces
   - Fixed with `trim()`

3. **Telegram not sending text** - Button callbacks vs text messages
   - Reply keyboard buttons SHOULD send text messages
   - Inline keyboard buttons send callbacks (different handling)

4. **Webhook not receiving updates** - Telegram webhook not configured
   - Check: `https://api.telegram.org/bot<TOKEN>/getWebhookInfo`

## How to test:

### 1. Enable logging:
In `.env` file, add or update:
```
LOG_REQUESTS=true
```

### 2. Check logs:
After pressing buttons, check:
```
storage/logs/telegram-2025-10-17.log
```

### 3. Test button manually:
Send these texts directly (without buttons):
- `Start` → should trigger /startgame
- `Wallet` → should trigger /wallet
- `Status` → should trigger /status

### 4. Check keyboard type:
Ensure `defaultReplyKeyboard()` returns:
```php
[
    'keyboard' => [...],
    'resize_keyboard' => true,
    'one_time_keyboard' => false,
    'is_persistent' => true
]
```

NOT inline_keyboard!

### 5. Verify webhook:
```bash
curl https://api.telegram.org/bot<YOUR_TOKEN>/getWebhookInfo
```

Should show:
```json
{
  "url": "https://yourdomain.com/game.php",
  "has_custom_certificate": false,
  "pending_update_count": 0
}
```

## Quick Fix Options:

### Option A: Add debug response
Temporarily add this after line 40 in TelegramController.php:
```php
// DEBUG: Echo what we received
if ($text !== '' && !str_starts_with($text, '/')) {
    $this->tg->sendMessage($chatId, "DEBUG: Received text: '{$text}'");
}
```

### Option B: Force logging
In `public/game.php`, change line 23 to:
```php
if (true) { // Force logging
```

### Option C: Test with direct commands
Instead of buttons, test with:
- `/startgame`
- `/wallet`
- `/status`

If commands work but buttons don't, the issue is in the mapping.

## Current mapping:
```php
'start' => '/startgame',
'next' => '/next',
'status' => '/status',
'leaderboard' => '/leaderboard',
'wallet' => '/wallet',
'deposit' => '/deposit',
'withdraw' => '/withdraw',
```

## Expected button texts (from keyboard):
- "Start" or "Next"
- "Status"
- "Leaderboard"
- "Wallet"
- "Deposit"
- "Withdraw"

All should be converted to lowercase before mapping.
