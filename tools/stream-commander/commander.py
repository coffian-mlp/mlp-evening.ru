#!/usr/bin/env python3
"""Stream commander: слушает чат mlp-evening.ru и исполняет команды владельца
через obs-websocket.

Транспорт: Centrifugo websocket (мгновенный push, основной) с фолбэком на
SSE-поток chat_stream.php (задержка ~2-4 c).

Кино: Media Source "Кино" + счётчик серий в state.json. Когда серия доигрывает
до конца, демон сам переключает сцену на перерыв и двигает счётчик.

Запуск:
    ~/.local/obs-claude-venv/bin/python3 ~/.local/stream-commander/commander.py

Команды в чате (только от пользователя OWNER_ID, регистр не важен;
обращение — «Лира» и её алиасы, «Клод» поддерживается как legacy):
    Лира, включи кино      -> сцена кино, играет серия по счётчику (resume после паузы)
    Лира, следующая серия  -> счётчик+1, серия с начала
    Лира, предыдущая серия -> счётчик-1, серия с начала
    Лира, включи перерыв   -> пауза кино + сцена перерыва
    Лира, включи начало / конец / ютуб -> соответствующие сцены
"""

import glob
import html
import json
import logging
import random
import re
import sys
import threading
import time
from datetime import datetime, timezone
from pathlib import Path

import requests
import obsws_python as obs
from websocket import create_connection

SITE_URL = "https://mlp-evening.ru"
SITE_SSE_URL = SITE_URL + "/chat_stream.php"
CENTRIFUGO_WS_URL = "wss://mlp-evening.ru/connection/websocket"
CENTRIFUGO_CHANNEL = "public:chat"
OWNER_ID = 1  # users.id владельца (coffian)

OBS_HOST = "localhost"
OBS_WS_CONFIG = Path.home() / (
    "Library/Application Support/obs-studio/plugin_config/obs-websocket/config.json"
)

SCENE_START = "Начало"
SCENE_BREAK = "Перерыв"
SCENE_MOVIE = "Сцена"
SCENE_END = "end"
SCENE_YOUTUBE = "youtube"

MOVIE_SOURCE = "Кино"  # Media Source в сцене SCENE_MOVIE
EPISODES_DIR = "/Volumes/KINGSTON/downloads/Stargate SG-1 S07 DVDrip-AVC (AXN Sci-Fi)"
BREAK_SOURCE = "Перерыв видео"  # VLC Source в сцене SCENE_BREAK
BREAK_DIR = "/Volumes/KINGSTON/downloads/All Songs Warhammer 40k"
STATE_FILE = Path.home() / ".local/stream-commander/state.json"
DEFAULT_EPISODE_IDX = 5  # S07E06 — реперная точка

LOG_FILE = Path.home() / ".local/stream-commander/commander.log"
# Секрет для отправки событий на сайт (MLP-308). Файл вне git: {"stream_token": "..."}
CONFIG_FILE = Path.home() / ".local/stream-commander/config.json"

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)s %(message)s",
    handlers=[logging.FileHandler(LOG_FILE), logging.StreamHandler(sys.stdout)],
)
log = logging.getLogger("commander")
for noisy in ("obsws_python", "baseclient", "reqs", "events", "websocket"):
    logging.getLogger(noisy).setLevel(logging.WARNING)


def obs_config():
    cfg = json.loads(OBS_WS_CONFIG.read_text())
    return cfg.get("server_port", 4455), cfg["server_password"]


def obs_client():
    port, password = obs_config()
    return obs.ReqClient(host=OBS_HOST, port=port, password=password, timeout=5)


# --- Состояние (счётчик серий) ---

def load_state():
    try:
        return json.loads(STATE_FILE.read_text())
    except Exception:
        return {"episode_idx": DEFAULT_EPISODE_IDX}


def save_state(state):
    STATE_FILE.write_text(json.dumps(state))


