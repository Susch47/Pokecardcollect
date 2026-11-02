# Dunkelnacht-Radar (ME05 Pitch Black)

Sucht laufend auf **Kleinanzeigen.de** und **tutti.ch** nach den Karten, die dir aus Dunkelnacht / Pitch Black fehlen. Alle Treffer erscheinen in einem Live-Dashboard im Browser.

## Start

Voraussetzung: Python 3.11 oder neuer (`python3 --version`).

```bash
cd pitchtracker
python3 -m venv .venv && source .venv/bin/activate      # Windows: .venv\Scripts\activate
pip install -r requirements.txt
python tests/test_radar.py                               # optional: Offline-Tests
python -m pitchtracker                                   # öffnet http://127.0.0.1:8765
```

Solange das Terminal offen ist, scannt der Radar alle 10 Minuten. Mit **Jetzt scannen** startest du einen Scan sofort.

## Erste Einrichtung: fehlende Karten eintragen

Im Dashboard auf **Bearbeiten** klicken, dann:
- deine Fehlliste ins Feld eintragen, z. B. `48, 55, 85-120`, und **Als FEHLENDE übernehmen** klicken, **oder**
- einzelne Kacheln anklicken. Ein Klick schaltet eine Karte zwischen *vorhanden* und *fehlt* um.

Die Liste liegt in `data/collection.json` (`{"owned": [...]}`). Du kannst sie auch direkt bearbeiten oder aus deinem bestehenden Tracker befüllen.

## Wie gesucht wird

- **Breite Set-Suchen** („dunkelnacht pokemon“, „pitch black pokemon“, „pokemon me05“) finden alle Anzeigen zum Set, auch Sammlungen.
- **Gezielte Suchen** pro fehlender Karte („skelabra 115“). Pro Scan kommen 6 Karten dran, rotierend, Secret Rares zuerst.
- **Erkennung** in Titel und Beschreibung:
  - **Nummer erkannt**: `116/084` steht in der Anzeige → sicher.
  - **Name + Set**: z. B. „Gwynn SIR Dunkelnacht“. Die Seltenheit wird über SIR / IR / Full Art / Gold / Hyper Rare bestimmt.
  - **unsicher**: Name ohne Set-Bezug oder Variante unklar (z. B. „Mega Darkrai ex“ gibt es viermal: 048, 101, 116, 120). Standardmässig ausgeblendet, über den Filter **auch unsichere** sichtbar.
- **Aussortiert** werden Gesuche und Tauschanzeigen, Displays, ETBs und andere Sealed-Produkte, Proxys und Fakes sowie japanische, chinesische und koreanische Versionen.
- **Markiert** werden Lots/Sammlungen, gegradete Karten (PSA/CGC …), die Sprache (DE/EN), Preissenkungen und Anzeigen, die nicht mehr auftauchen.
- **Preise**: Kleinanzeigen in EUR (mit CHF-Umrechnung), tutti in CHF. Den Cardmarket-Richtwert lädt der Radar über TCGdex, sofern verfügbar.

## Optional

- **Handy-Push über Telegram**: Bot bei @BotFather anlegen und vor dem Start `TELEGRAM_BOT_TOKEN` und `TELEGRAM_CHAT_ID` setzen. Du bekommst dann eine Nachricht pro neuem, sicher erkanntem Treffer.
- **Dashboard auf dem Handy im WLAN**: `python -m pitchtracker --host 0.0.0.0`, dann `http://<IP-des-Rechners>:8765` öffnen.
- **Desktop-Benachrichtigung**: Beim ersten Klick fragt der Browser nach der Erlaubnis.
- Intervall, Suchbegriffe und Wechselkurs stellst du in `config.yaml` ein.

## Grenzen

- Kleinanzeigen und tutti erlauben automatisches Auslesen laut ihren Regeln nicht. tutti wird über seine interne Such-API abgefragt (Bibliothek `tutti-scraper`). Für den Privatgebrauch mit 10-Minuten-Intervall und Pausen zwischen Anfragen ist das moderat. Bei einer Sperre (403/429) zeigt das Dashboard einen roten Status. Dann das Intervall erhöhen.
- Wenn eine der Seiten ihr Format ändert, bleibt die Quelle leer oder zeigt einen Fehler. Die Tests und das Protokoll im Dashboard zeigen, wo es hakt.
- Trainer- und Item-Namen erkennt der Radar auf Deutsch erst, wenn TCGdex erreichbar ist. Bis dahin klappt die Erkennung bei diesen Karten nur über die Kartennummer oder den englischen Namen.
