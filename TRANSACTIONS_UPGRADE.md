# 🔄 Transactions System Upgrade Guide

این راهنما برای آپگرید سیستم به **Transaction-Based Architecture** است که تمام مشکلات tracking و accountability رو حل می‌کنه.

## 🎯 چی تغییر کرده؟

### قبل از آپگرید ❌:
- موجودی فقط یه عدد بود (`users.coins`)
- تاریخچه برد/باخت وجود نداشت
- برداشت موجودی کم نمی‌کرد
- لیدربورد غیردقیق بود
- نمیشد transaction ها رو track کرد
- اگه golden number حذف میشد، data های مرتبط باقی می‌موندن

### بعد از آپگرید ✅:
- ✅ همه transaction ها ثبت میشن (win, loss, deposit, withdraw)
- ✅ تاریخچه کامل برای هر کاربر
- ✅ برداشت به صورت اتمیک موجودی کم می‌کنه
- ✅ لیدربورد از transaction ها محاسبه میشه
- ✅ Cascade delete برای golden numbers
- ✅ Pause/Resume session با حفظ throws_remaining
- ✅ Refund خودکار برای withdraw های ناموفق

## 📊 جدول جدید: `transactions`

```sql
CREATE TABLE transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT NOT NULL,
    type ENUM('deposit', 'withdraw', 'win', 'loss', 'bonus', 'refund'),
    amount INT NOT NULL DEFAULT 0,
    golden_id INT NULL,
    session_id INT NULL,
    description VARCHAR(255) NULL,
    status ENUM('pending', 'completed', 'failed', 'cancelled'),
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    
    FOREIGN KEY (golden_id) REFERENCES golden_numbers(id) ON DELETE CASCADE,
    FOREIGN KEY (session_id) REFERENCES game_sessions(id) ON DELETE SET NULL
);
```

## 🔧 تغییرات در `game_sessions`:

```sql
ALTER TABLE game_sessions 
    ADD COLUMN throws_remaining INT NOT NULL DEFAULT 7,
    ADD COLUMN paused TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN paused_at TIMESTAMP NULL;
```

## 🚀 مراحل آپگرید

### مرحله 1: Backup دیتابیس

```bash
mysqldump -u root -p mindroll > backup_before_transactions_$(date +%Y%m%d).sql
```

### مرحله 2: اجرای Migration

```bash
mysql -u root -p mindroll < migrations/transactions.sql
```

یا در phpMyAdmin:
1. باز کن `phpMyAdmin`
2. انتخاب database `mindroll`
3. تب **SQL** رو باز کن
4. محتوای فایل `migrations/transactions.sql` رو paste کن
5. کلیک **Go**

### مرحله 3: تست Migration

```sql
-- چک کن که جدول ساخته شده
SHOW TABLES LIKE 'transactions';

-- چک کن که ستون‌های جدید اضافه شدن
DESCRIBE game_sessions;

-- چک کن که foreign keys درست هستن
SHOW CREATE TABLE transactions;
```

### مرحله 4: Migrate کردن withdraw_requests موجود

این خودکار در migration انجام میشه، ولی می‌تونی چک کنی:

```sql
-- تعداد transaction ها
SELECT COUNT(*) FROM transactions WHERE type = 'withdraw';

-- تعداد withdraw_requests قدیمی
SELECT COUNT(*) FROM withdraw_requests;

-- باید برابر باشن (یا transactions بیشتر باشه اگه برخی duplicate بودن)
```

## 📝 فایل‌های تغییر یافته

### Repository ها:
- ✅ `src/Repositories/TransactionRepository.php` - **NEW**

### Services:
- ✅ `src/Services/GameService.php` - آپدیت شد:
  - Constructor حالا `TransactionRepository` می‌گیره
  - `startSession()` - `throws_remaining` و `paused` اضافه شد
  - `rollNext()` - transaction های win/loss ثبت میشه
  - `createWithdrawRequest()` - حالا موجودی کم میکنه و transaction میسازه
  - `processWithdrawTest()` - refund خودکار برای failed withdrawals
  - `pauseSession()` - **NEW**
  - `resumeSession()` - **NEW**

### Entry Points:
- ✅ `public/game.php` - `TransactionRepository` inject شد
- ✅ `public/cron/generate-golden.php` - `TransactionRepository` inject شد
- ✅ `admin/index.php` - `TransactionRepository` inject شد
- ✅ `admin/ajax.php` - `TransactionRepository` inject شد

## 🧪 تست کردن

### 1. تست Win Transaction:
```
1. شروع بازی جدید: /startgame
2. همه 7 پرتاب رو بزن: /next (7 بار)
3. اگه برنده شدی:
   - موجودی باید افزایش پیدا کنه
   - یک transaction با type='win' باید ثبت بشه
4. اگه بازنده شدی:
   - موجودی ثابت میمونه
   - یک transaction با type='loss' باید ثبت بشه
```

چک کن در دیتابیس:
```sql
SELECT * FROM transactions WHERE user_id = YOUR_USER_ID ORDER BY created_at DESC LIMIT 5;
```

### 2. تست Withdraw با کسر موجودی:
```
1. موجودی فعلی رو چک کن: /status
2. برداشت کن: /withdraw 100
3. موجودی جدید باید 100 کمتر باشه
4. تراکنش withdraw باید pending باشه
```

چک کن:
```sql
SELECT * FROM transactions WHERE type = 'withdraw' AND user_id = YOUR_USER_ID;
SELECT coins FROM users WHERE id = YOUR_USER_ID;
```