def episode_list():
    files = sorted(glob.glob(EPISODES_DIR + "/*.mkv"))
    if not files:
        raise RuntimeError(f"в {EPISODES_DIR} не найдено серий")
    return files


def episode_name(path):
    m = re.search(r"S\d+E[\d-]+", Path(path).name)
    return m.group(0) if m else Path(path).name


# --- Действия ---

def set_scene(name):
    cl = obs_client()
    cl.set_current_program_scene(name)
    log.info("OBS: сцена -> %s", name)


def movie_play():
    """Включить кино: серия по счётчику; если она уже заряжена — продолжить с места."""
    cl = obs_client()
    eps = episode_list()
    state = load_state()
    idx = max(0, min(state["episode_idx"], len(eps) - 1))
    target = eps[idx]
    current = cl.get_input_settings(MOVIE_SOURCE).input_settings.get("local_file")
    if current != target:
        global _last_media_load
        _last_media_load = time.time()
        cl.set_input_settings(MOVIE_SOURCE, {"local_file": target}, True)
        log.info("Кино: заряжена %s", episode_name(target))
    else:
        media = cl.get_media_input_status(MOVIE_SOURCE).media_state
        if media == "OBS_MEDIA_STATE_PAUSED":
            action = "OBS_WEBSOCKET_MEDIA_INPUT_ACTION_PLAY"      # с места паузы
        elif media != "OBS_MEDIA_STATE_PLAYING":
            action = "OBS_WEBSOCKET_MEDIA_INPUT_ACTION_RESTART"   # доиграла/стоит — с начала
        else:
            action = None
        if action == "OBS_WEBSOCKET_MEDIA_INPUT_ACTION_RESTART":
            _last_media_load = time.time()  # RESTART тоже может породить ложный ended
        if action:
            cl.trigger_media_input_action(MOVIE_SOURCE, action)
    cl.set_current_program_scene(SCENE_MOVIE)
    log.info("OBS: кино -> %s (серия %d из %d)", episode_name(target), idx + 1, len(eps))


def movie_shift(delta):
    """Сдвинуть счётчик и включить серию строго с начала."""
    eps = episode_list()
    state = load_state()
    idx = max(0, min(state["episode_idx"] + delta, len(eps) - 1))
    state["episode_idx"] = idx
    save_state(state)
    cl = obs_client()
    global _last_media_load
    _last_media_load = time.time()
    cl.set_input_settings(MOVIE_SOURCE, {"local_file": eps[idx]}, True)
    # Явный RESTART: смена файла запускает с нуля сама, но при упоре в границу
    # списка (та же серия) настройки не меняются — без рестарта продолжилось бы с места.
    cl.trigger_media_input_action(MOVIE_SOURCE, "OBS_WEBSOCKET_MEDIA_INPUT_ACTION_RESTART")
    cl.set_current_program_scene(SCENE_MOVIE)
    log.info("OBS: серия %s (%d из %d) с начала", episode_name(eps[idx]), idx + 1, len(eps))


def reshuffle_break_playlist():
    """Свежая тасовка плейлиста перерывов. VLC-shuffle в OBS перемешивает список
    один раз при загрузке настроек, и stop_restart гоняет один и тот же порядок —
    поэтому тасуем сами, а встроенный shuffle держим выключенным."""
    files = sorted(glob.glob(BREAK_DIR + "/*.mp4"))
    if not files:
        log.warning("Перерывы: в %s нет mp4 — тасовать нечего", BREAK_DIR)
        return
    random.shuffle(files)
    playlist = [{"value": f, "hidden": False, "selected": False} for f in files]
    obs_client().set_input_settings(
        BREAK_SOURCE, {"playlist": playlist, "shuffle": False, "loop": True}, True)
    log.info("Перерывы: плейлист перетасован (%d роликов, первый: %s)",
             len(files), files[0].rsplit("/", 1)[-1][:40])


