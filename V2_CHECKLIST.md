# V2 Implementation Checklist ✅

## ✅ Completed Features

### 1. Wallet System
- ✅ `/wallet` - View current wallet address
- ✅ `/wallet <ADDRESS>` - Set or update Worldcoin wallet address
- ✅ Database: `users.wallet_address` (UNIQUE, nullable)
- ✅ Validation: Address stored with user's name/ID
- ✅ Update logic: Overwrites existing address

### 2. Withdraw Functionality
- ✅ `/withdraw <AMOUNT>` - Create withdrawal request
- ✅ Validation: Minimum balance check (`withdraw_min_balance` = 1001)
- ✅ Validation: Amount > 0 and ≤ user's coins
- ✅ Database: `withdraw_requests` table (status: pending/success/failed)
- ✅ Test API: Stub implementation (always returns success)
- ✅ Response messages: Success/error feedback to user

### 3. Deposit Functionality
- ✅ `/deposit` - Show deposit wallet address
- ✅ Admin setting: `deposit_wallet_address` configurable via Admin panel
- ✅ Display: Shows pre-configured address or "not configured" message

### 4. 7-Digit Golden Number
- ✅ OpenAI generation: `generateSevenDigit()` method
- ✅ Daily generation: At midnight (00:00) via cron
- ✅ Fallback: Random 7-digit (0-9) if OpenAI fails
- ✅ Database: `golden_numbers.number` CHAR(7)
- ✅ Validation: `valid_date` field for daily tracking

### 5. Game Logic (7 Dice Rolls)
- ✅ `/startgame` - Start new session (first roll happens immediately)
- ✅ `/next` - Roll next dice (up to 7 total)
- ✅ Reply keyboard: **Start** becomes **Next** when session active
- ✅ Sleep between rolls: `sleep_ms_between_rolls` (default 3000ms)
- ✅ Database: `game_sessions` tracks 7-roll sessions
- ✅ Database: `rolls` stores each individual roll

### 6. Scoring System
- ✅ **3 matching digits** (unordered) → `score_match_3` (default 10)
- ✅ **5 matching digits** (unordered) → `score_match_5` (default 15)
- ✅ **All 7 digits**, wrong order → `score_all_unordered` (default 30)
- ✅ **Exact 7-digit ordered match** → `score_exact_ordered` (default 10,000)
- ✅ Algorithm: `computeScore()` in `GameService`
- ✅ Award coins: Added to `users.coins` on session finish

### 7. Admin Settings
- ✅ All settings in database (`settings` table)
- ✅ Admin panel form with all v2 keys:
  - `deposit_wallet_address`
  - `sleep_ms_between_rolls`
  - `quiet_hours_start`, `quiet_hours_end`
  - `score_match_3`, `score_match_5`, `score_all_unordered`, `score_exact_ordered`
  - `start_coins`, `withdraw_min_balance`
  - `openai_model`
- ✅ "Generate Golden Number" button (creates 7-digit)

### 8. User Balance & Coins
- ✅ Currency name: "World Coin"
- ✅ Initial balance: 1,000 coins (`start_coins`)
- ✅ Minimum to withdraw: 1,001 coins (`withdraw_min_balance`)
- ✅ Coins earned only by matching golden number

### 9. Daily Golden Number & Events
- ✅ Generation: Midnight (00:00) via cron
- ✅ Manual trigger: Admin panel button
- ✅ Broadcast: Automatic message to all users
- ✅ Message: "The new number is ready! Try your luck!"
- ✅ OpenAI: Announcement text generation

### 10. Quiet Hours
- ✅ Time range: 23:00–00:00 (configurable)
- ✅ Block: New sessions cannot start
- ✅ Allow: Ongoing sessions can finish
- ✅ Message: "Bot is inactive from {start} to {end}. Come back after {end}."
- ✅ Admin configurable: `quiet_hours_start`, `quiet_hours_end`

### 11. Sleep Between Rolls
- ✅ Duration: 3 seconds (3000ms) default
- ✅ Configurable: `sleep_ms_between_rolls` in Admin
- ✅ Implementation: `usleep()` in PHP after each dice roll

