# THE NON — deployment and launch guide

Built by RSGSoft for THE NON. Stack: Laravel 13, PHP 8.4, Livewire 4, Tailwind 4, MySQL 8.

## 1. Server requirements

- PHP 8.4 with extensions: `pdo_mysql`, `mbstring`, `xml`, `zip`, `fileinfo`, `curl`, `gd` or `imagick` (image uploads), `intl`
- MySQL 8.x (or MariaDB 10.6+)
- Node 20+ (only on the build machine, to run `npm run build`)
- A **public https domain** pointing to the `public/` folder. Telegram refuses plain http and non-public addresses, so the bot and the web app cannot work without it.
- A way to keep two background processes running (see section 5)

## 2. First install

```bash
git clone <repository> the-non && cd the-non
composer install --no-dev --optimize-autoloader
npm ci && npm run build
cp .env.example .env
php artisan key:generate
```

Edit `.env` (see section 3), create an empty MySQL database and user, then:

```bash
php artisan migrate --force
php artisan storage:link
php artisan crm:create-user you@company.uz --name="Your Name" --role=admin   # asks for a password
php artisan optimize
```

Do **not** run `db:seed` on production. The seeder is guarded and creates nothing there, because demo accounts use a well-known password.

## 3. Production `.env`

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://orders.example.uz
APP_TIMEZONE=Asia/Tashkent
APP_LOCALE=ru
APP_FALLBACK_LOCALE=ru
TRUSTED_PROXIES=*            # only if behind nginx/Cloudflare/a tunnel that terminates https

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=the_non
DB_USERNAME=the_non
DB_PASSWORD=<strong password>

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=none       # lets the web app keep its session inside Telegram Web (an iframe)
QUEUE_CONNECTION=database
CACHE_STORE=database

CRM_DEMO_LOGIN=false         # must stay false in production
CRM_SUPPORT_PHONE="+998 95 144 94 00"   # shown to employees; confirm with the client
CRM_REMINDER_MINUTES=30      # 0 disables the "ordering closes soon" reminder

TELEGRAM_BOT_TOKEN=<from BotFather>
TELEGRAM_BOT_USERNAME=TheNonuzBot
TELEGRAM_WEBHOOK_SECRET=<long random string, e.g. php -r "echo bin2hex(random_bytes(24));">
TELEGRAM_WEB_APP_URL=https://orders.example.uz/app
```

Keep `.env` out of git and readable only by the web user. Treat the bot token like a password; if it ever leaks, revoke it in BotFather.

## 4. Connect Telegram

1. In BotFather, check the bot name and description. (The Menu Button can also be set to the web app URL.)
2. Register the webhook:
   ```bash
   php artisan telegram:webhook https://orders.example.uz
   ```
3. Create an employee in the CRM, then open the bot, press `/start`, choose a language and share the phone number.

## 5. Background processes (required)

Without these, **no Telegram messages are sent** and reminders do not run.

**Queue worker** — sends the bot notifications. Run it under Supervisor/systemd (Linux) or NSSM / Task Scheduler (Windows):

```bash
php artisan queue:work --tries=3 --sleep=3 --max-time=3600
```

Supervisor example:

```ini
[program:the-non-queue]
command=php /var/www/the-non/artisan queue:work --tries=3 --sleep=3 --max-time=3600
autostart=true
autorestart=true
user=www-data
stopwaitsecs=3600
```

**Scheduler** — runs the cutoff reminder every minute. Add one cron line:

```cron
* * * * * cd /var/www/the-non && php artisan schedule:run >> /dev/null 2>&1
```

After every deploy, restart the worker so it loads new code: `php artisan queue:restart`.

## 6. Web server

Point the document root at `public/`. nginx essentials:

```nginx
server {
    server_name orders.example.uz;
    root /var/www/the-non/public;
    index index.php;
    client_max_body_size 10M;

    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ { fastcgi_pass unix:/run/php/php8.4-fpm.sock; include fastcgi_params;
                        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name; }
    location ~ /\.(?!well-known) { deny all; }
}
```

Enable https (Let's Encrypt / Cloudflare). Only `public/` may be web-accessible.

## 7. Backups

- **Database**, daily, keep 14+ days, copy off the server:
  ```cron
  0 3 * * * mysqldump --single-transaction -u the_non -p'<password>' the_non | gzip > /backups/the_non_$(date +\%F).sql.gz
  ```
- **Uploaded item pictures**: back up `storage/app/public/`.
- Test a restore once before launch.

## 8. Deploying an update

```bash
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan optimize
php artisan queue:restart
```

## 9. Pilot checklist (one company, one week)

Before the pilot:
- [ ] Items created with the **1C codes**, names in both languages, unit and order step
- [ ] Company created, items assigned (prices optional)
- [ ] Locations created; for each, the **cutoff schedule** set (Locations tab → Schedule)
- [ ] Employees created with correct phone numbers, linked to their locations, a **default employee** chosen per location
- [ ] Support phone confirmed with the client
- [ ] Webhook registered, queue worker and scheduler running
- [ ] Each pilot employee registered through the bot and opened the web app

Dry run (with internal staff, before real employees):
- [ ] Order → Complete → default employee receives the message
- [ ] Operator moves Accepted → Done → Sent → employee gets "confirm delivery" and confirms
- [ ] Second order the same day shows the "already ordered" warning
- [ ] After the cutoff, ordering is closed and the operator can still edit
- [ ] Deactivate an employee: access is lost on the next tap
- [ ] Reports page and Excel export match the orders; the API token works from 1C

During the pilot, check daily: failed jobs (`php artisan queue:failed`), `storage/logs/laravel.log`, and ask the operator which questions employees keep asking.

## 10. Open items to confirm with the client

- Support phone number and chat contact shown to employees
- Cutoff times per location and weekday
- Whether an unfinished draft at cutoff should stay a draft (current behavior), auto-complete, or be ignored
- Excel column layout expected by the factory and by 1C
- Whether 1C needs to write statuses back through the API (currently read-only)
- Expected scale (companies / locations / employees) for sizing
- Brand credit ("Built by RSGSoft") on the landing page, if wanted