def notify_site(event, episode=""):
    """Сообщить сайту об автопереключении (MLP-308) — бот прокомментирует в чате.
    Сбой отправки некритичен: показ уже переключён, теряется только реплика."""
    try:
        token = json.loads(CONFIG_FILE.read_text()).get("stream_token", "")
    except Exception:
        return  # конфига нет — событие просто не отправляем
    if not token:
        return
    try:
        r = requests.post(SITE_URL + "/api.php",
                          data={"action": "stream_event", "event": event, "episode": episode},
                          headers={"X-Stream-Token": token}, timeout=8)
        log.info("Событие '%s' отправлено на сайт: HTTP %s", event, r.status_code)
    except Exception as e:
        log.warning("Не удалось отправить событие '%s': %s", event, e)


def break_scene():
    """Перерыв: поставить кино на паузу и уйти на сцену перерыва."""
    cl = obs_client()
    try:
        cl.trigger_media_input_action(
            MOVIE_SOURCE, "OBS_WEBSOCKET_MEDIA_INPUT_ACTION_PAUSE")
    except Exception:
        pass  # источника может не быть — не критично
    cl.set_current_program_scene(SCENE_BREAK)
    log.info("OBS: перерыв (кино на паузе)")


# (регулярка, действие, описание) — проверяются по порядку, первое совпадение
COMMANDS = [
    (r"перерыв", break_scene, "перерыв"),
    (r"следующ", lambda: movie_shift(+1), "следующая серия"),
    (r"предыдущ", lambda: movie_shift(-1), "предыдущая серия"),
    (r"кино|фильм|сери[юя]", movie_play, "кино"),
    (r"начал", lambda: set_scene(SCENE_START), "начало"),
    (r"кон(ец|цовк)|финал|энд", lambda: set_scene(SCENE_END), "конец"),
    (r"ютуб|youtube", lambda: set_scene(SCENE_YOUTUBE), "ютуб"),
]

# Обращение к боту: основное имя + алиасы; «клод» — legacy с эпохи «духа машины» (MLP-307)
TRIGGER = re.compile(r"^\s*@?(лирочка|лира|lyra|клод)[\s,:!.]*", re.IGNORECASE)

# Командный глагол — надёжный признак приказа, а не разговора о кино
IMPERATIVE = re.compile(
    r"\b(включ|врубай|врубить|врубл|поставь|переключ|запусти|давай|сделай|верни|"
    r"останов|прекрат|стоп|дальше|next)", re.IGNORECASE)


def looks_like_command(body):
    """Отличает приказ от болтовни: «Лира, кино» — да, «Лира, что думаешь про 18 серию?» — нет.
    Признак приказа: командный глагол ЛИБО короткая телеграфная фраза без вопроса.
    (Ложное срабатывание поймано в бою 2026-08-08: обсуждение серии переключило сцену.)"""
    if IMPERATIVE.search(body):
        return True
    if "?" in body:
        return False
    return len(body.split()) <= 3

STARTED_AT = datetime.now(timezone.utc)


def msg_time(msg):
    raw = msg.get("created_at") or ""
    try:
        return datetime.fromisoformat(raw.replace("Z", "+00:00"))
    except ValueError:
        return None


def handle_message(msg):
    if msg.get("user_id") != OWNER_ID or msg.get("is_deleted"):
        return
    ts = msg_time(msg)
    if ts is None or ts < STARTED_AT:
        return  # сообщение из истории до старта демона — не исполнять
    text = msg.get("raw_message") or msg.get("message") or ""
    text = html.unescape(re.sub(r"<[^>]+>", "", text)).strip()
    m = TRIGGER.match(text)
    if not m:
        return
    body = text[m.end():].lower()
    if not looks_like_command(body):
        log.info("Обращение без приказа (#%s): %r — не команда", msg.get("id"), text[:70])
        return
    for pattern, action, label in COMMANDS:
        if re.search(pattern, body):
            log.info("Команда от %s (#%s): %r -> %s",
                     msg.get("username"), msg.get("id"), text, label)
            try:
                action()
            except Exception as e:
                log.error("Ошибка исполнения '%s': %s", label, e)
            return
    log.info("Триггер без известной команды (#%s): %r", msg.get("id"), text)


