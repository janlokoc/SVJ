#!/bin/bash
# Zpřístupní web spuštěný na tomto počítači přes internet (HTTPS) pomocí Cloudflare Tunnel – na zkoušení z iPadu,
# telefonu nebo pro další lidi. Zdarma a bez účtu u Cloudflare; adresa je dočasná a při každém spuštění jiná.
#
# Jednou předem:  brew install cloudflared
#                 do storage/config.php přidat do 'platform' volbu 'tunnel' => true (viz config.example.php)
# Spuštění:       ve složce webu  ./tools/tunel.sh      (ukončení: Ctrl+C)
set -u
cd "$(dirname "$0")/.." || exit 1

if ! command -v cloudflared >/dev/null 2>&1; then
  echo "Chybí program cloudflared. Nainstalujte ho příkazem:  brew install cloudflared"
  exit 1
fi
if [ -f storage/config.php ] && grep -q "'platform'" storage/config.php && ! grep -q "'tunnel'" storage/config.php; then
  echo "Upozornění: v storage/config.php chybí v části 'platform' volba 'tunnel' => true."
  echo "Bez ní platforma adresu tunelu nepozná. Doplňte ji a skript spusťte znovu."
  exit 1
fi

cleanup() { kill "${PHP_PID:-0}" 2>/dev/null; kill "${CF_PID:-0}" 2>/dev/null; [ -n "${LOG:-}" ] && rm -f "$LOG"; }
trap cleanup EXIT INT TERM

# Vlastní server PHP pro tunel na volném portu – nikdy se nepřipojí k programu, který už na portu běží
# (mohl by to být jiný web). Data jsou stejná jako u webu spuštěného ručně, protože jde o tutéž složku storage.
port_busy() { lsof -iTCP:"$1" -sTCP:LISTEN >/dev/null 2>&1 || nc -z 127.0.0.1 "$1" >/dev/null 2>&1; }
if [ -n "${PORT:-}" ]; then
  if port_busy "$PORT"; then echo "Port $PORT už používá jiný program. Zvolte jiný (PORT=8790 ./tools/tunel.sh), nebo ho nechte vybrat skript."; exit 1; fi
else
  PORT=8780
  while port_busy "$PORT"; do PORT=$((PORT + 1)); done
fi
php -S 127.0.0.1:"$PORT" index.php >/tmp/svj-php.log 2>&1 &
PHP_PID=$!
sleep 1
# Kontrola, že na portu opravdu odpovídá tento web (zná ho podle souboru assets/app.js z této složky).
if ! curl -s "http://127.0.0.1:$PORT/assets/app.js" | grep -q "Menu administrace"; then
  echo "Server PHP se nespustil správně. Výpis:"; cat /tmp/svj-php.log; exit 1
fi
echo "Server PHP běží na portu $PORT (jen pro tunel; ukončí se spolu se skriptem)."
LOG=$(mktemp -t svj-tunel.XXXXXX)

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
  echo "  web společenství:   $URL/<adresa-společenství>/   (ukázka: $URL/demo/vstup)"
fi
echo
echo "Adresa platí, dokud běží tento skript a počítač je zapnutý a nespí. Ukončení: Ctrl+C"
wait "$CF_PID"
