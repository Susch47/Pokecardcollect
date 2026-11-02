"""Lokaler Webserver für das Live-Dashboard."""
from __future__ import annotations

from pathlib import Path

from flask import Flask, jsonify, request, send_file

from .engine import Tracker, parse_ranges

HERE = Path(__file__).parent


def create_app(tracker: Tracker) -> Flask:
    app = Flask(__name__)

    @app.get("/")
    def index():
        return send_file(HERE / "dashboard.html")

    @app.get("/api/state")
    def state():
        return jsonify(tracker.state(show_all=request.args.get("all") == "1"))

    @app.post("/api/toggle")
    def toggle():
        d = request.get_json(force=True)
        tracker.set_owned({int(d["num"])}, bool(d["owned"]))
        return jsonify(ok=True)

    @app.post("/api/collection")
    def collection():
        d = request.get_json(force=True)
        nums = parse_ranges(d.get("text", ""))
        if d.get("mode") == "missing":
            tracker.replace_collection(set(range(1, 121)) - nums)
        else:
            tracker.replace_collection(nums)
        return jsonify(ok=True, owned=len(tracker.owned))

    @app.post("/api/scan")
    def scan():
        tracker.trigger()
        return jsonify(ok=True)

    @app.post("/api/hide")
    def hide():
        tracker.hide(request.get_json(force=True)["id"])
        return jsonify(ok=True)

    @app.post("/api/viewed")
    def viewed():
        tracker.mark_viewed()
        return jsonify(ok=True)

    return app
