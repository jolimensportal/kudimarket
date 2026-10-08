#!/usr/bin/env bash
# Brings up all three KudiMarket dev surfaces at once.
#
# WHY THIS EXISTS
# These servers die whenever the session that started them ends, and I have twice handed over a URL
# that had already stopped. One command, three servers, one stop.
#
# Two apps, one checkout, and cookies are scoped by domain and path but NOT by port. Both the trader
# app and the operations console therefore set SESSION_COOKIE differently here, or whichever logged in
# last silently signs the other one out. That is a real footgun, not only a dev annoyance: in production
# these would be different domains anyway, and this makes the dev setup behave like that.

set -euo pipefail

APP_DIR="${APP_DIR:-$HOME/Desktop/kudimarket/app}"
LOG_DIR="${LOG_DIR:-/tmp/kudi-servers}"
mkdir -p "$LOG_DIR"

stop() {
  pkill -f "artisan serve --host=127.0.0.1 --port=8100" 2>/dev/null || true
  pkill -f "artisan serve --host=127.0.0.1 --port=8101" 2>/dev/null || true

  # By port rather than by command line. An earlier mockup server was started with a different
  # argument order, so matching on "http.server 8778" missed it, the new one could not bind, and the
  # old one kept serving a directory where /index.html does not exist -- a 404 that looked like the
  # script was broken rather than like two servers were fighting over a port.
  for port in 8100 8101 8778; do
    pids=$(lsof -ti tcp:"$port" 2>/dev/null || true)
    if [ -n "$pids" ]; then
      kill $pids 2>/dev/null || true
    fi
  done

  sleep 1
  echo "stopped."
}

start() {
  cd "$APP_DIR"

  # artisan serve, not "php -S ... public/index.php".
  #
  # Two mistakes here, both of which leave the operations console looking perfect and doing nothing.
  # Passing public/index.php as the router sends EVERY request through Laravel, static files included, so
  # each /js/... and /css/... request matched the panel's auth middleware and answered 302 to the login
  # page -- delivered as HTML where the browser expected JavaScript. Laravel 13 also has no server.php, so
  # the first attempt at fixing it failed to open the router at all.
  #
  # artisan serve is the supported path and serves files from public/ directly. The symptom of getting this
  # wrong was a console that rendered perfectly with every action button inert, because
  # filamentActionModals, filamentTable and filamentSchema were all undefined. No server-side test can see
  # that, which is how it survived three rounds of tests. SESSION_COOKIE still differs per app so the trader
  # and the console keep separate sessions.
  SESSION_COOKIE=kudi-trader-session \
    nohup php artisan serve --host=127.0.0.1 --port=8101 \
    > "$LOG_DIR/trader.log" 2>&1 &
  disown || true

  SESSION_COOKIE=kudi-ops-session \
    nohup php artisan serve --host=127.0.0.1 --port=8100 \
    > "$LOG_DIR/ops.log" 2>&1 &
  disown || true

  nohup python3 -m http.server 8778 --bind 127.0.0.1 \
    --directory "$HOME/Desktop/kudimarket" \
    > "$LOG_DIR/mockup.log" 2>&1 &
  disown || true

  # php -S binds before the app has booted, so a 200 here means the socket is up, not that the
  # framework is ready. Give it a moment, then check for real.
  sleep 4

  # If a port is still held by something we did not start, everything below will report on the wrong
  # server. Say so plainly rather than debugging a 404 later.
  for port in 8100 8101 8778; do
    holder=$(lsof -nP -iTCP:"$port" -sTCP:LISTEN 2>/dev/null | tail -n +2 | awk '{print $1, $2}' || true)
    if [ -n "$holder" ] && ! echo "$holder" | grep -q "^php"; then
      [ "$port" = "8778" ] && continue
      echo "WARNING: port $port is held by something other than php: $holder"
    fi
  done

  echo
  printf '%-38s %s\n' "trader board   http://127.0.0.1:8101/sign-in"  "$(check 8101 /sign-in)"
  printf '%-38s %s\n' "ops console    http://127.0.0.1:8100/admin/login" "$(check 8100 /admin/login)"
  # The full path, because the mockup lives in product-preview/ and the repo root has no index.html.
  # Serving the repo root and linking /index.html 404s, which reads as a broken script rather than a
  # wrong URL.
  printf '%-38s %s\n' "locked mockup  http://127.0.0.1:8778/product-preview/index.html" "$(check 8778 /product-preview/index.html)"
  echo
  echo "trader: ama@example.com / kudi-dev-only"
  echo "ops:    ops@kudimarket.com / kudi-dev-only"
  echo "logs:   $LOG_DIR"
}

check() {
  local port="$1" path="$2"
  local code
  code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 8 "http://127.0.0.1:${port}${path}" || echo 000)
  case "$code" in
    200) echo "up (200)" ;;
    302) echo "up (302, redirecting to sign-in)" ;;
    *)   echo "DOWN (${code})" ;;
  esac
}

case "${1:-start}" in
  start) start ;;
  stop)  stop ;;
  *)     echo "usage: $0 [start|stop]"; exit 1 ;;
esac