#!/usr/bin/env bash
# HeadFord Sender - Cloudways par ek baar chalao:  bash scripts/setup-cloudways.sh
# Sawaal poochega, .env banayega, database tables banayega, aur PC ke liye token dikhayega.
set -e
cd "$(dirname "$0")/.."
echo "== HeadFord Sender setup =="
if [ -f .env ] && grep -q '^SENDER_API_TOKEN=.\+' .env; then
  echo ".env pehle se bana hai. Sirf database update kar raha hoon."
else
  read -rp "Website address (jaise https://sender.headfordtech.com): " URL
  read -rp "Database name (Cloudways > Application > Access Details): " DBN
  read -rp "Database username: " DBU
  read -rsp "Database password: " DBP; echo
  read -rsp "Dashboard ka password (khud socho, 10+ akshar): " DPW; echo
  read -rp "Claude API key (sk-ant-..., abhi nahi hai to Enter): " CK
  TOKEN=$(php -r 'echo bin2hex(random_bytes(24));')
  cp .env.example .env
  set_env() { php -r '$f=".env";$k=$argv[1];$v=$argv[2];$s=file_get_contents($f);$l=$k."=".(preg_match("/[\\s#\"]/",$v)?"\"".addcslashes($v,"\"\\\\")."\"":$v);$s=preg_match("/^".$k."=.*$/m",$s)?preg_replace("/^".$k."=.*$/m",addcslashes($l,"\\\\$"),$s):$s."\n".$l;file_put_contents($f,$s);' "$1" "$2"; }
  set_env APP_URL "$URL"; set_env DB_DATABASE "$DBN"; set_env DB_USERNAME "$DBU"; set_env DB_PASSWORD "$DBP"
  set_env DASHBOARD_PASSWORD "$DPW"; set_env CLAUDE_API_KEY "$CK"; set_env SENDER_API_TOKEN "$TOKEN"
  set_env SESSION_SECURE_COOKIE true
  php artisan key:generate --force
fi
mkdir -p storage/app/private storage/framework/{cache,sessions,views} storage/logs
chmod -R ug+rwX storage bootstrap/cache 2>/dev/null || true
php artisan migrate --force
php artisan config:clear >/dev/null 2>&1 || true
php artisan config:cache 2>/dev/null || true
php artisan route:cache 2>/dev/null || true
php artisan view:cache 2>/dev/null || true
echo
echo "=================== HO GAYA ==================="
echo "PC Lead Manager > Instructions > 5. Cloud sender mein daalo:"
echo "  Cloud sender ka address : $(grep '^APP_URL=' .env | cut -d= -f2- | tr -d '\"')"
echo "  Cloud sender API token  : $(grep '^SENDER_API_TOKEN=' .env | cut -d= -f2- | tr -d '\"')"
echo "Cron (Cloudways > Cron Job Management > Advanced) mein yeh ek line:"
echo "  * * * * * cd $(pwd) && php artisan schedule:run >> /dev/null 2>&1"
echo "==============================================="
