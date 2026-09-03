#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# scripts/model-server.sh — lifecycle manager for the local classifier server.
# scripts/model-server.sh — gestor de ciclo de vida del clasificador local.
#
# Replaces: python3 -m venv .venv && pip install -r requirements.txt &&
#           uvicorn server:app --port 8501   (3 commands, every time)
#
# Usage:  ./run model start     # provision venv (once), boot in background, wait healthy
#         ./run model status    # health + PID + log location
#         ./run model stop      # stop the background server
#         ./run model run       # run in the foreground (Ctrl+C to stop)
# Env:    B2B_MODEL_PORT        # default 8501 (matches LOCAL_CLASSIFIER_URL)
#
# Then point the app at it: RECYCLING_CLASSIFIER_DRIVER=local in .env — see
# docs/LOCAL_MODEL.md. Requires python3 (Arch: it is in the base system).
# ---------------------------------------------------------------------------
set -Eeuo pipefail
SOURCE_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SOURCE_DIR}/_lib/common.sh"

MODEL_DIR="$B2B_ROOT/scripts/local-model-server"
VENV="$MODEL_DIR/.venv"
REQS="$MODEL_DIR/requirements.txt"
MARKER="$VENV/.deps-installed"
PIDFILE="$B2B_ROOT/.model-server.pid"
LOGFILE="$B2B_ROOT/storage/logs/model-server.log"
PORT="${B2B_MODEL_PORT:-8501}"
HEALTH_URL="http://127.0.0.1:${PORT}/healthz"

model_running() { http_probe "$HEALTH_URL"; }

ensure_venv() {
    command -v python3 >/dev/null 2>&1 \
        || die "python3 is required for the model server / se necesita python3 para el servidor de modelo"
    if [ ! -d "$VENV" ]; then
        log "Creating venv (one-time) / Creando venv (una sola vez)"
        python3 -m venv "$VENV" || die "python3 -m venv failed / falló python3 -m venv"
    fi
    # (Re)install deps when the venv is fresh or requirements.txt changed.
    if [ ! -f "$MARKER" ] || [ "$REQS" -nt "$MARKER" ]; then
        log "Installing Python dependencies / Instalando dependencias de Python"
        "$VENV/bin/pip" install -q --disable-pip-version-check -r "$REQS" \
            || die "pip install failed — check network / falló pip install — revisa la red"
        touch "$MARKER"
    else
        log "Python dependencies already installed / Dependencias ya instaladas"
    fi
}

model_start() {
    if model_running; then
        warn "Model server already running at ${HEALTH_URL} / El servidor de modelo ya corre en ${HEALTH_URL}"
        exit 0
    fi
    ensure_venv
    mkdir -p "$B2B_ROOT/storage/logs"
    log "Starting uvicorn on 127.0.0.1:${PORT} (log: storage/logs/model-server.log)"
    ( cd "$MODEL_DIR" && nohup "$VENV/bin/uvicorn" server:app --host 127.0.0.1 --port "$PORT" \
        >>"$LOGFILE" 2>&1 & printf '%s\n' "$!" >"$PIDFILE" )
    if ! wait_for_http "$HEALTH_URL" 20 "model server / servidor de modelo"; then
        err "Last log lines / Últimas líneas del log:"
        tail -n 15 "$LOGFILE" >&2 || true
        rm -f "$PIDFILE"
        die "Model server failed to become healthy / El servidor de modelo no arrancó bien"
    fi
    ok "Model server healthy at ${HEALTH_URL} / Servidor de modelo sano en ${HEALTH_URL}"
    bi "Activate it: set RECYCLING_CLASSIFIER_DRIVER=local in .env (docs/LOCAL_MODEL.md)" \
       "Actívalo: pon RECYCLING_CLASSIFIER_DRIVER=local en .env (docs/LOCAL_MODEL.es.md)"
}

model_stop() {
    local pid=""
    [ -f "$PIDFILE" ] && pid="$(cat "$PIDFILE" 2>/dev/null || true)"
    if [ -z "$pid" ] && command -v pgrep >/dev/null 2>&1; then
        pid="$(pgrep -f 'uvicorn server:app' | head -n 1 || true)"
    fi
    if [ -z "$pid" ] && ! model_running; then
        ok "Model server is not running / El servidor de modelo no está corriendo"
        rm -f "$PIDFILE"
        exit 0
    fi
    if [ -n "$pid" ]; then
        kill "$pid" 2>/dev/null || true
        for _ in $(seq 1 10); do kill -0 "$pid" 2>/dev/null || break; sleep 0.5; done
        kill -0 "$pid" 2>/dev/null && { kill -9 "$pid" 2>/dev/null || true; warn "Had to SIGKILL ${pid} / Fue necesario SIGKILL ${pid}"; }
    fi
    rm -f "$PIDFILE"
    ok "Model server stopped (port ${PORT}) / Servidor de modelo detenido (puerto ${PORT})"
}

model_status() {
    if model_running; then
        local pid=""
        [ -f "$PIDFILE" ] && pid="$(cat "$PIDFILE" 2>/dev/null || true)"
        ok "Model server: running at ${HEALTH_URL} (pid: ${pid:-unknown, found via health})"
        log "  ${C_DIM}log: ${LOGFILE}${C_RESET}"
    else
        warn "Model server: not running at ${HEALTH_URL} — start with: ./run model start"
        exit 1
    fi
}

case "${1:-}" in
    start)       model_start ;;
    stop)        model_stop ;;
    status)      model_status ;;
    run)
        ensure_venv
        log "Foreground mode — Ctrl+C to stop / Modo primer plano — Ctrl+C para detener"
        cd "$MODEL_DIR" && exec "$VENV/bin/uvicorn" server:app --host 127.0.0.1 --port "$PORT" ;;
    --help|-h|"") help_header "$0" ;;
    *) die "Unknown subcommand: $1 (start | stop | status | run) / Subcomando desconocido: $1" ;;
esac
