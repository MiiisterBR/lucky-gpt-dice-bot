# Telegram Dice Game Bot (PHP 8 + MySQL) — Full Setup & Deployment Guide

This project is a Telegram dice game bot with daily points and an hourly “Golden Number” generated via OpenAI. It is built in PHP (class-based, SOLID-ish layering) and uses MySQL for persistence.

## Features
- **Daily points**: each user gets 100 points per day (configurable).
- **Dice roll**: each roll costs 5 points (configurable) using Telegram `sendDice`.
- **Golden Number**: generated hourly via OpenAI; correct guess awards +100 points.
- **Buttons**: Start, Leaderboard, Status.
- **Commands**: `/start`, `/guess 123`, `/leaderboard`, `/status`.
- **Admin panel**: Tailwind dashboard with first-run admin registration.

## Requirements
- **PHP** 8.1+ with PDO MySQL extension
- **MySQL/MariaDB**
- **Composer**
- **Web server**: Apache (with `mod_rewrite`, `AllowOverride All`) or Nginx (see config below)
- **Valid HTTPS** (SSL certificate) for Telegram webhook

## Folder Structure
```
telegram-game-bot/
├─ public/
│  └─ game.php                  # Telegram webhook entry
│  └─ api/roll.php              # Internal roll endpoint
│  └─ cron/generate-golden.php  # Hourly golden number cron
├─ src/
│  ├─ App.php                   # bootstrap / env / PDO / settings
│  ├─ Controllers/TelegramController.php
│  ├─ Services/{Telegram,OpenAI,Game,User}Service.php
│  ├─ Repositories/{User,Golden}Repository.php
│  └─ Models/{User,Roll,Guess}.php
├─ admin/index.php              # Admin panel (Tailwind)
├─ migrations/game.sql          # DB schema and seeds
├─ .env.example
├─ composer.json
└─ README.md
```

## Step-by-step Setup

### 1) Upload/Clone code on your host
- Place the project under your domain path (e.g., `mindroll.misterbr.ir`).
- Prefer setting your web server docroot to the `public/` directory.

### 2) Install dependencies
```bash
composer install
```

### 3) Create database and import schema
- Create database (e.g., `telegram_game`).
- Import `migrations/game.sql` using phpMyAdmin or CLI:
```bash
mysql -u <DB_USER> -p -h <DB_HOST> <DB_NAME> < migrations/game.sql
```

### 4) Configure environment
- Copy `.env.example` to `.env` and fill values:
```
TELEGRAM_BOT_TOKEN=123456:ABCDEF...
OPENAI_API_KEY=sk-...
OPENAI_MODEL=gpt-5

DB_HOST=localhost
DB_NAME=telegram_game
DB_USER=root
DB_PASS=

ADMIN_PASSWORD_HASH=

DAILY_POINTS=100
DICE_COST=5
```
- Optional admin via `.env`: generate a hash to allow username `admin` to login:
```bash
php -r "echo password_hash('YourStrongPassword', PASSWORD_BCRYPT), PHP_EOL;"
```
Set the output into `ADMIN_PASSWORD_HASH`.

### 5) Configure web server & HTTPS
- If using Apache, enable `mod_rewrite` and `AllowOverride All`.
- Recommended: Docroot → `public/`. We also provide `/.htaccess` and `public/.htaccess` for:
  - Force HTTPS
  - Remove `www`
  - Disable directory listing
  - Block sensitive files (`.env`, `composer.json`, etc.)

### 6) Create bot in BotFather and set webhook
1. In Telegram, open `@BotFather`.
2. Send `/newbot` and follow prompts to get your bot token.
3. Set the webhook URL (example domain: `mindroll.misterbr.ir`):
   - If docroot is `public/`:
     ```
     https://api.telegram.org/bot<YOUR_TOKEN>/setWebhook?url=https://mindroll.misterbr.ir/game.php&drop_pending_updates=true
     ```
   - If docroot is project root:
     ```
     https://api.telegram.org/bot<YOUR_TOKEN>/setWebhook?url=https://mindroll.misterbr.ir/public/game.php&drop_pending_updates=true
     ```
4. (Optional) Set bot description and commands in BotFather.
   - In BotFather:
     - `/setdescription` → paste a short description, e.g.:
       > Roll the dice, earn points daily, and guess the hourly golden number!
     - `/setcommands` → paste the commands list:
       ```
       start - Start the game and show buttons
       leaderboard - Show top players
       status - Show your points and remaining rolls
       guess - Guess the golden 3-digit number (e.g. /guess 123)
       ```

