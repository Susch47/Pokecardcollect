# Pokecardcollect – PokéCard Radar

WordPress-Plugin, das auf **Kleinanzeigen.de** und **tutti.ch** nach den Pokémon-Karten sucht, die deinen Nutzern fehlen.
Es basiert auf dem Python-Tool „Dunkelnacht-Radar“ (`tools/pitchtracker`) und läuft jetzt als Website mit Login für mehrere Nutzer und Sets.

**Sets:** Pitch Black / Dunkelnacht (ME05), Base Set, Jungle (Dschungel), Fossil.

## Funktionen

| Bereich | Was es kann |
|---|---|
| Login | WordPress-Konten. Gäste sehen auf der Seite ein Login-Formular und, falls die Registrierung aktiv ist, einen Link zum Registrieren. |
| Sammlung | Raster pro Set. Ein Klick schaltet eine Karte zwischen *vorhanden* und *fehlt* um, oder du gibst eine Liste ein (`85-120, 48, 55`). Jeder Nutzer hat seine eigene Sammlung. |
| Radar | Durchsucht Kleinanzeigen und tutti mit breiten Set-Suchen und rotierenden Einzelkarten-Suchen (wertvollste zuerst). Gescannt wird **einmal pro Set**, nicht pro Nutzer. Jeder Nutzer sieht nur die Angebote für **seine** fehlenden Karten. |
| Erkennung | **Nummer erkannt** (`116/084`, `4/102`), **Name + Set** (deutsch und englisch, Seltenheit über SIR/IR/Holo/„nicht holo“) oder **unsicher**. Aussortiert werden Gesuche, Sealed-Produkte, Fakes/Proxys, japanische, chinesische und koreanische Karten sowie Neuauflagen (Base Set 2, Celebrations, Evolutions). |
| Markierungen | Lot/Sammlung, PSA/CGC-Grading, Sprache, **1. Edition / Shadowless**, Preissenkung, „nicht mehr gesehen“ und **verdächtig günstig** (unter 35 % vom Marktwert). |
| Preise | Cardmarket-Richtwert über TCGdex. Bei Holo-Karten der alten Sets zählt der Holo-Preis. Kleinanzeigen-Preise werden in CHF umgerechnet. |
| Push | Telegram: Der Admin hinterlegt den Bot-Token, jeder Nutzer trägt seine eigene Chat-ID ein. Dazu kommen Browser-Benachrichtigungen. |

## Installation

1. Den Ordner `pokecard-radar/` als ZIP packen und unter **Plugins → Installieren → Plugin hochladen** hochladen, dann aktivieren.
2. Eine Seite anlegen, z. B. „Radar“, mit dem Shortcode `[pokecard_radar]` (optional `[pokecard_radar set="base1"]`).
3. Damit sich Nutzer selbst registrieren können: **Einstellungen → Allgemein → „Jeder kann sich registrieren“** aktivieren.
4. **Einstellungen → PokéCard Radar**: Sets, Intervall, Wechselkurs und optional den Telegram-Bot-Token einstellen.
5. **Wichtig: einen echten Cron einrichten.** WP-Cron läuft sonst nur, wenn jemand die Website besucht.
   ```php
   // wp-config.php
   define( 'DISABLE_WP_CRON', true );
   ```
   Dann beim Hoster einen Cronjob anlegen, der jede Minute `https://deine-seite.ch/wp-cron.php` aufruft.

Lokal testen: Mit `docker compose up -d` startet WordPress unter http://localhost:8080, das Plugin ist bereits eingebunden.

### Wie der Scan läuft

Jede Minute arbeitet ein Cron-Lauf höchstens ~25 Sekunden lang eine Warteschlange ab. Das Budget lässt sich einstellen und muss unter dem PHP-Zeitlimit des Hostings liegen.
Ein kompletter Durchlauf über alle Sets startet alle 15 Minuten (einstellbar, mindestens 10).
Zwischen zwei Anfragen an Kleinanzeigen liegen 4–9 s Pause, bei tutti 2 s.
Bei einer Sperre (HTTP 403/429) pausiert die Quelle bis zum nächsten Durchlauf. Das Dashboard zeigt dann einen roten Status.

