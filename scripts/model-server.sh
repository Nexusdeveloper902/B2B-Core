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
# docs/LOCAL_MODEL.md. Python: python3 on Linux/macOS, python3 -> python
# -> py launcher on Windows (auto-detected, ADR-017).
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

# Windows venvs put binaries in .venv/Scripts (not .venv/bin) — selected by
# the auto-detected OS class (ADR-017).
if is_windows; then VENV_BIN="$VENV/Scripts"; else VENV_BIN="$VENV/bin"; fi

model_running() { http_probe "$HEALTH_URL"; }

ensure_venv() {
    python_resolve
    [ -n "$PYTHON_BIN" ] \
        || die "python is required for the model server / se necesita python para el servidor de modelo"
    if [ ! -d "$VENV" ]; then
        log "Creating venv (one-time) with ${PYTHON_BIN} / Creando venv (una sola vez)"
        "$PYTHON_BIN" -m venv "$VENV" || die "${PYTHON_BIN} -m venv failed / falló ${PYTHON_BIN} -m venv"
    fi
    # (Re)install deps when the venv is fresh or requirements.txt changed.
    if [ ! -f "$MARKER" ] || [ "$REQS" -nt "$MARKER" ]; then
        log "Installing Python dependencies / Instalando dependencias de Python"
        "$VENV_BIN/pip" install -q --disable-pip-version-check -r "$REQS" \
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
    # Daemonize: one backgrounded unit that EXECs uvicorn (pid stays valid),
    # all three fds detached from this shell, then disown so the script can
    # never block in wait() for the daemon (TASK-003 finding: pipelines held
    # the script open when the daemon remained a shell child).
    ( cd "$MODEL_DIR" && exec "$VENV_BIN/uvicorn" server:app --host 127.0.0.1 --port "$PORT" \
        </dev/null >>"$LOGFILE" 2>&1 ) &
    disown
    printf '%s\n' "$!" >"$PIDFILE"
    if ! wait_for_http "$HEALTH_URL" 20 "model server / servidor de modelo"; then
        err "Last log lines / Últimas líneas del log:"
        tail -n 15 "$LOGFILE" >&2 || true
        rm -f "$PIDFILE"
        die "Model server failed to become healthy / El servidor de modelo no arrancó bien"
    fi
    # The launcher pid and the serving pid can differ (exec chain), so once
    # healthy, record the AUTHORITATIVE pid found via pgrep when available.
    if command -v pgrep >/dev/null 2>&1; then
        real_pid="$(pgrep -f 'uvicorn server:app' | head -n 1 || true)"
        if [ -n "$real_pid" ]; then printf '%s\n' "$real_pid" >"$PIDFILE"; fi
    fi
    ok "Model server healthy at ${HEALTH_URL} / Servidor de modelo sano en ${HEALTH_URL}"
    bi "Activate it: set RECYCLING_CLASSIFIER_DRIVER=local in .env (docs/LOCAL_MODEL.md)" \
       "Actívalo: pon RECYCLING_CLASSIFIER_DRIVER=local en .env (docs/LOCAL_MODEL.es.md)"
}

model_stop() {
    local pid=""
    [ -f "$PIDFILE" ] && pid="$(cat "$PIDFILE" 2>/dev/null || true)"
    if [ -n "$pid" ]; then
        kill "$pid" 2>/dev/null || true
    fi
    # Verify the ACTUAL shutdown via the health endpoint — the pidfile pid
    # may be a stale launcher pid (exec chains orphan the real server).
    for _ in $(seq 1 10); do model_running || break; sleep 0.5; done
    if model_running && command -v pkill >/dev/null 2>&1; then
        pkill -f 'uvicorn server:app' 2>/dev/null || true
        for _ in $(seq 1 10); do model_running || break; sleep 0.5; done
    fi
    # Windows fallback: Git Bash has no pkill — translate the msys pid to a
    # Windows pid via /proc/<pid>/winpid (msys /proc emulation) and kill the
    # whole process tree with taskkill (// survives Git Bash path mangling).
    if model_running && is_windows && [ -n "${pid:-}" ] && [ -r "/proc/${pid}/winpid" ]; then
        local winpid=""
        winpid="$(cat "/proc/${pid}/winpid" 2>/dev/null || true)"
        if [ -n "$winpid" ]; then
            taskkill //F //T //PID "$winpid" >/dev/null 2>&1 || true
            for _ in $(seq 1 10); do model_running || break; sleep 0.5; done
        fi
    fi
    rm -f "$PIDFILE"
    if model_running; then
        die "Model server is STILL running on port ${PORT} — kill it manually / mátalo a mano:
  Linux:   pkill -f 'uvicorn server:app'
  Windows: taskkill /F /T /IM uvicorn.exe"
    fi
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
        cd "$MODEL_DIR" && exec "$VENV_BIN/uvicorn" server:app --host 127.0.0.1 --port "$PORT" ;;
    --help|-h|"") help_header "$0" ;;
    *) die "Unknown subcommand: $1 (start | stop | status | run) / Subcomando desconocido: $1" ;;
esac