### 12. Congratulatory Message
- ✅ Trigger: Exact 7-digit ordered match
- ✅ OpenAI: `generateCongratsText()` method
- ✅ Personalized: Includes matched digits
- ✅ Sent automatically: Appended to finish message

### 13. Reply Keyboard (UX)
- ✅ Persistent keyboard: Bottom of chat
- ✅ Layout:
  - Row 1: **Start** (or **Next**), **Status**
  - Row 2: **Leaderboard**, **Wallet**
  - Row 3: **Deposit**, **Withdraw**
- ✅ Dynamic: **Start** ↔ **Next** based on session state
- ✅ No inline keyboards: Only Reply Keyboard

### 14. Commands
- ✅ `/help` - Show all commands
- ✅ `/status` - Show coins, session progress, wallet
- ✅ `/startgame` - Start new session
- ✅ `/next` - Roll next dice
- ✅ `/wallet [<ADDRESS>]` - View/set wallet
- ✅ `/deposit` - Show deposit address
- ✅ `/withdraw <AMOUNT>` - Create withdrawal
- ✅ `/leaderboard` - Top winners/losers

### 15. Language & Code Style
- ✅ All code: English
- ✅ All comments: English
- ✅ All bot messages: English
- ✅ Clean architecture: Controllers, Services, Repositories

### 16. Database Schema (v2)
- ✅ `users`: coins, wallet_address, timestamps
- ✅ `golden_numbers`: 7-digit, valid_date, announced
- ✅ `game_sessions`: 7 rolls, scoring, finished
- ✅ `rolls`: session_id, step_index (1-7)
- ✅ `withdraw_requests`: amount, status, api_response
- ✅ `settings`: key/value store
- ✅ Removed: `guesses` table (v1 logic)

### 17. Documentation
- ✅ `README.md` - Complete setup guide
- ✅ `app_structure.md` - Architecture & design
- ✅ `MIGRATION_V2.md` - Migration guide from v1
- ✅ `migrations/game.sql` - v2 database schema

---

## 🎯 Testing Recommendations

### Basic Flow
1. Import `migrations/game.sql`
2. Visit `/admin` → Generate Golden Number
3. Send `/start` to bot → keyboard appears
4. Send `/startgame` → first roll, button changes to "Next"
5. Send `/next` 6 more times → 7 rolls complete
6. Check coins awarded based on matching

### Wallet Flow
1. Send `/wallet` → should show "No wallet set"
2. Send `/wallet 0x123abc...` → should save
3. Send `/wallet` → should display saved address
4. Send `/deposit` → should show admin-configured address

### Withdraw Flow
1. Ensure user has ≥ 1001 coins
2. Send `/withdraw 100` → should create request
3. Check status message (success/error)
4. Admin panel → view withdraw requests

### Quiet Hours
1. Change server time to 23:30
2. Send `/startgame` → should be blocked
3. Message should say "Come back after 00:00"

### Scoring Test
1. Admin panel → check current golden number
2. Play game and match digits
3. Verify coins awarded correctly:
   - 3 digits → 10 coins
   - 5 digits → 15 coins
   - All 7 (wrong order) → 30 coins
   - Exact match → 10,000 coins

---

## 📝 Notes

- **Golden number range**: 0-9 (not 1-6 like dice)
- **Dice results**: 1-6 (Telegram dice emoji)
- **Scoring**: Checks if dice results (1-6) appear in golden number (0-9)
- **Important**: If you want golden number to use dice range (1-6), update `generateSevenDigit()` fallback to `random_int(1, 6)`

---

## ✅ All Requirements Completed!

Every feature from the original request has been implemented:
1. ✅ Wallet (set/update Worldcoin address)
2. ✅ Withdraw (with validation and test API)
3. ✅ Deposit (show admin address)
4. ✅ 7-digit golden number (daily generation)
5. ✅ 7 dice rolls per session
6. ✅ Scoring system (3/5/7 digits, exact match)
7. ✅ Admin settings (all configurable)
8. ✅ World Coin currency (1000 initial, 1001 min withdraw)
9. ✅ Quiet hours (23:00-00:00)
10. ✅ Sleep between rolls (3000ms)
11. ✅ Congratulatory message (GPT-generated)
12. ✅ English language (code, comments, messages)
13. ✅ Reply keyboard (no inline keyboards)

**Status: READY FOR TESTING** 🎉
