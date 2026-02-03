<?php
declare(strict_types=1);

namespace platform;

use Exception;
use ilDBInterface;

/**
 * Datenbankzugriffsklasse für das MarkdownLernmodul Plugin
 * 
 * Diese Klasse bietet sichere Datenbankoperationen mit eingebauter SQL-Injection-Schutz.
 * Alle Queries verwenden prepared statements über die ILIAS-Datenbank-API.
 * 
 * Sicherheitsfeatures:
 * - Whitelist-basierte Tabellennamen-Validierung (nur erlaubte Tabellen)
 * - Automatisches Quoting aller Werte über ilDBInterface
 * - Exception-Handling für alle Datenbankfehler
 * 
 * Verwendung:
 * ```php
 * $db = new ilMarkdownLernmodulDatabase();
 * $db->insert('xmdl_config', ['name' => 'api_key', 'value' => 'encrypted_key']);
 * ```
 * 
 * @package platform
 */
class ilMarkdownLernmodulDatabase
{
    /**
     * Whitelist der erlaubten Tabellennamen für SQL-Injection-Schutz
     * Nur diese Tabellen dürfen über diese Klasse abgefragt werden
     */
    const ALLOWED_TABLES = [
        'xmdl_config',           // Plugin-Konfiguration (API-Keys, Einstellungen)
        'rep_robj_xmdl_data'     // Quiz-Inhalte und Metadaten
    ];

    /**
     * ILIAS Datenbank-Interface
     * Wird über den Dependency Injection Container injiziert
     */
    private ilDBInterface $db;

    /**
     * Konstruktor - Initialisiert die Datenbankverbindung
     * Verwendet den globalen ILIAS DIC (Dependency Injection Container)
     */
    public function __construct()
    {
        global $DIC;
        $this->db = $DIC->database();
    }

    /**
     * Fügt eine neue Zeile in die Datenbank ein
     * 
     * Erstellt ein INSERT-Statement mit automatischem Quoting aller Werte.
     * Schlägt fehl, wenn ein Primärschlüssel bereits existiert.
     * 
     * @param string $table Tabellenname (muss in ALLOWED_TABLES sein)
     * @param array $data Assoziatives Array: ['spaltenname' => 'wert']
     * @return void
     * @throws ilMarkdownLernmodulException Bei ungültigem Tabellennamen oder DB-Fehler
     * 
     * @example
     * ```php
     * $db->insert('xmdl_config', [
     *     'name' => 'system_prompt',
     *     'value' => 'Generate quiz questions about...'
     * ]);
     * ```
     */
    public function insert(string $table, array $data): void
    {
        // Sicherheitsprüfung: Nur erlaubte Tabellen dürfen verwendet werden
        if (!$this->validateTableName($table)) {
            throw new ilMarkdownLernmodulException("Invalid table name: " . $table);
        }

        try {
            // Baue INSERT-Query: INSERT INTO tabelle (spalte1, spalte2) VALUES (wert1, wert2)
            $this->db->query("INSERT INTO " . $table . " (" . implode(", ", array_keys($data)) . ") VALUES (" . implode(", ", array_map(function ($value) {
                    return $this->db->quote($value); // Automatisches Escaping für SQL-Injection-Schutz
                }, array_values($data))) . ")");
        } catch (Exception $e) {
            throw new ilMarkdownLernmodulException($e->getMessage());
        }
    }

