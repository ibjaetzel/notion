# SymconNotionTasks

Eigenständiges IP-Symcon Tile-Modul zur Anzeige aktueller Aufgaben aus Dirks Notion-Datenbank **Aufgaben**.

## Verwendete Notion-Datenbank

Die Datenbank wurde aus der Notion-Seite **Huskeliste** erkannt:

- Datenbank: `Aufgaben`
- Database ID / Collection ID: `c53ffce3-985d-4bae-96f0-961aab197c9f`

Voreingestellte Properties:

- Titel: `Name / Aufgabe`
- Status: `Status`
- Erledigt: `Erledigt`
- Fälligkeit: `Zieldatum`
- Priorität: `Priorität`

## Installation

Ordner `SymconNotionTasks` in das IP-Symcon `modules`-Verzeichnis kopieren oder als lokales Git-Repository einbinden.

Struktur:

```text
SymconNotionTasks/
├─ library.json
└─ NotionTasks/
   ├─ module.json
   ├─ module.php
   ├─ form.json
   └─ module.html
```

Danach IP-Symcon Console neu laden und Instanz **Notion Aufgaben** anlegen.

## Notion API Token

Das Modul benötigt einen Notion Integration Token.

1. In Notion eine interne Integration erstellen.
2. Die Integration zur Datenbank `Aufgaben` einladen bzw. Zugriff geben.
3. Token in der Modulkonfiguration eintragen.

## Anzeige

Das Modul zeigt offene Aufgaben nach Fälligkeit und Priorität an. Erledigte Aufgaben werden standardmäßig ausgeblendet.

Filter und Property-Namen sind in der Instanz konfigurierbar.
