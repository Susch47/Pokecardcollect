# Pokecardcollect – PokéCard Screener (WordPress-Plugin)

Eingeloggte Nutzer können ein Pokémon-Set auswählen, alle Karten filtern und sortieren, ihre Sammlung pflegen,
Cardmarket-Preise sehen und mit einem Klick einen Anzeigentext für **Kleinanzeigen.de** oder **tutti.ch** erzeugen.

Vorkonfigurierte Sets: **Pitch Black** (Mega Evolution), **Base Set**, **Jungle**, **Fossil**. Weitere Sets lassen sich im Admin-Bereich hinzufügen.

## Funktionen (Version 0.1)

| Bereich | Was es kann |
|---|---|
| Login | WordPress-Konten. Gäste sehen auf der Seite ein Login-Formular (und einen Registrierungslink, wenn die Registrierung aktiv ist). |
| Screener | Set wählen. Filtern nach Name/Nummer, Seltenheit, Typ, Besitz (alle / vorhanden / fehlend / doppelt) und Preisspanne. Sortieren nach Nummer, Preis oder Name. |
| Sammlung | Anzahl pro Karte mit +/− setzen. Wird pro Nutzer gespeichert. Statistik: Fortschritt, Wert der Sammlung, Wert der fehlenden Karten. |
| Preise | Cardmarket-Preise (Trend, Ø 30 Tage, Ø Verkauf, Tiefstpreis, Reverse Holo) in EUR über die [Pokémon TCG API](https://pokemontcg.io). Link zur Karte auf Cardmarket. |
| Anzeige | Plattform (Kleinanzeigen in EUR / tutti in CHF), Zustand (NM…PO mit Preisabschlag), Preisbasis und Zusatz (z. B. 1. Edition oder Shadowless) wählen. Daraus werden Preisvorschlag, Titel und Beschreibung erzeugt und können kopiert werden. |

## Installation

1. Den Ordner `pokecard-screener/` als ZIP packen und unter **Plugins → Installieren → Plugin hochladen** hochladen, dann aktivieren.
2. **Einstellungen → PokéCard Screener**:
   - Einen kostenlosen API-Key von [dev.pokemontcg.io](https://dev.pokemontcg.io) eintragen (empfohlen).
   - Sets, Wechselkurs EUR→CHF und Standardtext für Anzeigen nach Bedarf anpassen.
3. Eine Seite anlegen, z. B. „Screener“, mit dem Shortcode `[pokecard_screener]`.
4. Damit sich Nutzer selbst registrieren können: **Einstellungen → Allgemein → „Jeder kann sich registrieren“** aktivieren (Rolle „Abonnent“).

Lokal testen: `docker compose up -d` und dann http://localhost:8080 öffnen. Das Plugin ist bereits eingebunden.

### Sets konfigurieren

Pro Zeile: `schluessel|Anzeigename|Suchanfrage` (Syntax der Pokémon TCG API):

```
pitch-black|Pitch Black (Mega Evolution)|set.name:"Pitch Black"
base1|Base Set|set.id:base1
base2|Jungle|set.id:base2
base3|Fossil|set.id:base3
```

Pitch Black (Juli 2026) wird über den Set-Namen gesucht, weil die Set-ID in der API noch nicht feststand. Falls dort keine Karten kommen,
die ID auf pokemontcg.io nachschlagen und `set.id:<id>` eintragen.

## Aufbau

```
pokecard-screener/
├── pokecard-screener.php          Plugin-Header, Bootstrap
├── includes/
│   ├── class-pcs-settings.php     Admin-Seite & Optionen
│   ├── class-pcs-api.php          Pokémon-TCG-API-Client (Karten + Cardmarket-Preise, Cache via Transients)
│   ├── class-pcs-collection.php   Sammlung je Nutzer (User-Meta)
│   ├── class-pcs-rest.php         REST: /wp-json/pcs/v1/{sets, sets/<key>/cards, collection}
│   └── class-pcs-shortcode.php    [pokecard_screener]
└── assets/  screener.js / screener.css   Frontend ohne Build-Schritt
```

## Roadmap: Foto → Anzeige

Ziel: Der Nutzer fotografiert eine Karte, und das Inserat wird automatisch erstellt.

1. **Kartenerkennung.** Foto-Upload (Handy-Kamera über `<input type="file" accept="image/*" capture>`). Ein Vision-Modell wie die Claude API
   liest Name, Kartennummer (`4/102`) und Set-Symbol aus und schätzt den Zustand vor. Mit Name und Nummer findet die
   Pokémon TCG API die genaue Karte. Der Nutzer bestätigt den Treffer.
2. **Preis.** Wie heute über die Cardmarket-Daten der Pokémon TCG API. Die offizielle Cardmarket-API vergibt seit einiger Zeit
   keine neuen Zugänge mehr an Privatpersonen. Deshalb ist der Umweg über pokemontcg.io aktuell der praktikable Weg.
3. **Inserat veröffentlichen.** Weder Kleinanzeigen.de noch tutti.ch bieten eine öffentliche API zum Erstellen von Anzeigen.
   Automatisches Ausfüllen per Bot verstösst gegen deren Nutzungsbedingungen. Realistisch ist:
   - fertiges „Anzeigen-Paket“ (Titel, Text, Preis, Fotos) zum Kopieren bzw. Teilen über das Teilen-Menü des Handys, wie heute schon beim Text, oder
   - eine offizielle Partner- oder Händler-Schnittstelle anfragen (Kleinanzeigen bietet so etwas gewerblichen Anbietern an).