def process_payload(msg, state):
    """Общая обработка сообщения из любого транспорта: дедуп по id + команды."""
    if not (isinstance(msg, dict) and "id" in msg and "user_id" in msg):
        return
    mid = int(msg["id"])
    if mid <= state["last_id"]:
        return
    state["last_id"] = mid
    handle_message(msg)


# --- Автопилот: конец серии -> перерыв, счётчик+1; уход со сцены кино -> пауза ---

_last_scene = None
_last_media_load = 0.0  # время последней зарядки файла: гасит ложный playback_ended


def on_current_program_scene_changed(data):
    """Ушли со сцены кино (командой, мышкой, автопилотом — неважно) -> пауза фильма.
    Иначе Media Source продолжает тикать в фоне и зрители теряют кусок серии."""
    global _last_scene
    new_scene = getattr(data, "scene_name", None)
    prev, _last_scene = _last_scene, new_scene
    if prev == SCENE_MOVIE and new_scene != SCENE_MOVIE:
        try:
            obs_client().trigger_media_input_action(
                MOVIE_SOURCE, "OBS_WEBSOCKET_MEDIA_INPUT_ACTION_PAUSE")
            log.info("Автопауза кино: сцена %s -> %s", prev, new_scene)
        except Exception as e:
            log.warning("Автопауза кино не удалась: %s", e)
    if prev == SCENE_BREAK and new_scene != SCENE_BREAK:
        # Ушли с перерыва — тасуем колоду к следующему (сцену никто не видит, глюков нет)
        try:
            reshuffle_break_playlist()
        except Exception as e:
            log.warning("Тасовка перерывов не удалась: %s", e)


def on_media_input_playback_ended(data):
    """Callback obs-websocket: имя функции = имя события."""
    if getattr(data, "input_name", None) != MOVIE_SOURCE:
        return
    # Замена файла (следующая/предыдущая серия) закрывает старый медиафайл и
    # порождает ЛОЖНЫЙ playback_ended — без этого гейта автопилот сдвигал
    # счётчик и уводил на перерыв поверх только что включённой серии (гонка
    # выловлена в бою 2026-08-01 18:59).
    if time.time() - _last_media_load < 3:
        log.info("Игнорирую playback_ended сразу после зарядки файла (ложный)")
        return
    state = load_state()
    state["episode_idx"] = state.get("episode_idx", DEFAULT_EPISODE_IDX) + 1
    save_state(state)
    log.info("Серия доиграла: счётчик -> серия %d, уходим на перерыв", state["episode_idx"] + 1)
    try:
        set_scene(SCENE_BREAK)
    except Exception as e:
        log.error("Не удалось включить перерыв: %s", e)
    # Реплику в чат сочинит бот на сайте — показ от этого не зависит (MLP-308)
    notify_site("episode_ended", str(state["episode_idx"]))


def obs_events_loop():
    """Держит подписку на события OBS; переживает рестарты OBS."""
    while True:
        try:
            port, password = obs_config()
            ecl = obs.EventClient(host=OBS_HOST, port=port, password=password, timeout=5)
            ecl.callback.register([on_media_input_playback_ended, on_current_program_scene_changed])
            global _last_scene
            _last_scene = obs_client().get_scene_list().current_program_scene_name
            log.info("OBS events: подписка активна (конец серии -> перерыв; уход с кино -> пауза); сцена: %s", _last_scene)
            while ecl.base_client.ws.connected:
                time.sleep(5)
            log.warning("OBS events: соединение потеряно")
        except Exception as e:
            log.warning("OBS events недоступны: %s — повтор через 15с", e)
        time.sleep(15)


# --- Транспорт 1: Centrifugo (мгновенный push) ---

