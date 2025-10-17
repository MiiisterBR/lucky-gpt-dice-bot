# Cron Jobs Setup Guide

## Overview

این پروژه نیاز به **2 Cron Job** داره که روزانه اجرا بشن:

1. **Quiet Hours Start** (ساعت 23:00) - اعلام غیرفعال شدن بات
2. **Generate Golden Number** (ساعت 00:00) - تولید عدد طلایی جدید

## 📋 Prerequisites

- دسترسی به crontab سرور
- PHP CLI نصب شده
- Timezone در Admin Panel تنظیم شده باشه

## ⚙️ تنظیم Timezone

قبل از تنظیم Cron ها، حتماً **Timezone** رو از پنل ادمین تنظیم کن:

1. برو `/admin`
2. کلیک **Edit Settings**
3. انتخاب Timezone (مثلاً `Asia/Tehran` برای ایران)
4. **Save Settings**

## 🕐 Cron Job #1: Quiet Hours Start (23:00)

**هدف:** اطلاع‌رسانی به کاربران که بات از ساعت 23:00 تا 00:00 غیرفعال می‌شه.

### کد Cron:
```bash
0 23 * * * php /path/to/project/public/cron/quiet-hours-start.php >> /path/to/project/storage/logs/cron-quiet.log 2>&1
```

### توضیحات:
- **0 23 * * *** = هر روز ساعت 23:00
- **php .../quiet-hours-start.php** = اجرای اسکریپت PHP
- **>> .../cron-quiet.log** = ذخیره خروجی در log
- **2>&1** = ذخیره خطاها هم در همون log

### عملکرد:
1. پیام اطلاع‌رسانی به همه کاربران می‌فرسته
2. می‌گه که بات از 23:00 تا 00:00 غیرفعاله
3. کاربران می‌تونن بازی‌های فعلی رو تموم کنن
4. نمی‌تونن بازی جدید شروع کنن

### پیام نمونه:
```
🌙 Bot Maintenance Notice

The bot will be inactive from 23:00 to 00:00 for daily maintenance.

⚠️ You cannot start new games during this time.
✅ Ongoing games can still be completed.

🕐 We'll be back at 00:00 with a fresh new Golden Number!
See you soon! 🎲
```

## 🕛 Cron Job #2: Generate Golden Number (00:00)

**هدف:** تولید عدد طلایی 7 رقمی جدید و اعلام به همه کاربران.

### کد Cron:
```bash
0 0 * * * php /path/to/project/public/cron/generate-golden.php >> /path/to/project/storage/logs/cron-golden.log 2>&1
```

### توضیحات:
- **0 0 * * *** = هر روز ساعت 00:00 (نیمه‌شب)
- **php .../generate-golden.php** = اجرای اسکریپت PHP
- **>> .../cron-golden.log** = ذخیره خروجی در log

### عملکرد:
1. عدد طلایی 7 رقمی جدید تولید می‌کنه (با OpenAI یا fallback تصادفی)
2. عدد رو در دیتابیس ذخیره می‌کنه
3. پیام اعلام به همه کاربران می‌فرسته
4. عدد رو به عنوان "announced" علامت می‌زنه

### پیام نمونه (تولید شده با ChatGPT):
```
The new golden number is ready! Try your luck and start a new game with /startgame and roll up to 7 times.
```

## 🛠️ مراحل تنظیم (Linux/Ubuntu)

### 1. باز کردن Crontab:
```bash
crontab -e
```

### 2. اضافه کردن هر دو Cron Job:
```bash
# Quiet Hours Start (23:00)
0 23 * * * php /var/www/html/mindroll/public/cron/quiet-hours-start.php >> /var/www/html/mindroll/storage/logs/cron-quiet.log 2>&1

# Generate Golden Number (00:00)
0 0 * * * php /var/www/html/mindroll/public/cron/generate-golden.php >> /var/www/html/mindroll/storage/logs/cron-golden.log 2>&1
```

**نکته:** مسیر `/var/www/html/mindroll` رو با مسیر واقعی پروژه‌ت جایگزین کن.

### 3. ذخیره و خروج:
- در **nano**: `Ctrl + X` → `Y` → `Enter`
- در **vim**: `ESC` → `:wq` → `Enter`

