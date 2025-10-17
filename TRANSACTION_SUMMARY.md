# ✅ Transaction System - خلاصه تغییرات

## 🎯 مشکلاتی که حل شدن:

### ✅ 1. تراکنش‌ها جدا شدن
**قبل:** فقط یک فیلد `coins` وجود داشت
**حالا:** جدول `transactions` با تاریخچه کامل همه تراکنش‌ها

### ✅ 2. ثبت برد و باخت
**قبل:** فقط موجودی عوض میشد، تاریخچه‌ای نبود
**حالا:** هر بازی یک transaction ثبت می‌کنه (win/loss)

### ✅ 3. کسر موجودی هنگام برداشت
**قبل:** برداشت فقط request ثبت می‌کرد
**حالا:** موجودی فوراً کم میشه + transaction pending ثبت میشه

### ✅ 4. شناسه طلایی (golden_id)
**قبل:** orphan data می‌موند
**حالا:** CASCADE DELETE - حذف golden همه transaction های مرتبط رو حذف می‌کنه

### ✅ 5. لیدربورد دقیق
**قبل:** از `users.coins` محاسبه میشد (غیردقیق)
**حالا:** از مجموع transaction های win محاسبه میشه

### ✅ 6. تعداد پرتاب (throws_remaining)
**قبل:** فقط `rolls_count` بود
**حالا:** `throws_remaining` ثبت میشه و با هر پرتاب کم میشه

### ✅ 7. Pause/Resume بازی
**قبل:** وجود نداشت
**حالا:** می‌تونی بازی رو pause کنی و بعداً resume کنی

### ✅ 8. Refund خودکار
**قبل:** اگه withdraw fail میشد، پول گم میشد
**حالا:** خودکار refund میشه + transaction ثبت میشه

## 📊 ساختار جدید دیتابیس:

### جدول: `transactions`
```
id, user_id, type, amount, golden_id, session_id, description, status, created_at
```

**انواع transaction:**
- `win` - برنده شدن بازی
- `loss` - باخت بازی
- `deposit` - واریز
- `withdraw` - برداشت
- `bonus` - جایزه
- `refund` - بازگشت پول

**وضعیت (status):**
- `pending` - در انتظار
- `completed` - تکمیل شده
- `failed` - ناموفق
- `cancelled` - لغو شده

### تغییرات `game_sessions`:
```sql
+ throws_remaining INT (تعداد پرتاب باقی‌مانده)
+ paused TINYINT(1) (آیا متوقف شده؟)
+ paused_at TIMESTAMP (زمان توقف)
```

## 🆕 Command های جدید:

### `/history`
نمایش 10 تراکنش آخر:
```
📊 Transaction History (Last 10)
──────────────────────────────

🎉 Win: +500 coins
   Won 5/7 digits match
   Oct 17, 22:30

😔 Loss: -0 coins
   Lost: 2/7 digits match
   Oct 17, 22:15
```

### `/stats`
نمایش آمار کامل:
```
📈 Your Statistics
──────────────────────────────
💰 Current Balance: 1500 coins

🎮 Games Played: 25
✅ Wins: 8
❌ Losses: 17
📊 Win Rate: 32.0%

🏆 Total Won: 2500 coins
💸 Total Lost: 0 coins
📈 Net Profit: +2500 coins
```

### `/pause`
متوقف کردن بازی فعلی:
```
⏸️ Game Paused
──────────────────────────────
🎲 Progress: 3/7 rolls
🔢 Throws Left: 4
📊 Digits: 6, 2, 4
──────────────────────────────

💡 Use /resume when ready to continue!
```

### `/resume`
ادامه بازی متوقف شده:
```
▶️ Game Resumed!
──────────────────────────────
🎲 Progress: 3/7 rolls
🔢 Throws Left: 4
📊 Digits: 6, 2, 4
──────────────────────────────

🎯 Ready to continue! Use /next to roll
```

## 📝 فایل‌های تغییر یافته:

### ✅ جدید:
1. `migrations/transactions.sql` - Migration دیتابیس
2. `src/Repositories/TransactionRepository.php` - Repository جدید
3. `TRANSACTIONS_UPGRADE.md` - راهنمای آپگرید
4. `TRANSACTION_SUMMARY.md` - این فایل

