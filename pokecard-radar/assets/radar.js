/* PokéCard Radar – Dashboard (Port von pitchtracker/dashboard.html) */
(function () {
  "use strict";
  var CFG = window.PCR_CONFIG || {};
  var root = document.getElementById("pcr-app");
  if (!root) return;
  root.classList.add("pcr-app");

  var esc = function (s) { return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) { return {"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c]; }); };
  var S = null, setKey = CFG.set, editing = false, selCard = null, known = null, timer = null;
  var F = { src: "all", low: false, lots: true, sort: "new" };

  function api(path, body) {
    return fetch(CFG.restUrl + path, {
      method: body ? "POST" : "GET",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json", "X-WP-Nonce": CFG.nonce },
      body: body ? JSON.stringify(body) : undefined
    }).then(function (r) {
      return r.json().then(function (d) { if (!r.ok) throw new Error((d && d.message) || ("HTTP " + r.status)); return d; });
    });
  }

  function rel(iso) {
    if (!iso) return "–";
    var d = (Date.now() - new Date(iso).getTime()) / 60000;
    if (d < 1) return "gerade eben"; if (d < 60) return "vor " + Math.round(d) + " min";
    if (d < 1440) return "vor " + Math.round(d / 60) + " h"; return "vor " + Math.round(d / 1440) + " T";
  }
  function num(v, dig) { return v.toLocaleString("de-CH", { maximumFractionDigits: dig == null ? 2 : dig }); }
  function fmt(v, cur) { return v == null ? "VB" : (cur === "EUR" ? num(v) + " €" : "CHF " + num(v)); }
  function card(n) { for (var i = 0; i < S.cards.length; i++) if (S.cards[i].num === n) return S.cards[i]; return null; }

  // ---------- Grundgerüst ----------
  root.innerHTML =
    '<div class="pcr-head">' +
      '<h1 id="pcrTitle">Karten-Radar <small>Kleinanzeigen &amp; tutti · live</small></h1>' +
      '<select id="pcrSet" aria-label="Set"></select>' +
      '<div class="pcr-spacer"></div>' +
      '<div id="pcrStatus" class="pcr-row"></div>' +
      '<button class="primary" id="pcrScan" hidden>Jetzt scannen</button>' +
      '<span class="pcr-user">' + esc(CFG.userName) + ' · <a href="' + esc(CFG.logoutUrl) + '">Abmelden</a></span>' +
    '</div>' +
    '<div class="pcr-main"><div>' +
      '<section>' +
        '<h2>Sammlung</h2>' +
        '<div id="pcrSetup"></div>' +
        '<div class="pcr-row" style="justify-content:space-between">' +
          '<div><span class="pcr-big" id="pcrOwned">–</span> <span class="pcr-hint" style="display:inline">/ <span id="pcrTotal">–</span> · <b id="pcrMiss">–</b> fehlen</span></div>' +
          '<button id="pcrEdit">Bearbeiten</button>' +
        '</div>' +
        '<div class="pcr-progress"><div id="pcrBar" style="width:0"></div></div>' +
        '<div class="pcr-grid" id="pcrGrid"></div>' +
        '<div class="pcr-legend">' +
          '<span><i style="background:var(--panel2);border:1px solid #3a3460"></i>fehlt</span>' +
          '<span><i style="background:#11141c"></i>vorhanden</span>' +
          '<span><i style="background:#2a2150"></i>Angebot gefunden</span>' +
          '<span><i style="border:1px solid var(--accent)"></i><span id="pcrSpecial">Secret Rare</span></span>' +
        '</div>' +
        '<p class="pcr-hint" id="pcrGridHint">Klick auf eine Karte filtert die Treffer.</p>' +
        '<div id="pcrBulk" style="display:none;margin-top:10px">' +
          '<p class="pcr-hint">Im Bearbeiten-Modus schaltet ein Klick eine Karte zwischen <b>vorhanden</b> und <b>fehlt</b> um. Oder trag hier deine Liste ein (Nummern und Bereiche, z.&nbsp;B. <code>85-120, 48, 55</code>):</p>' +
          '<textarea id="pcrBulkText" placeholder="z. B. 85-120, 48, 55"></textarea>' +
          '<div class="pcr-row" style="margin-top:6px"><button id="pcrSaveMissing">Als FEHLENDE übernehmen</button><button id="pcrSaveOwned">Als VORHANDENE übernehmen</button></div>' +
        '</div>' +
      '</section>' +
      '<section id="pcrTg" style="margin-top:14px" hidden>' +
        '<h2>Push aufs Handy</h2>' +
        '<p class="pcr-hint" style="margin-top:0">Telegram-Chat-ID eintragen. Du bekommst sie, wenn du dem Bot der Seite schreibst und z.&nbsp;B. @userinfobot fragst. Benachrichtigt wird bei neuen, sicher erkannten Angeboten für deine fehlenden Karten.</p>' +
        '<div class="pcr-row"><input type="text" id="pcrChat" inputmode="numeric" placeholder="Chat-ID, z. B. 123456789"><button id="pcrChatSave">Speichern</button></div>' +
      '</section>' +
      '<details id="pcrLogBox" hidden><summary>Protokoll</summary><pre id="pcrLog"></pre></details>' +
    '</div>' +
    '<section>' +
      '<h2>Angebote für fehlende Karten</h2>' +
      '<div class="pcr-filters" id="pcrFilters">' +
        '<span class="pcr-chip on" data-f="src" data-v="all">Alle Quellen</span>' +
        '<span class="pcr-chip" data-f="src" data-v="kleinanzeigen">Kleinanzeigen</span>' +
        '<span class="pcr-chip" data-f="src" data-v="tutti">tutti</span>' +
        '<span style="width:10px"></span>' +
        '<span class="pcr-chip" data-f="low" title="Name ohne Set-Bezug oder mehrdeutige Variante">auch unsichere</span>' +
        '<span class="pcr-chip on" data-f="lots">Lots / Sammlungen</span>' +
        '<span class="pcr-chip" data-f="sort">günstigste zuerst</span>' +
        '<span class="pcr-spacer"></span>' +
        '<span class="pcr-hint" style="margin:0" id="pcrCardFilter"></span>' +
      '</div>' +
      '<div id="pcrHits"><div class="pcr-empty">Lade …</div></div>' +
    '</section></div>';

  var $ = function (id) { return document.getElementById(id); };

  (CFG.sets || []).forEach(function (s) {
    var o = document.createElement("option"); o.value = s.key; o.textContent = s.label; $("pcrSet").appendChild(o);
  });
  $("pcrSet").value = setKey;
  if ((CFG.sets || []).length < 2) $("pcrSet").hidden = true;

  // ---------- Laden & Rendern ----------
  function load() {
    var key = setKey;
    return api("/state?set=" + encodeURIComponent(key)).then(function (d) {
      if (key !== setKey) return;
      S = d; render(); notifyNew();
    }).catch(function (e) {
      $("pcrStatus").innerHTML = '<span class="pcr-pill"><span class="pcr-dot bad"></span>' + esc(e.message) + "</span>";
    });
  }

  function render() {
    var vintage = S.cards.every(function (c) { return !c.secret; });
    $("pcrTitle").innerHTML = esc(S.title) + " <small>" + esc(S.subtitle) + " · Kleinanzeigen &amp; tutti · live</small>";
    $("pcrSpecial").textContent = vintage ? "Holo" : "Secret Rare";

    // Status
    var st = Object.keys(S.status).map(function (k) {
      var v = S.status[k];
      var dot = S.scanning ? "pulse" : v.ok === true ? "ok" : v.ok === false ? "bad" : "";
      return '<span class="pcr-pill" title="' + esc(v.error) + '"><span class="pcr-dot ' + dot + '"></span>' +
        (k === "kleinanzeigen" ? "Kleinanzeigen" : "tutti") + " " + (v.last ? rel(v.last) : "") + (v.ok === false ? " · Fehler" : "") + "</span>";
    }).join("");
    var nxt = S.scanning ? "Scan läuft…" : S.next_scan ? "nächster Scan " + new Date(S.next_scan).toLocaleTimeString("de-CH", { hour: "2-digit", minute: "2-digit" }) : "";
    $("pcrStatus").innerHTML = st + (nxt ? '<span class="pcr-pill">' + nxt + "</span>" : "");
    $("pcrScan").hidden = !S.is_admin;

    // Erste Einrichtung
    $("pcrSetup").innerHTML = S.configured ? "" :
      '<div class="pcr-callout">Trag zuerst ein, welche Karten dir fehlen: <b>Bearbeiten</b> klicken und die Liste eingeben oder Kacheln anklicken. Bis dahin gelten alle Karten als fehlend.</div>';

    // Sammlung
    $("pcrOwned").textContent = S.owned; $("pcrTotal").textContent = S.total; $("pcrMiss").textContent = S.total - S.owned;
    $("pcrBar").style.width = (S.owned / S.total * 100) + "%";
    $("pcrGrid").innerHTML = S.cards.map(function (c) {
      var cls = ["pcr-tile", c.owned ? "owned" : "missing", c.secret ? "secret" : "", vintage && c.rarity === "H" ? "holo" : "",
        (!c.owned && c.hits) ? "has" : "", selCard === c.num ? "sel" : ""].join(" ");
      var mk = c.market ? " · Markt ≈ " + c.market.toFixed(2) + " €" : "";
      return '<div class="' + cls + '" data-n="' + c.num + '" title="#' + esc(c.code) + " " + esc(c.de) + (c.de !== c.en ? " / " + esc(c.en) : "") + " · " + esc(c.rarity_label) + mk + '">' +
        c.num + ((!c.owned && c.hits) ? '<span class="b">' + c.hits + "</span>" : "") + "</div>";
    }).join("");

    // Telegram & Protokoll
    $("pcrTg").hidden = !S.telegram;
    if (document.activeElement !== $("pcrChat")) $("pcrChat").value = S.telegram_chat || "";
    $("pcrLogBox").hidden = !S.is_admin;
    $("pcrLog").textContent = (S.log || []).join("\n");

    // Treffer
    var hits = S.hits.filter(function (h) { return h.missing_nums.length; });
    if (F.src !== "all") hits = hits.filter(function (h) { return h.source === F.src; });
    if (!F.low) hits = hits.filter(function (h) { return h.confidence !== "niedrig"; });
    if (!F.lots) hits = hits.filter(function (h) { return !h.is_lot; });
    if (selCard) hits = hits.filter(function (h) { return h.missing_nums.indexOf(selCard) !== -1; });
    if (F.sort === "price") hits.sort(function (a, b) { return (a.price_chf == null ? 1e9 : a.price_chf) - (b.price_chf == null ? 1e9 : b.price_chf); });
    var sc = selCard && card(selCard);
    $("pcrCardFilter").innerHTML = sc ? "Filter: #" + selCard + " " + esc(sc.de) + ' <a href="#" id="pcrClr">×</a>' : hits.length + " Angebote";
    if (sc) $("pcrClr").onclick = function (e) { e.preventDefault(); selCard = null; render(); };

    $("pcrHits").innerHTML = hits.length ? hits.map(hitHtml).join("") :
      '<div class="pcr-empty">' + (S.cycle ? "Noch keine Angebote für deine fehlenden Karten. Der Radar sucht weiter." : "Der erste Scan läuft noch …") + "</div>";
  }

  function hitHtml(h) {
    var cards = h.missing_nums.map(function (n) {
      var c = card(n);
      return '<span class="pcr-cc ' + esc(c.rarity) + '">#' + esc(c.code.split("/")[0]) + " " + esc(c.de) + " · " + esc(c.rarity) + "</span>";
    }).join("");
    var tags = [
      h.viewed ? "" : '<span class="pcr-tag acc">NEU</span>',
      h.confidence === "sicher" ? '<span class="pcr-tag good">Nummer erkannt</span>' : h.confidence === "mittel" ? '<span class="pcr-tag">Name + Set</span>' : '<span class="pcr-tag warn">unsicher</span>',
      h.ambiguous ? '<span class="pcr-tag warn">Variante unklar</span>' : "",
      h.is_lot ? '<span class="pcr-tag warn">Lot/Sammlung</span>' : "",
      h.edition ? '<span class="pcr-tag acc">' + esc(h.edition) + "</span>" : "",
      h.graded ? '<span class="pcr-tag acc">' + esc(h.graded) + "</span>" : "",
      h.language ? '<span class="pcr-tag">' + esc(h.language) + "</span>" : "",
      h.suspicious ? '<span class="pcr-tag bad" title="Unter 35 % vom Marktwert: Fake- oder Betrugsgefahr. Fotos von Vorder- und Rückseite verlangen, nur mit Käuferschutz zahlen.">verdächtig günstig</span>' : "",
      h.price_drop ? '<span class="pcr-tag good">Preis gesenkt (vorher ' + esc(fmt(h.first_price, h.currency)) + ")</span>" : "",
      h.stale ? '<span class="pcr-tag">nicht mehr gesehen</span>' : ""
    ].join("");
    var conv = h.currency === "EUR" && h.price_chf != null ? "<small>≈ CHF " + num(h.price_chf, 0) + "</small>" : "";
    var mk = "";
    if (h.market_chf) {
      var cls = h.price_chf != null && h.price_chf <= h.market_chf ? "under" : "over";
      mk = '<small class="' + cls + '">Markt ≈ CHF ' + num(h.market_chf, 0) + "</small>";
    }
    var img = h.image ? '<img src="' + esc(h.image) + '" alt="" loading="lazy" referrerpolicy="no-referrer">' : '<div class="noimg">🂠</div>';
    return '<div class="pcr-hit ' + (h.viewed ? "" : "new") + " " + (h.stale ? "stale" : "") + '">' +
      '<a href="' + esc(h.url) + '" target="_blank" rel="noopener noreferrer">' + img + "</a>" +
      "<div>" +
        '<div class="pcr-cards">' + cards + "</div>" +
        '<a class="t" href="' + esc(h.url) + '" target="_blank" rel="noopener noreferrer">' + esc(h.title) + "</a>" +
        '<div class="pcr-meta"><span class="pcr-src ' + esc(h.source) + '">' + (h.source === "tutti" ? "tutti" : "Kleinanzeigen") + "</span>" +
          "<span>" + esc(h.location) + "</span><span>gefunden " + rel(h.first_seen) + "</span></div>" +
        '<div class="pcr-meta">' + tags + "</div>" +
      "</div>" +
      '<div class="pcr-price"><div class="p">' + esc(h.price_raw || fmt(h.price, h.currency)) + "</div>" + conv + mk +
        '<button class="x" data-hide="' + esc(h.id) + '" title="ausblenden">ausblenden ×</button></div>' +
    "</div>";
  }

  // Bilder ohne Quelle durch Platzhalter ersetzen (kein inline onerror wegen CSP)
  $("pcrHits").addEventListener("error", function (e) {
    if (e.target.tagName === "IMG") { var d = document.createElement("div"); d.className = "noimg"; d.textContent = "🂠"; e.target.replaceWith(d); }
  }, true);

  function notifyNew() {
    var relevant = S.hits.filter(function (h) { return h.missing_nums.length && h.confidence !== "niedrig"; });
    var ids = {}; relevant.forEach(function (h) { ids[h.id] = 1; });
    if (known && known.set === setKey && "Notification" in window && Notification.permission === "granted") {
      relevant.filter(function (h) { return !known.ids[h.id]; }).slice(0, 3).forEach(function (h) {
        new Notification(S.title + ": neues Angebot", { body: h.title + " – " + h.price_raw });
      });
    }
    known = { set: setKey, ids: ids };
    var n = relevant.filter(function (h) { return !h.viewed; }).length;
    document.title = document.title.replace(/^\(\d+\) /, "");
    if (n) document.title = "(" + n + ") " + document.title;
  }

  // ---------- Interaktion ----------
  $("pcrSet").onchange = function () {
    markViewed(); setKey = this.value; selCard = null; S = null;
    $("pcrHits").innerHTML = '<div class="pcr-empty">Lade …</div>'; load();
  };
  $("pcrGrid").addEventListener("click", function (e) {
    var t = e.target.closest(".pcr-tile"); if (!t || !S) return;
    var n = +t.dataset.n;
    if (editing) {
      var c = card(n);
      c.owned = !c.owned; render();
      api("/toggle", { set: setKey, num: n, owned: c.owned }).then(load);
    } else { selCard = selCard === n ? null : n; render(); }
  });
  $("pcrEdit").onclick = function () {
    editing = !editing;
    this.textContent = editing ? "Fertig" : "Bearbeiten";
    $("pcrBulk").style.display = editing ? "block" : "none";
    $("pcrGrid").classList.toggle("editing", editing);
    $("pcrGridHint").textContent = editing ? "Bearbeiten: Klick = vorhanden ↔ fehlt" : "Klick auf eine Karte filtert die Treffer.";
  };
  function bulk(mode) {
    api("/collection", { set: setKey, mode: mode, text: $("pcrBulkText").value }).then(function () { $("pcrBulkText").value = ""; load(); });
  }
  $("pcrSaveMissing").onclick = function () { bulk("missing"); };
  $("pcrSaveOwned").onclick = function () { bulk("owned"); };
  $("pcrScan").onclick = function () { api("/scan", {}).then(function () { setTimeout(load, 1500); }); };
  $("pcrChatSave").onclick = function () {
    var b = this;
    api("/telegram", { chat: $("pcrChat").value.trim() }).then(function () { b.textContent = "Gespeichert ✓"; setTimeout(function () { b.textContent = "Speichern"; }, 1500); })
      .catch(function (e) { alert(e.message); });
  };
  $("pcrHits").addEventListener("click", function (e) {
    var id = e.target.getAttribute("data-hide"); if (!id) return;
    api("/hide", { set: setKey, id: id }).then(load);
  });
  $("pcrFilters").addEventListener("click", function (e) {
    var c = e.target.closest(".pcr-chip"); if (!c) return;
    var f = c.dataset.f;
    if (f === "src") { F.src = c.dataset.v; root.querySelectorAll('[data-f="src"]').forEach(function (x) { x.classList.toggle("on", x === c); }); }
    else if (f === "low") { F.low = !F.low; c.classList.toggle("on", F.low); }
    else if (f === "lots") { F.lots = !F.lots; c.classList.toggle("on", F.lots); }
    else if (f === "sort") { F.sort = F.sort === "price" ? "new" : "price"; c.classList.toggle("on", F.sort === "price"); }
    if (S) render();
  });

  // Beim Verlassen der Seite / Set-Wechsel gelten Treffer als gesehen
  function markViewed() {
    if (!S) return;
    fetch(CFG.restUrl + "/viewed", { method: "POST", credentials: "same-origin", keepalive: true,
      headers: { "Content-Type": "application/json", "X-WP-Nonce": CFG.nonce }, body: JSON.stringify({ set: setKey }) });
  }
  document.addEventListener("visibilitychange", function () { if (document.hidden) markViewed(); });
  if ("Notification" in window && Notification.permission === "default") {
    root.addEventListener("click", function () { Notification.requestPermission(); }, { once: true });
  }

  load();
  timer = setInterval(function () { if (!document.hidden) load(); }, 20000);
})();