    /**
     * Fügt eine Zeile ein oder aktualisiert sie bei Duplikat
     * 
     * MySQL-spezifisches INSERT ... ON DUPLICATE KEY UPDATE Statement.
     * Wenn der Primärschlüssel bereits existiert, wird die Zeile aktualisiert.
     * Nützlich für Upsert-Operationen (Insert or Update).
     * 
     * @param string $table Tabellenname (muss in ALLOWED_TABLES sein)
     * @param array $data Assoziatives Array: ['spaltenname' => 'wert']
     * @return void
     * @throws ilMarkdownLernmodulException Bei ungültigem Tabellennamen oder DB-Fehler
     * 
     * @example
     * ```php
     * // Erstellt neuen Eintrag oder aktualisiert existierenden
     * $db->insertOnDuplicatedKey('xmdl_config', [
     *     'name' => 'ai_enabled',
     *     'value' => true
     * ]);
     * ```
     */
    public function insertOnDuplicatedKey(string $table, array $data): void
    {
        if (!$this->validateTableName($table)) {
            throw new ilMarkdownLernmodulException("Invalid table name: " . $table);
        }

        try {
            // INSERT ... ON DUPLICATE KEY UPDATE: Fügt ein oder aktualisiert bei Konflikt
            $this->db->query("INSERT INTO " . $table . " (" . implode(", ", array_keys($data)) . ") VALUES (" . implode(", ", array_map(function ($value) {
                    return $this->db->quote($value);
                }, array_values($data))) . ") ON DUPLICATE KEY UPDATE " . implode(", ", array_map(function ($key, $value) {
                    // Bei Duplikat: Setze jede Spalte auf den neuen Wert
                    return $key . " = " . $value;
                }, array_keys($data), array_map(function ($value) {
                    return $this->db->quote($value);
                }, array_values($data)))));
        } catch (Exception $e) {
            throw new ilMarkdownLernmodulException($e->getMessage());
        }
    }

    /**
     * Aktualisiert bestehende Zeilen in der Datenbank
     * 
     * Führt ein UPDATE-Statement mit WHERE-Bedingungen aus.
     * Alle Werte werden automatisch escaped für SQL-Injection-Schutz.
     * 
     * @param string $table Tabellenname (muss in ALLOWED_TABLES sein)
     * @param array $data Zu aktualisierende Daten: ['spaltenname' => 'neuer_wert']
     * @param array $where WHERE-Bedingungen: ['spaltenname' => 'wert']
     * @return void
     * @throws ilMarkdownLernmodulException Bei ungültigem Tabellennamen oder DB-Fehler
     * 
     * @example
     * ```php
     * // Aktualisiere den Wert einer Konfiguration
     * $db->update('xmdl_config', 
     *     ['value' => 'new_value'],           // SET value = 'new_value'
     *     ['name' => 'system_prompt']          // WHERE name = 'system_prompt'
     * );
     * ```
     */
    public function update(string $table, array $data, array $where): void
    {
        if (!$this->validateTableName($table)) {
            throw new ilMarkdownLernmodulException("Invalid table name: " . $table);
        }

        try {
            // UPDATE tabelle SET spalte1 = wert1, spalte2 = wert2 WHERE bedingung
            $this->db->query("UPDATE " . $table . " SET " . implode(", ", array_map(function ($key, $value) {
                    return $key . " = " . $value; // SET-Teil: spalte = wert
                }, array_keys($data), array_map(function ($value) {
                    return $this->db->quote($value);
                }, array_values($data)))) . " WHERE " . implode(" AND ", array_map(function ($key, $value) {
                    return $key . " = " . $value; // WHERE-Teil: spalte = wert AND ...
                }, array_keys($where), array_map(function ($value) {
                    return $this->db->quote($value);
                }, array_values($where)))));
        } catch (Exception $e) {
            throw new ilMarkdownLernmodulException($e->getMessage());
        }
    }

    /**
     * Löscht Zeilen aus der Datenbank
     * 
     * Führt ein DELETE-Statement mit WHERE-Bedingungen aus.
     * ACHTUNG: Ohne WHERE-Bedingungen würde die gesamte Tabelle gelöscht!
     * 
     * @param string $table Tabellenname (muss in ALLOWED_TABLES sein)
     * @param array $where WHERE-Bedingungen: ['spaltenname' => 'wert']
     * @return void
     * @throws ilMarkdownLernmodulException Bei ungültigem Tabellennamen oder DB-Fehler
     * 
     * @example
     * ```php
     * // Lösche eine spezifische Konfiguration
     * $db->delete('xmdl_config', ['name' => 'old_api_key']);
     * ```
     */
    public function delete(string $table, array $where): void
    {
        if (!$this->validateTableName($table)) {
            throw new ilMarkdownLernmodulException("Invalid table name: " . $table);
        }

        try {
            // DELETE FROM tabelle WHERE bedingung AND bedingung
            $this->db->query("DELETE FROM " . $table . " WHERE " . implode(" AND ", array_map(function ($key, $value) {
                    return $key . " = " . $value;
                }, array_keys($where), array_map(function ($value) {
                    return $this->db->quote($value);
                }, array_values($where)))));
        } catch (Exception $e) {
            throw new ilMarkdownLernmodulException($e->getMessage());
        }
    }

