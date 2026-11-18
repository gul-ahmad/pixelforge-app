# Deploying to the office server

Target: `http://YOUR_SERVER_IP:8200` on the shared Ubuntu 24.04 server, following the server
admin's rules. Other apps on this server must not be affected.

| Choice                                                          | Why                                                                                                                                                                                                                        |
| --------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **PHP 8.4 next to 8.2**                                         | Laravel 13 / Symfony 8 need PHP ≥ 8.4.1. The server default stays 8.2; this app always calls `php8.4` explicitly.                                                                                                          |
| **SQLite**                                                      | No MySQL on the server. `config/database.php` runs SQLite with `IMMEDIATE` transactions + WAL, so concurrent updates are serialized (our "charge exactly once" guarantee). The test suite passes on both MySQL and SQLite. |
| **Cron runs the queue worker**                                  | No Supervisor. Cron starts `queue:work --max-time=55` every minute; `flock` keeps it to one worker.                                                                                                                        |
| **Basic auth on the whole site, except `/webhooks/pixelforge`** | Admin's template. Pixelforge can't send a password; the webhook is authenticated by its HMAC signature in the app instead.                                                                                                 |

Run everything below in **your own SSH session** (`ssh YOUR_SSH_USER@YOUR_SERVER_IP`). Type passwords
carefully: 5 wrong attempts in 10 minutes bans your IP (fail2ban).

Each step lists what you should see. **If you see anything else, stop and send me the output.**

---

## 1. Install PHP 8.4 next to 8.2 (admin's procedure)

```bash
sudo apt-get update
sudo apt-get install -s php8.4-fpm php8.4-cli php8.4-common php8.4-mbstring \
  php8.4-xml php8.4-curl php8.4-zip php8.4-bcmath php8.4-intl php8.4-gd php8.4-sqlite3
```

**STOP if** the dry run says it will **remove** anything, or mentions any `php8.2-*` package.
If it's clean, run the same `install` command **without `-s`**. Then:

```bash
php -v        # must still say PHP 8.2
php8.4 -v     # PHP 8.4
```

If `php -v` says 8.4: `sudo update-alternatives --set php /usr/bin/php8.2`

Disable the default 8.4 pool (it serves nothing):

```bash
sudo mv /etc/php/8.4/fpm/pool.d/www.conf /etc/php/8.4/fpm/pool.d/www.conf.disabled
```

## 2. App user and code

```bash
id your-app-user || sudo useradd --create-home --shell /usr/sbin/nologin your-app-user
sudo mkdir -p /var/www/your-app/pixelforge
sudo chown your-app-user:your-app-user /var/www/your-app/pixelforge
sudo chmod 750 /var/www/your-app/pixelforge
sudo -u your-app-user -H git clone https://github.com/gul-ahmad/pixelforge-app.git /var/www/your-app/pixelforge
```

Expected: `Cloning into '/var/www/your-app/pixelforge'... done.`

## 3. Production `.env`

```bash
cd /var/www/your-app/pixelforge
sudo -u your-app-user cp deploy/env.production.example .env
sudo -u your-app-user chmod 600 .env
sudo -u your-app-user nano .env
```

In nano, fill in **only** these two lines with the **production** key and its `whsec_` secret,
then save (Ctrl+O, Enter) and exit (Ctrl+X):

```
PIXELFORGE_API_KEY=pf_...
PIXELFORGE_WEBHOOK_SECRET=whsec_...
```

## 4. Build the app

```bash
sudo -u your-app-user -H bash /var/www/your-app/pixelforge/deploy/deploy.sh
```

Expected: it ends with `==> Done` (composer, npm build, migrations, caches).

## 5. Let Nginx read only `public/`

```bash
sudo setfacl -m u:www-data:x /var/www/your-app/pixelforge
sudo setfacl -R -m u:www-data:rX /var/www/your-app/pixelforge/public
sudo setfacl -R -d -m u:www-data:rX /var/www/your-app/pixelforge/public   # also for files created by later builds
```

## 6. PHP-FPM pool (PHP 8.4 only)