def get_guest_token():
    r = requests.get(SITE_URL + "/", timeout=15)
    r.raise_for_status()
    m = re.search(
        r'centrifugo:\s*{\s*url:\s*"[^"]*"\s*,\s*token:\s*"([^"]+)"',
        r.text, re.DOTALL,
    )
    if not m:
        raise RuntimeError("токен Centrifugo не найден на странице (driver=sse?)")
    return m.group(1)


def listen_centrifugo(state):
    token = get_guest_token()
    ws = create_connection(CENTRIFUGO_WS_URL, timeout=15)
    try:
        ws.send(json.dumps({"id": 1, "connect": {"token": token}}))
        reply = json.loads(ws.recv())
        if "connect" not in reply.get("reply", reply) and "connect" not in reply:
            raise RuntimeError(f"connect отклонён: {reply}")
        ws.send(json.dumps({"id": 2, "subscribe": {"channel": CENTRIFUGO_CHANNEL}}))
        log.info("Centrifugo: подписка на %s активна", CENTRIFUGO_CHANNEL)
        ws.settimeout(60)  # сервер пингует каждые ~25с; тишина дольше минуты = обрыв
        while True:
            raw = ws.recv()
            for line in raw.splitlines():
                line = line.strip()
                if not line:
                    continue
                if line == "{}":  # ping -> pong
                    ws.send("{}")
                    continue
                try:
                    frame = json.loads(line)
                except ValueError:
                    continue
                pub = frame.get("push", {}).get("pub")
                if pub:
                    process_payload(pub.get("data"), state)
    finally:
        try:
            ws.close()
        except Exception:
            pass


# --- Транспорт 2: SSE (фолбэк) ---

def listen_sse_once(state):
    """Один цикл SSE-соединения (~50 c), затем возврат к попытке Centrifugo."""
    headers = {"Accept": "text/event-stream"}
    if state["last_id"]:
        headers["Last-Event-ID"] = str(state["last_id"])
    resp = requests.get(SITE_SSE_URL, headers=headers, stream=True, timeout=(10, 90))
    resp.raise_for_status()
    event_data = []
    skip_event = False
    for raw in resp.iter_lines(decode_unicode=True):
        if raw is None or raw.startswith(":"):
            continue
        if raw == "":
            if event_data and not skip_event:
                try:
                    msg = json.loads("\n".join(event_data))
                except ValueError:
                    msg = None
                process_payload(msg, state)
            event_data = []
            skip_event = False
            continue
        if raw.startswith("data:"):
            event_data.append(raw[5:].strip())
        elif raw.startswith("event:") and raw[6:].strip() != "message":
            skip_event = True  # служебное событие (online_count) — игнор


def listen():
    state = {"last_id": 0}
    while True:
        try:
            listen_centrifugo(state)
        except KeyboardInterrupt:
            log.info("Остановка по Ctrl+C")
            return
        except Exception as e:
            log.warning("Centrifugo недоступен: %s — фолбэк на SSE-цикл", e)
        try:
            listen_sse_once(state)
        except KeyboardInterrupt:
            log.info("Остановка по Ctrl+C")
            return
        except Exception as e:
            log.warning("SSE разрыв: %s — пауза 5с", e)
            time.sleep(5)


if __name__ == "__main__":
    log.info("Stream commander запущен. OWNER_ID=%s", OWNER_ID)
    try:
        obs_client().get_version()
        log.info("OBS websocket: OK")
    except Exception as e:
        log.warning("OBS недоступен на старте (%s) — команды будут падать, пока OBS не поднимется", e)
    st = load_state()
    if not STATE_FILE.exists():
        save_state(st)
    log.info("Счётчик серий: %d (серия %d)", st["episode_idx"], st["episode_idx"] + 1)
    try:
        if obs_client().get_scene_list().current_program_scene_name != SCENE_BREAK:
            reshuffle_break_playlist()  # свежая колода на старте (если перерыв не в эфире)
    except Exception as e:
        log.warning("Стартовая тасовка перерывов не удалась: %s", e)
    threading.Thread(target=obs_events_loop, daemon=True).start()
    listen()
