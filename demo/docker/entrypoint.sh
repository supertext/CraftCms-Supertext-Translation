#!/bin/bash
# Demo container start: database, install on first start, migrations, demo setup, Apache.
# Variables: demo/.env.example. Passwords are never printed.
set -euo pipefail
cd /app/demo/project

echo "[demo] Starting (database, migrations, demo setup)…"
eval "$(php /app/demo/docker/prepare-db.php)"

if [ -n "${RAILWAY_PUBLIC_DOMAIN:-}" ] && [ -z "${PRIMARY_SITE_URL:-}" ]; then
  export PRIMARY_SITE_URL="https://${RAILWAY_PUBLIC_DOMAIN}/"
fi
export PRIMARY_SITE_URL="${PRIMARY_SITE_URL:-http://localhost:${PORT:-8080}/}"
if [ -z "${CRAFT_SECURITY_KEY:-}" ]; then
  echo "[demo] CRAFT_SECURITY_KEY not set; using a random one (everyone is signed out on restart)."
  export CRAFT_SECURITY_KEY="$(php -r 'echo bin2hex(random_bytes(32));')"
fi
export CRAFT_APP_ID="${CRAFT_APP_ID:-supertext-craft-demo}"
export CRAFT_ENVIRONMENT="${CRAFT_ENVIRONMENT:-production}"

craft() { runuser -u www-data -- php craft "$@" --interactive=0; }

# Later `php craft` calls (docker exec, Railway shell) read the same settings from .env (container only).
env | grep -E '^(CRAFT_[A-Z_]+|PRIMARY_SITE_URL)=' | sed -E "s/^([A-Z_]+)=(.*)$/\1='\2'/" > .env
chown www-data:www-data .env && chmod 600 .env

chown -R www-data:www-data storage web config
mkdir -p web/cpresources && chown www-data:www-data web/cpresources

if ! craft install/check >/dev/null 2>&1; then
  # Craft has no "create admin" screen once installed. DEMO_ADMIN_* becomes the first admin;
  # without it, a throwaway installer admin (random password, never shown) that the demo
  # setup removes as soon as a real admin exists.
  if [ -n "${DEMO_ADMIN_EMAIL:-}" ] && [ -n "${DEMO_ADMIN_PASSWORD:-}" ]; then
    ADMIN_EMAIL="$DEMO_ADMIN_EMAIL"; ADMIN_PASSWORD="$DEMO_ADMIN_PASSWORD"
  else
    ADMIN_EMAIL="installer-$(php -r 'echo bin2hex(random_bytes(6));')@example.com"
    ADMIN_PASSWORD="$(php -r 'echo bin2hex(random_bytes(24));')"
  fi
  echo "[demo] Installing Craft…"
  if ! craft install/craft --email="$ADMIN_EMAIL" --username="$ADMIN_EMAIL" --password="$ADMIN_PASSWORD" \
      --site-name="Supertext Craft Demo" --site-url='$PRIMARY_SITE_URL' --language=en-US > /tmp/install.log 2>&1; then
    # e.g. DEMO_ADMIN_PASSWORD shorter than 6 characters: install with the throwaway admin instead.
    echo "[demo] Install with DEMO_ADMIN_* failed (check DEMO_ADMIN_EMAIL / DEMO_ADMIN_PASSWORD); using a throwaway admin."
    grep -v -i password /tmp/install.log | tail -5 || true
    ADMIN_EMAIL="installer-$(php -r 'echo bin2hex(random_bytes(6));')@example.com"
    ADMIN_PASSWORD="$(php -r 'echo bin2hex(random_bytes(24));')"
    craft install/craft --email="$ADMIN_EMAIL" --username="$ADMIN_EMAIL" --password="$ADMIN_PASSWORD" \
      --site-name="Supertext Craft Demo" --site-url='$PRIMARY_SITE_URL' --language=en-US > /dev/null
  fi
  rm -f /tmp/install.log
  unset ADMIN_EMAIL ADMIN_PASSWORD
fi

echo "[demo] Applying migrations and project config…"
if ! craft up > /tmp/up.log 2>&1; then
  echo "[demo] craft up failed:"; grep -v -i password /tmp/up.log | tail -20 || true; exit 1
fi
# `plugin/install` exits non-zero when the plugin is already installed.
craft plugin/install ckeditor > /dev/null 2>&1 || true
craft plugin/install supertext-translation > /dev/null 2>&1 || true
craft supertext-demo/setup
echo "[demo] Starting Apache on port ${PORT:-8080}."

# Exactly one Apache MPM (prefork, for mod_php).
rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.*
sed -ri "s/Listen [0-9]+/Listen ${PORT:-8080}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT:-8080}>/" /etc/apache2/sites-available/000-default.conf
exec apache2-foreground