    /**
     * Liest Zeilen aus der Datenbank
     * 
     * Führt ein SELECT-Statement mit optionalen WHERE-Bedingungen aus.
     * Gibt alle gefundenen Zeilen als Array zurück.
     * 
     * @param string $table Tabellenname (muss in ALLOWED_TABLES sein)
     * @param array|null $where Optional: WHERE-Bedingungen ['spaltenname' => 'wert']
     * @param array|null $columns Optional: Zu selektierende Spalten (default: alle mit *)
     * @param string|null $extra Optional: Extra SQL (ORDER BY, LIMIT etc.) - wird sanitized
     * @return array Array von assoziativen Arrays mit den Ergebniszeilen
     * @throws ilMarkdownLernmodulException Bei ungültigem Tabellennamen oder DB-Fehler
     * 
     * @example
     * ```php
     * // Alle Configs abrufen
     * $all = $db->select('xmdl_config');
     * 
     * // Spezifische Config abrufen
     * $config = $db->select('xmdl_config', ['name' => 'api_key']);
     * 
     * // Mit ORDER BY und LIMIT
     * $recent = $db->select('rep_robj_xmdl_data', null, ['id', 'title'], 'ORDER BY id DESC LIMIT 10');
     * ```
     */
    public function select(string $table, ?array $where = null, ?array $columns = null, ?string $extra = ""): array
    {
        if (!$this->validateTableName($table)) {
            throw new ilMarkdownLernmodulException("Invalid table name: " . $table);
        }

        try {
            // Baue SELECT-Query: SELECT spalten FROM tabelle
            $query = "SELECT " . (isset($columns) ? implode(", ", $columns) : "*") . " FROM " . $table;

            // Füge WHERE-Bedingungen hinzu, falls vorhanden
            if (isset($where)) {
                $query .= " WHERE " . implode(" AND ", array_map(function ($key, $value) {
                        return $key . " = " . $value;
                    }, array_keys($where), array_map(function ($value) {
                        return $this->db->quote($value);
                    }, array_values($where))));
            }

            // Füge zusätzliche SQL-Klauseln hinzu (ORDER BY, LIMIT etc.)
            // strip_tags() als minimaler Schutz vor HTML/Script-Injection
            if (is_string($extra)) {
                $extra = strip_tags($extra);
                $query .= " " . $extra;
            }

            // Führe Query aus
            $result = $this->db->query($query);

            // Sammle alle Ergebniszeilen
            $rows = [];
            while ($row = $this->db->fetchAssoc($result)) {
                $rows[] = $row;
            }

            return $rows;
        } catch (Exception $e) {
            throw new ilMarkdownLernmodulException($e->getMessage());
        }
    }

    /**
     * Gibt die nächste verfügbare ID für eine Tabelle zurück
     * 
     * Verwendet ILIAS' eigene Sequenz-Mechanismus für Auto-Increment IDs.
     * Wichtig für manuelles Einfügen von Zeilen mit expliziten IDs.
     * 
     * @param string $table Tabellenname
     * @return int Die nächste verfügbare ID
     * @throws ilMarkdownLernmodulException Bei DB-Fehler
     * 
     * @example
     * ```php
     * $newId = $db->nextId('rep_robj_xmdl_data');
     * $db->insert('rep_robj_xmdl_data', ['id' => $newId, 'title' => 'New Quiz']);
     * ```
     */
    public function nextId(string $table): int
    {
        try {
            return (int) $this->db->nextId($table);
        } catch (Exception $e) {
            throw new ilMarkdownLernmodulException($e->getMessage());
        }
    }

    /**
     * Validiert Tabellennamen gegen Whitelist
     * 
     * Sicherheitsmechanismus: Nur Tabellen aus ALLOWED_TABLES dürfen verwendet werden.
     * Verhindert SQL-Injection über dynamische Tabellennamen.
     * 
     * @param string $identifier Zu prüfender Tabellenname
     * @return bool True wenn erlaubt, sonst false
     */
    private function validateTableName(string $identifier): bool
    {
        return in_array($identifier, self::ALLOWED_TABLES, true);
    }
}

