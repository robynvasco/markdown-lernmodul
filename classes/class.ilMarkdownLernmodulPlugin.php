<?php
declare(strict_types=1);

require_once __DIR__ . '/platform/class.ilMarkdownLernmodulConfig.php';
require_once __DIR__ . '/platform/class.ilMarkdownLernmodulEncryption.php';

use platform\ilMarkdownLernmodulConfig;
use platform\ilMarkdownLernmodulEncryption;

/**
 * MarkdownLernmodul Plugin Main Class
 * 
 * This is the central plugin class that defines the plugin's identity and
 * handles lifecycle events (installation, update, uninstall).
 * 
 * Features:
 * - Automatic API key encryption migration on update
 * - Clean uninstall with complete data removal
 * - Support for object copying
 * 
 * @author  Robyn Vasco
 * @version 0.1.0
 */
class ilMarkdownLernmodulPlugin extends ilRepositoryObjectPlugin
{
    /** @var string Plugin identifier (must start with 'x' for plugin types) */
    public const PLUGIN_ID = "xmdl";
    
    /** @var string Human-readable plugin name */
    public const PLUGIN_NAME = "MarkdownLernmodul";

    /**
     * Get the plugin name
     * Required by ILIAS plugin interface
     * 
     * @return string The plugin name
     */
    public function getPluginName(): string
    {
        return self::PLUGIN_NAME;
    }
    
    /**
     * Enable plugin configuration
     * 
     * @return bool True to show configure button in plugin administration
     */
    protected function hasConfigureClass(): bool
    {
        return true;
    }
    
    /**
     * Post-update hook
     * 
     * Called automatically by ILIAS after plugin is updated to a new version.
     * Handles data migration tasks:
     * - Encrypts existing plaintext API keys with AES-256-CBC
     * - Errors are logged but don't fail the update
     */
    protected function afterUpdate(): void
    {
        try {
            // Migrate existing API keys to encrypted format
            ilMarkdownLernmodulEncryption::migrateApiKeys();
        } catch (\Exception $e) {
            // Log error but don't fail the update
            error_log("MarkdownLernmodul: API key migration failed: " . $e->getMessage());
        }
    }

    /**
     * Custom uninstall cleanup
     * 
     * Removes all plugin data from the database:
     * - xmdl_config: Configuration including encrypted API keys
     * - rep_robj_xmdl_data: Quiz content and metadata
     * 
     * Note: ILIAS handles removing object_data entries automatically
     */
    protected function uninstallCustom(): void
    {
        global $DIC;
        $db = $DIC->database();
        
        // Drop plugin tables to clean up all data including API keys
        if ($db->tableExists('xmdl_config')) {
            $db->dropTable('xmdl_config');
        }
        
        if ($db->tableExists('rep_robj_xmdl_data')) {
            $db->dropTable('rep_robj_xmdl_data');
        }
    }

    /**
     * Allow copying of learning modules
     * 
     * @return bool True to enable copy functionality in ILIAS
     */
    public function allowCopy(): bool
    {
        return true;
    }

    /**
     * Get the title icon
     * 
     * Used for object list, creation GUI, info screen, export and permission tabs.
     * Returns the SVG icon path for the plugin.
     * 
     * @param string $a_type The object type (should be "xmdl")
     * @return string Path to the icon file relative to ILIAS root
     */
    public static function _getIcon(string $a_type): string
    {
        return 'Customizing/global/plugins/Services/Repository/RepositoryObject/MarkdownLernmodul/templates/images/icon_xmdl.svg';
    }
}