### 3. تست Cascade Delete:
```sql
-- حذف یک golden number
DELETE FROM golden_numbers WHERE id = 1;

-- همه transaction های مرتبط باید حذف بشن
SELECT * FROM transactions WHERE golden_id = 1;
-- نتیجه باید خالی باشه
```

### 4. تست Leaderboard:
```
1. برو /leaderboard
2. باید لیست برندگان رو بر اساس مجموع transaction های win نشون بده
3. دقیق‌تر از قبل هست چون از transaction table محاسبه میشه
```

## 📈 API های جدید

### Transaction Repository:

```php
// ساخت transaction
$transactions->create($userId, 'win', 500, $goldenId, $sessionId, 'Won 5/7 match');

// گرفتن تراکنش‌های کاربر
$userTransactions = $transactions->getByUser($userId, 10); // آخرین 10 تا

// مجموع برد/باخت
$totalWins = $transactions->getTotalWins($userId);
$totalLosses = $transactions->getTotalLosses($userId);
$winCount = $transactions->getWinCount($userId);
$lossCount = $transactions->getLossCount($userId);

// لیدربورد
$leaderboard = $transactions->getLeaderboardByWins(10);

// تراکنش‌های یک session
$sessionTx = $transactions->getBySession($sessionId);

// pending withdrawals
$pending = $transactions->getPendingWithdrawals();
```

### Game Service:

```php
// Pause/Resume session
$game->pauseSession($sessionId, $userId);
$game->resumeSession($sessionId, $userId);
```

## 🎮 فیچرهای جدید برای کاربران

### 1. Transaction History (آماده برای پیاده‌سازی در TelegramController):
```
/history - نمایش 10 تراکنش آخر
/stats - نمایش آمار کلی (تعداد برد، باخت، مجموع)
```

### 2. Pause/Resume Session (آماده برای پیاده‌سازی):
```
دکمه "Pause Game" - متوقف کردن بازی فعلی
/resume - ادامه بازی متوقف شده
```

## ⚠️ نکات مهم

### 1. Backward Compatibility:
- جدول `withdraw_requests` هنوز وجود داره (برای backup)
- می‌تونی بعد از اطمینان حذفش کنی: `DROP TABLE withdraw_requests;`

### 2. Performance:
- همه transaction queries از index استفاده می‌کنن
- Leaderboard حالا از aggregated query استفاده می‌کنه (سریع‌تر)

### 3. Data Integrity:
- Foreign key constraints اطمینان میدن که orphan data نداریم
- Cascade delete خودکار cleanup می‌کنه

### 4. Balance Consistency:
- موجودی همیشه با مجموع transactions sync هست:
```sql
SELECT 
    u.id,
    u.coins as current_balance,
    (
        COALESCE(SUM(CASE WHEN t.type IN ('win', 'deposit', 'bonus', 'refund') THEN t.amount ELSE 0 END), 0) -
        COALESCE(SUM(CASE WHEN t.type IN ('loss', 'withdraw') THEN t.amount ELSE 0 END), 0) + 1000
    ) as calculated_balance
FROM users u
LEFT JOIN transactions t ON u.id = t.user_id AND t.status = 'completed'
WHERE u.id = YOUR_USER_ID
GROUP BY u.id, u.coins;
```

## 🐛 Troubleshooting

### خطا: "Table transactions doesn't exist"
**راه‌حل:** Migration رو اجرا کن:
```bash
mysql -u root -p mindroll < migrations/transactions.sql
```

### خطا: "Column throws_remaining doesn't exist"
**راه‌حل:** ALTER TABLE ها رو اجرا کن:
```sql
ALTER TABLE game_sessions 
    ADD COLUMN throws_remaining INT NOT NULL DEFAULT 7,
    ADD COLUMN paused TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN paused_at TIMESTAMP NULL;
```

### خطا: "Cannot add foreign key constraint"
**راه‌حل:** مطمئن شو که:
1. جداول `golden_numbers` و `game_sessions` وجود دارن
2. ستون‌های referenced (id) همون type هستن

### موجودی کاربران inconsistent شده
**راه‌حل:** Recalculate کن:
```sql
UPDATE users u
SET coins = 1000 + (
    SELECT COALESCE(SUM(
        CASE 
            WHEN t.type IN ('win', 'deposit', 'bonus', 'refund') THEN t.amount
            WHEN t.type IN ('withdraw') THEN -t.amount
            ELSE 0
        END
    ), 0)
    FROM transactions t
    WHERE t.user_id = u.id AND t.status = 'completed'
);
```

## 📚 بعدی چی کار کنیم؟

1. ✅ پیاده‌سازی `/history` command در TelegramController
2. ✅ پیاده‌سازی `/stats` command برای نمایش آمار
3. ✅ پیاده‌سازی دکمه Pause/Resume در کیبورد
4. ✅ صفحه Transaction History در Admin Panel
5. ✅ Export کردن transactions به CSV/Excel
6. ✅ نمودار transaction ها در admin dashboard

## ✅ Checklist نهایی

- [ ] Migration اجرا شد
- [ ] جدول `transactions` ساخته شد
- [ ] ستون‌های جدید در `game_sessions` اضافه شدن
- [ ] Foreign keys کار می‌کنن
- [ ] تست win transaction
- [ ] تست loss transaction
- [ ] تست withdraw با کسر موجودی
- [ ] تست leaderboard
- [ ] تست cascade delete
- [ ] Backup از دیتابیس قبل از migration گرفته شد

**همه چیز آماده است! 🎉**
