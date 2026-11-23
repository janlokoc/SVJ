#!/bin/bash
# Zpřístupní web spuštěný na tomto počítači přes internet (HTTPS) pomocí Cloudflare Tunnel – na zkoušení z iPadu,
# telefonu nebo pro další lidi. Zdarma a bez účtu u Cloudflare; adresa je dočasná a při každém spuštění jiná.
#
# Jednou předem:  brew install cloudflared
#                 do storage/config.php přidat do 'platform' volbu 'tunnel' => true (viz config.example.php)
# Spuštění:       ve složce webu  ./tools/tunel.sh      (ukončení: Ctrl+C)
set -u
cd "$(dirname "$0")/.." || exit 1
PORT="${PORT:-8000}"

if ! command -v cloudflared >/dev/null 2>&1; then
  echo "Chybí program cloudflared. Nainstalujte ho příkazem:  brew install cloudflared"
  exit 1
fi
if [ -f storage/config.php ] && grep -q "'platform'" storage/config.php && ! grep -q "'tunnel'" storage/config.php; then
  echo "Upozornění: v storage/config.php chybí v části 'platform' volba 'tunnel' => true."
  echo "Bez ní platforma adresu tunelu nepozná. Doplňte ji a skript spusťte znovu."
  exit 1
fi

# Server PHP – pokud už na portu neběží, spustí se a po skončení skriptu se zase vypne.
PHP_PID=""
if ! lsof -iTCP:"$PORT" -sTCP:LISTEN >/dev/null 2>&1; then
  php -S 127.0.0.1:"$PORT" index.php >/tmp/svj-php.log 2>&1 &
  PHP_PID=$!
  sleep 1
fi
LOG=$(mktemp -t svj-tunel)
cleanup() { [ -n "$PHP_PID" ] && kill "$PHP_PID" 2>/dev/null; kill "${CF_PID:-0}" 2>/dev/null; rm -f "$LOG"; }
trap cleanup EXIT INT TERM

cloudflared tunnel --no-autoupdate --url "http://127.0.0.1:$PORT" >"$LOG" 2>&1 &
CF_PID=$!
echo "Spouštím tunel…"
URL=""
for _ in $(seq 1 40); do
  URL=$(grep -oE 'https://[a-z0-9-]+\.trycloudflare\.com' "$LOG" | head -1)
  [ -n "$URL" ] && break
  sleep 1
done
if [ -z "$URL" ]; then
  echo "Tunel se nepodařilo spustit. Výpis cloudflared:"; tail -20 "$LOG"; exit 1
fi
echo
echo "Web je dostupný na:  $URL"
if [ -f storage/config.php ] && grep -q "'platform'" storage/config.php; then
  echo "  konzole platformy:  $URL/sprava"
  echo "  web společenství:   $URL/<adresa-společenství>/   (např. $URL/demo/)"
fi
echo
echo "Adresa platí, dokud běží tento skript a počítač je zapnutý a nespí. Ukončení: Ctrl+C"
wait "$CF_PID"