```bash
sudo cp /var/www/your-app/pixelforge/deploy/php-fpm/your-app-user.conf /etc/php/8.4/fpm/pool.d/your-app-user.conf
sudo php-fpm8.4 -t && sudo systemctl reload php8.4-fpm
ls -l /run/php/your-app-user.sock
```

Expected: `test is successful`, and the socket file exists. Never touch `php8.2-fpm`.

## 7. Nginx site on port 8200

```bash
sudo cp /var/www/your-app/pixelforge/deploy/nginx/your-app-user.conf /etc/nginx/sites-available/your-app-user
sudo ln -s /etc/nginx/sites-available/your-app-user /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

Expected: `syntax is ok` / `test is successful`. **If `nginx -t` fails**, remove only our link
(`sudo rm /etc/nginx/sites-enabled/your-app-user`), run `sudo nginx -t` again, and send me the error.

## 8. Basic-auth password for reviewers

```bash
sudo htpasswd -c /etc/nginx/your-app-user.htpasswd client
```

## 9. Open port 8200 (only ADD a rule)

```bash
sudo ufw allow 8200/tcp comment 'temp project - remove after'
sudo ufw status numbered
```

## 10. Scheduler and queue worker (cron)

```bash
sudo crontab -u your-app-user /var/www/your-app/pixelforge/deploy/crontab.txt
sudo crontab -u your-app-user -l
```

## 11. Check it works (from the server)

```bash
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8200/up                        # 401 (password required)
curl -s -o /dev/null -w "%{http_code}\n" -u client http://127.0.0.1:8200/up              # 200 (asks for the password)
curl -s -o /dev/null -w "%{http_code}\n" -X POST http://127.0.0.1:8200/webhooks/pixelforge  # 400 (reached the app; unsigned)
```

The third must be **400**, not 401: that proves webhooks bypass basic auth and reach the app,
which rejects unsigned requests.

Then the admin's final checks: **http://YOUR_SERVER_IP:8100** and **http://YOUR_SERVER_IP:8101/login**
must still open, and `php -v` must still say 8.2.

## 12. Connect Pixelforge (production key)

In the Pixelforge dashboard, on the **production** key, set the webhook URL to
`http://YOUR_SERVER_IP:8200/webhooks/pixelforge` and press **Send test event**. Then on the server:

```bash
sudo -u your-app-user php8.4 /var/www/your-app/pixelforge/artisan tinker --execute='echo App\Models\WebhookEvent::latest("id")->value("type");'
```

Expected: `ping`.

## 13. Test from outside

On a phone using **mobile data** (not office Wi-Fi), open `http://YOUR_SERVER_IP:8200`: it should ask
for the password, then show the app. If it doesn't open from outside, the university network may
block 8200. **Stop and report**; don't try other ports.

---

## 14. Admin access (operator report)

Register the account first on the site, then grant it:

```bash
sudo -u your-app-user php8.4 /var/www/your-app/pixelforge/artisan users:make-admin you@example.com
```

The **Admin report** link then appears in the sidebar (`/admin/report`).

## Updating later

```bash
sudo -u your-app-user -H bash /var/www/your-app/pixelforge/deploy/deploy.sh
```

## Where to look when something breaks

- `/var/log/nginx/your-app-user.error.log`
- `/var/www/your-app/pixelforge/storage/logs/laravel-YYYY-MM-DD.log`
- `/var/www/your-app/pixelforge/storage/logs/worker.log`

## Removing everything afterwards

```bash
sudo crontab -u your-app-user -r
sudo rm /etc/nginx/sites-enabled/your-app-user /etc/nginx/sites-available/your-app-user /etc/nginx/your-app-user.htpasswd
sudo nginx -t && sudo systemctl reload nginx
sudo rm /etc/php/8.4/fpm/pool.d/your-app-user.conf
sudo ufw delete allow 8200/tcp
sudo rm -rf /var/www/your-app/pixelforge
sudo userdel -r your-app-user
# PHP 8.4, as the admin described (check the autoremove dry run list first):
sudo apt-get purge 'php8.4*' && sudo apt-get autoremove --purge -s
```