## ⚠️ Grenzen und Risiken

- **Kleinanzeigen und tutti verbieten das automatische Auslesen in ihren Nutzungsbedingungen.** Für den Privatgebrauch ist das ein überschaubares Risiko. Als öffentliche Website für viele Nutzer ist es eine andere Grössenordnung: Abmahnungen oder Sperren sind möglich. Vor einem öffentlichen Start rechtlich prüfen lassen oder die Plattformen nach einer Partner-Schnittstelle fragen.
- Server von Webhostern werden schneller gesperrt als private Anschlüsse. Läuft es auf dem Hosting nicht, kann der Python-Radar zu Hause scannen und die WordPress-Seite nur anzeigen (siehe „Nächste Schritte“).
- tutti wird über seine interne GraphQL-API abgefragt (dieselbe Abfrage wie in der Bibliothek `tutti-scraper`). Ändert tutti das Format, bleibt die Quelle leer.
- Trainer-Namen auf Deutsch kennt das Plugin erst, wenn TCGdex erreichbar war. Bis dahin werden Trainer nur über die Nummer oder den englischen Namen erkannt.
- Der Cardmarket-Richtwert gilt für Unlimited. Bei 1. Edition und Shadowless zeigt das Plugin deshalb keinen Marktvergleich.

## Aufbau

```
pokecard-radar/
├── pokecard-radar.php             Plugin-Header, Bootstrap
├── includes/
│   ├── sets.php                   Kartenlisten der 4 Sets (EN/DE, Seltenheit) + Such- und Erkennungsregeln
│   ├── class-pcr-set.php          Set-Modell
│   ├── class-pcr-matcher.php      Erkennung (Port von matcher.py, erweitert für alte Sets)
│   ├── class-pcr-sources.php      Kleinanzeigen (HTML) und tutti (GraphQL)
│   ├── class-pcr-engine.php       Warteschlange/Cron, Speicherung, TCGdex-Preise, Telegram, Zustand pro Nutzer
│   ├── class-pcr-rest.php         REST: /wp-json/pcr/v1/{sets,state,toggle,collection,hide,viewed,telegram,scan}
│   ├── class-pcr-settings.php     Admin-Seite
│   └── class-pcr-shortcode.php    [pokecard_radar]
├── assets/radar.js, radar.css     Dashboard (Design vom Dunkelnacht-Radar)
└── tests/                         php tests/run.php · php tests/engine-test.php
tools/
├── pitchtracker/                  Ursprünglicher Dunkelnacht-Radar (Python, lokal)
└── poketracker/                   Deal-Tracker mit Scam-/Gewinn-Bewertung (Python, lokal)
```

Speicherung: Treffer liegen in der Tabelle `wp_pcr_hits` (einmal pro Set). Sammlung, ausgeblendete Treffer, „gesehen“-Zeitpunkt und Telegram-Chat-ID sind User-Meta.

## Tests

```bash
php pokecard-radar/tests/run.php          # Erkennung, Parser (ohne WordPress)
php pokecard-radar/tests/engine-test.php  # Scanner Ende-zu-Ende mit WordPress-Ersatz
```

## Nächste Schritte

1. **Deal-Bewertung aus `poketracker` übernehmen:** Zustand (Knick, Whitening), Verkäufer-Risiko (PayPal F&F, junges Konto) sowie Gewinn und Maximalangebot für Wiederverkäufer.
2. **Weitere Sets:** In `includes/sets.php` einen Eintrag ergänzen (Kartenliste, Suchbegriffe, Set-Bezug).
3. **Foto → Anzeige:** Karte fotografieren, von einem KI-Bildmodell erkennen lassen, Preisvorschlag aus Cardmarket/TCGdex und fertiger Anzeigentext. Weder Kleinanzeigen noch tutti bieten eine öffentliche Schnittstelle zum Veröffentlichen. Der Nutzer übernimmt das fertige Paket also selbst.
4. **Scanner auslagern (falls das Hosting gesperrt wird):** Der Python-Radar scannt zu Hause und schickt die Treffer per REST an WordPress.
