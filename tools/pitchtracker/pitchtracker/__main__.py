"""Start:  python -m pitchtracker            (Dashboard: http://127.0.0.1:8765)
        python -m pitchtracker --no-browser
"""
import argparse
import logging
import threading
import webbrowser
from pathlib import Path

import yaml

from .engine import Tracker
from .web import create_app


def main():
    ap = argparse.ArgumentParser(prog="pitchtracker")
    ap.add_argument("--config", default="config.yaml")
    ap.add_argument("--port", type=int, default=8765)
    ap.add_argument("--host", default="127.0.0.1",
                    help="0.0.0.0 = auch vom Handy im selben WLAN erreichbar")
    ap.add_argument("--no-browser", action="store_true")
    a = ap.parse_args()

    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(message)s", datefmt="%H:%M:%S")
    logging.getLogger("werkzeug").setLevel(logging.WARNING)
    cfg = yaml.safe_load(Path(a.config).read_text(encoding="utf-8")) if Path(a.config).exists() else {}
    tracker = Tracker(cfg)
    threading.Thread(target=tracker.run_forever, daemon=True).start()
    url = f"http://127.0.0.1:{a.port}"
    print(f"\n  Dunkelnacht-Radar läuft → {url}   (Beenden: Ctrl+C)\n")
    if not a.no_browser:
        threading.Timer(1.0, lambda: webbrowser.open(url)).start()
    create_app(tracker).run(host=a.host, port=a.port, debug=False, use_reloader=False)


if __name__ == "__main__":
    main()