### ✅ به‌روز شده:
1. `src/Services/GameService.php`
   - Constructor: + `TransactionRepository`
   - `startSession()`: + `throws_remaining`, `paused`
   - `rollNext()`: ثبت transaction برای win/loss
   - `createWithdrawRequest()`: کسر موجودی فوری
   - `processWithdrawTest()`: refund خودکار
   - `pauseSession()`: متد جدید
   - `resumeSession()`: متد جدید

2. `src/Controllers/TelegramController.php`
   - Constructor: + `TransactionRepository`
   - `/history`: command جدید
   - `/stats`: command جدید
   - `/pause`: command جدید
   - `/resume`: command جدید
   - `/help`: آپدیت شد

3. `public/game.php` - injection TransactionRepository
4. `public/cron/generate-golden.php` - injection TransactionRepository
5. `admin/index.php` - injection TransactionRepository
6. `admin/ajax.php` - injection TransactionRepository

## 🧪 چگونه تست کنیم؟

### 1️⃣ اجرای Migration:
```bash
mysql -u root -p mindroll < migrations/transactions.sql
```

یا در phpMyAdmin:
- Import فایل `migrations/transactions.sql`

### 2️⃣ تست Commands:

```
/help          → باید command های جدید رو ببینی
/stats         → آمار کلی
/history       → تاریخچه تراکنش‌ها
/startgame     → شروع بازی
/pause         → متوقف کردن
/resume        → ادامه دادن
/withdraw 100  → موجودی باید 100 کم بشه فوراً
```

### 3️⃣ بررسی دیتابیس:

```sql
-- چک کردن جدول transactions
SELECT * FROM transactions ORDER BY created_at DESC LIMIT 10;

-- چک کردن game_sessions
DESCRIBE game_sessions;

-- چک کردن leaderboard
SELECT 
    u.username,
    SUM(CASE WHEN t.type = 'win' THEN t.amount ELSE 0 END) as total_wins
FROM transactions t
JOIN users u ON t.user_id = u.id
WHERE t.status = 'completed'
GROUP BY u.username
ORDER BY total_wins DESC
LIMIT 10;
```

### 4️⃣ تست Withdraw:

```
1. موجودی فعلی: /status
   مثلاً: 1500 coins

2. برداشت: /withdraw 200
   
3. چک موجودی: /status
   باید: 1300 coins (200 کم شده)

4. چک transaction:
   SELECT * FROM transactions 
   WHERE type='withdraw' AND user_id=YOUR_ID
   ORDER BY created_at DESC LIMIT 1;
```

### 5️⃣ تست Pause/Resume:

```
1. /startgame
2. /next (2-3 بار)
3. /pause
   → بازی متوقف میشه
4. /resume
   → ادامه پیدا می‌کنه از همون جا
5. /next
   → پرتاب بعدی
```

## 💡 نکات مهم:

### 1. Backward Compatibility
جدول قدیمی `withdraw_requests` هنوز وجود داره برای backup.
اگه همه چیز درست کار کرد، می‌تونی حذفش کنی:
```sql
DROP TABLE withdraw_requests;
```

### 2. موجودی همیشه sync هست
موجودی کاربر (`users.coins`) همیشه با مجموع transaction ها sync هست:
```
Balance = 1000 (start) + SUM(win,deposit,bonus,refund) - SUM(withdraw)
```

### 3. Foreign Keys
حذف golden number → همه transaction های مرتبط خودکار حذف میشن (CASCADE)

### 4. Performance
همه query ها از index استفاده می‌کنن:
- `idx_user_id`
- `idx_type`
- `idx_golden_id`
- `idx_session_id`
- `idx_created_at`

## 🎉 آماده برای استفاده!

همه چیز پیاده‌سازی شده و آماده تست هست.

**مراحل بعدی:**
1. ✅ اجرای migration
2. ✅ تست commands
3. ✅ چک دیتابیس
4. ⏳ بعداً: Admin panel برای مدیریت transactions
5. ⏳ بعداً: Export transactions به CSV

**سوال یا مشکلی داری؟ بگو تا حلش کنیم!** 🚀
