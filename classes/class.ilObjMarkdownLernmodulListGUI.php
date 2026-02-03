<?php
declare(strict_types=1);

/**
 * List GUI for MarkdownLernmodul Plugin
 * 
 * Controls how flashcard decks appear in the ILIAS repository list view.
 * Defines available commands and displays status information.
 * 
 * @author  Your Name
 * @version 1.0
 */
class ilObjMarkdownLernmodulListGUI extends ilObjectPluginListGUI
{
    /**
     * Get the GUI class name for handling quiz actions
     * 
     * @return string Fully qualified class name
     */
    public function getGuiClass(): string
    {
        return ilObjMarkdownLernmodulGUI::class;
    }

    /**
     * Define commands/actions available in the list view
     * 
     * Returns an array of command definitions with:
     * - permission: Required RBAC permission
     * - cmd: Command name to execute
     * - default: Whether this is the default action
     * - txt: Display text (optional)
     * 
     * @return array Command definitions
     */
    public function initCommands(): array
    {
        return [
            [
                "permission" => "read",
                "cmd" => "view",      // Der Standard-Befehl beim Klick auf den Titel
                "default" => true,
            ],
            [
                "permission" => "write",
                "cmd" => "settings",  // Schnellzugriff auf die Einstellungen
                "txt" => "Einstellungen"
            ]
        ];
    }

    /**
     * Set the plugin object type identifier
     */
    public function initType(): void
    {
        $this->setType("xmdl");
    }

    /**
     * Display custom properties in the list view
     * 
     * Shows status information like offline/online state to administrators.
     * Adds an alert badge when quiz is offline.
     * 
     * @param array $a_prop Existing properties
     * @return array Extended properties array
     */
    public function getCustomProperties($a_prop): array
    {
        $props = parent::getCustomProperties($a_prop);
        
        // Show offline status
        if (ilObjMarkdownLernmodulAccess::_isOffline($this->obj_id)) {
            $props[] = [
                "alert" => true,
                "property" => "Status",
                "value" => "Offline"
            ];
        }
        
        return $props;
    }
}