### 4. چک کردن Cron ها:
```bash
crontab -l
```

## 🪟 مراحل تنظیم (Windows - Task Scheduler)

### برای Quiet Hours Start (23:00):

1. باز کن: **Task Scheduler** (جستجو در Start Menu)
2. کلیک **Create Basic Task**
3. نام: `Bot Quiet Hours Start`
4. Trigger: **Daily** → ساعت **23:00**
5. Action: **Start a program**
   - Program: `C:\laragon\bin\php\php-8.x\php.exe`
   - Arguments: `D:\Programming\WebSrv\laragon\www\mindroll\public\cron\quiet-hours-start.php`
6. **Finish**

### برای Generate Golden Number (00:00):

1. **Create Basic Task**
2. نام: `Bot Generate Golden Number`
3. Trigger: **Daily** → ساعت **00:00** (midnight)
4. Action: **Start a program**
   - Program: `C:\laragon\bin\php\php-8.x\php.exe`
   - Arguments: `D:\Programming\WebSrv\laragon\www\mindroll\public\cron\generate-golden.php`
5. **Finish**

**نکته:** مسیر PHP رو بر اساس نصب Laragon یا XAMPP خودت تنظیم کن.

## 📊 بررسی Log ها

### چک کردن Quiet Hours Log:
```bash
tail -f storage/logs/cron-quiet-start.log
```

نمونه خروجی:
```
[2025-10-17 23:00:01] Sent: 125, Failed: 2
```

### چک کردن Generate Golden Log:
```bash
tail -f storage/logs/cron-generate-golden.log
```

نمونه خروجی:
```
[2025-10-18 00:00:03] Generated: 3847261, Sent: 127, Failed: 1
```

## 🧪 تست دستی (قبل از تنظیم Cron)

### تست Quiet Hours:
```bash
php public/cron/quiet-hours-start.php
```

خروجی موفق:
```
Quiet hours notification sent to 150 users (2 failed)
```

### تست Generate Golden:
```bash
php public/cron/generate-golden.php
```

خروجی موفق:
```
Golden number generated: 5732819 | Sent to 148 users (4 failed)
```

## ⚠️ مشکلات احتمالی

### 1. Cron اجرا نمیشه
**راه‌حل:**
- چک کن PHP CLI نصب باشه: `php -v`
- مسیر PHP رو کامل بنویس: `/usr/bin/php` یا `C:\laragon\bin\php\php.exe`

### 2. Permission denied
**راه‌حل:**
```bash
chmod +x public/cron/quiet-hours-start.php
chmod +x public/cron/generate-golden.php
chmod -R 777 storage/logs/
```

### 3. Timezone اشتباه
**راه‌حل:**
- برو `/admin` → Edit Settings
- Timezone رو درست انتخاب کن
- مثلاً برای ایران: `Asia/Tehran`

### 4. هیچ پیامی ارسال نمیشه
**راه‌حل:**
- چک کن `TELEGRAM_BOT_TOKEN` در `.env` درست باشه
- بررسی کن که کاربران در دیتابیس وجود داشته باشن
- Log ها رو چک کن: `storage/logs/cron-*.log`

## 📝 نکات مهم

1. **Timezone حیاتیه:** حتماً از پنل ادمین تنظیمش کن
2. **Test کن:** قبل از تنظیم Cron، دستی تست کن
3. **Log ها رو چک کن:** برای دیباگ مشکلات
4. **Rate Limit:** بین پیام‌ها 50ms تأخیر هست تا Telegram block نکنه

## 🎯 Summary

| Cron Job | زمان | فایل | هدف |
|----------|------|------|-----|
| **Quiet Hours** | 23:00 | `quiet-hours-start.php` | اعلام غیرفعال شدن |
| **Generate Golden** | 00:00 | `generate-golden.php` | تولید عدد طلایی |

بعد از تنظیم، هر شب:
- ⏰ 23:00 → پیام quiet hours
- 🚫 23:00-00:00 → بازی جدید ممنوع
- ✅ 00:00 → عدد طلایی جدید + پیام به همه

**همه چی آماده است!** 🚀