### 7) First admin registration (first-run)
- Open the admin panel:
  - Docroot=root → `https://yourdomain.com/admin`
  - Docroot=public → move `admin/` under public or use a separate vhost; otherwise, set docroot to project root.
- If `admin_users` is empty, you’ll see the “First Admin Registration” form.
- Submit email/password → account is created and you are logged in.
- After the first admin exists, the registration form will never show again; you’ll see the login form instead.

### 8) Cron job (hourly Golden Number)
- Linux crontab:
```cron
0 * * * * php /path/to/project/public/cron/generate-golden.php > /dev/null 2>&1
```
- cPanel → Cron Jobs → add the command above (adjust the path).
- Windows (Task Scheduler) → create a task to run:
```
Program/script: php
Arguments: C:\\path\\to\\project\\public\\cron\\generate-golden.php
```

### 9) Test the bot
- Send `/start` to your bot.
- Try `/guess 123`, `/leaderboard`, `/status`.
- Tap inline buttons: Start, Leaderboard, Status.

## Deployment Examples

### A) Shared hosting (cPanel + Apache)
- Upload project to your domain folder.
- If possible, set document root to `project/public/` (Domains → Document Root).
- Run `composer install` (cPanel Terminal or local then upload `vendor/`).
- Create DB and import `migrations/game.sql` via phpMyAdmin.
- Create `.env` from `.env.example` and fill values.
- Set webhook as in step 6.
- Add Cron in cPanel as in step 8.

### B) VPS (Ubuntu) + Apache
- Install Apache, PHP 8.1+, MySQL, Composer.
- VirtualHost example (docroot to `public/`):
```apache
<VirtualHost *:80>
    ServerName yourdomain.com
    Redirect permanent / https://yourdomain.com/
</VirtualHost>

<VirtualHost *:443>
    ServerName yourdomain.com
    DocumentRoot /var/www/project/public
    <Directory /var/www/project/public>
        AllowOverride All
        Require all granted
    </Directory>
    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/yourdomain.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/yourdomain.com/privkey.pem
</VirtualHost>
```

### C) VPS (Ubuntu) + Nginx
- Sample server block (docroot to `public/`):
```nginx
server {
  listen 80;
  server_name yourdomain.com www.yourdomain.com;
  return 301 https://yourdomain.com$request_uri;
}

server {
  listen 443 ssl http2;
  server_name yourdomain.com;
  root /var/www/project/public;
  index index.php;

  ssl_certificate /etc/letsencrypt/live/yourdomain.com/fullchain.pem;
  ssl_certificate_key /etc/letsencrypt/live/yourdomain.com/privkey.pem;

  location / {
    try_files $uri $uri/ /index.php?$query_string;
  }

  location ~ \.php$ {
    include snippets/fastcgi-php.conf;
    fastcgi_pass unix:/run/php/php8.2-fpm.sock;
  }

  # Block sensitive files
  location ~ /\.(env|git|htaccess|gitignore) { deny all; }
}
```

### D) Local (Laragon/XAMPP)
- Place project in the web root (e.g., `laragon/www/mindroll`).
- Create DB, import `migrations/game.sql`.
- Copy `.env.example` → `.env` and set credentials.
- `composer install`.
- Access `http://mindroll.test` (Laragon) or `http://localhost/mindroll/public`.

## API Endpoints
| Method | Endpoint                | Description                                  |
| ------ | ----------------------- | -------------------------------------------- |
| POST   | `/webhook`              | Telegram webhook (actually `public/game.php`) |
| POST   | `/api/roll`             | Internal endpoint for rolling dice            |
| POST   | `/cron/generate-golden` | Cron job for generating hourly golden number  |
| GET    | `/admin`                | Admin login/dashboard                         |

## Security & Best Practices
- Keep secrets in `.env` (already `.gitignore`).
- Ensure HTTPS and valid certificate (Telegram requires it).
- Apache: `mod_rewrite` + `AllowOverride All`; Nginx: use server block above.
- Our `.htaccess` enforces HTTPS, removes `www`, disables directory listing, and blocks sensitive files.
- Sanitize inputs; use prepared statements (already used via PDO prepared statements).

## Troubleshooting
- **Webhook not triggered**: check `getWebhookInfo`, verify URL is HTTPS and reachable; confirm docroot path to `public/`.
- **500 errors**: check `vendor/` installed, PHP version, DB credentials in `.env`.
- **DB errors**: ensure DB exists and `migrations/game.sql` imported; verify privileges.
- **Admin panel not loading**: ensure correct docroot. If docroot is `public/`, move `admin/` under `public/` or set a separate vhost with root at project directory.
- **OpenAI errors**: if API fails, fallback 3-digit number is used automatically.

## License
MIT (see `composer.json`).
