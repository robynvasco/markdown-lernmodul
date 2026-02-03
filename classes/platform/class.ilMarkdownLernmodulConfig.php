<?php
declare(strict_types=1);
/**
 *  This file is part of the Markdown Lernmodul Repository Object plugin for ILIAS, which allows your platform's users
 *  To create interactive quizzes in Markdown format with AI assistance
 *  This plugin is adapted from the AI Chat plugin.
 *
 *  The Markdown Lernmodul Repository Object plugin for ILIAS is open-source and licensed under GPL-3.0.
 *
 */

namespace platform;

require_once __DIR__ . '/class.ilMarkdownLernmodulEncryption.php';

/**
 * Configuration Manager for MarkdownLernmodul Plugin
 * 
 * Handles loading, storing, and encrypting plugin configuration values.
 * Automatically encrypts sensitive data (API keys) using AES-256-CBC.
 * 
 * Features:
 * - Lazy loading from database
 * - Automatic encryption of API keys
 * - JSON encoding for complex values
 * - Transaction-safe updates
 * 
 * @package platform
 */
class ilMarkdownLernmodulConfig
{
    private static array $config = [];
    private static array $updated = [];
    
    // List of configuration keys that should be encrypted
    private const ENCRYPTED_KEYS = [
        'gwdg_api_key',
        'google_api_key',
        'openai_api_key'
    ];

    /**
     * Load plugin configuration from database
     * 
     * Loads all config values into static cache. JSON values are automatically decoded.
     * Silently handles missing table during installation/uninstallation.
     * 
     * @return void
     * @throws ilMarkdownLernmodulException On database errors
     */
    public static function load(): void
    {
        try {
            // Check if table exists first (important during uninstall)
            global $DIC;
            if (!$DIC->database()->tableExists('xmdl_config')) {
                self::$config = [];
                return;
            }
            
            $config = (new ilMarkdownLernmodulDatabase)->select('xmdl_config');

            foreach ($config as $row) {
                // Skip if row is null or doesn't have required keys
                if (!is_array($row) || !isset($row['name'])) {
                    continue;
                }
                
                if (isset($row['value']) && $row['value'] !== '') {
                    $json_decoded = json_decode($row['value'], true);

                    if (json_last_error() === JSON_ERROR_NONE) {
                        $row['value'] = $json_decoded;
                    }
                }

                self::$config[$row['name']] = $row['value'] ?? null;
            }
        } catch (ilMarkdownLernmodulException $e) {
            // Silently ignore if table doesn't exist yet during plugin activation
            if (strpos($e->getMessage(), 'xmdl_config') !== false) {
                return;
            }
            throw $e;
        }
    }

    /**
     * Set configuration value
     * 
     * Automatically encrypts API keys before storing.
     * Marks key as updated for save().
     * 
     * @param string $key Configuration key
     * @param mixed $value Value to store
     * @return void
     */
    public static function set(string $key, $value): void
    {
        if (is_bool($value)) {
            $value = (int)$value;
        }
        
        // Encrypt API keys before storing
        if (in_array($key, self::ENCRYPTED_KEYS) && is_string($value) && !empty($value)) {
            // Only encrypt if not already encrypted
            if (!ilMarkdownLernmodulEncryption::isEncrypted($value)) {
                $value = ilMarkdownLernmodulEncryption::encrypt($value);
            }
        }

        if (!isset(self::$config[$key]) || self::$config[$key] !== $value) {
            self::$config[$key] = $value;
            self::$updated[$key] = true;
        }
    }

    /**
     * Get configuration value
     * 
     * Automatically decrypts API keys when retrieving.
     * Falls back to database if not in cache.
     * 
     * @param string $key Configuration key
     * @return mixed Configuration value
     * @throws ilMarkdownLernmodulException On database errors
     */
    public static function get(string $key)
    {
        $value = self::$config[$key] ?? self::getFromDB($key);
        
        // Decrypt API keys when retrieving
        if (in_array($key, self::ENCRYPTED_KEYS) && is_string($value) && !empty($value)) {
            return ilMarkdownLernmodulEncryption::decrypt($value);
        }
        
        return $value;
    }

    /**
     * Get configuration value directly from database
     * 
     * Bypasses cache and queries database. JSON values are automatically decoded.
     * 
     * @param string $key Configuration key
     * @return mixed Configuration value or empty string if not found
     * @throws ilMarkdownLernmodulException On database errors
     */
    public static function getFromDB(string $key)
    {
        try {
            $config = (new ilMarkdownLernmodulDatabase)->select('xmdl_config', array(
                'name' => $key
            ));

            if (count($config) > 0) {
                $json_decoded = json_decode($config[0]['value'], true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    $config[0]['value'] = $json_decoded;
                }

                self::$config[$key] = $config[0]['value'];

                return $config[0]['value'];
            } else {
                return "";
            }
        } catch (ilMarkdownLernmodulException $e) {
            // Silently ignore if table doesn't exist yet during plugin activation
            if (strpos($e->getMessage(), 'xmdl_config') !== false) {
                return "";
            }
            throw $e;
        }
    }

    /**
     * Get all configuration values
     * 
     * @return array All config values from cache
     */
    public static function getAll(): array
    {
        return self::$config;
    }

    /**
     * Save updated configuration values to database
     * 
     * Only saves values marked as updated. Uses INSERT ON DUPLICATE KEY UPDATE.
     * Arrays are JSON-encoded before storage.
     * 
     * @return bool|string True on success, error message on failure
     */
    public static function save()
    {
        foreach (self::$updated as $key => $exist) {
            if ($exist) {
                if (isset(self::$config[$key])) {
                    $data = array(
                        'name' => $key
                    );

                    if (is_array(self::$config[$key])) {
                        $data['value'] = json_encode(self::$config[$key]);
                    } else {
                        $data['value'] = self::$config[$key];
                    }

                    try {
                        (new ilMarkdownLernmodulDatabase)->insertOnDuplicatedKey('xmdl_config', $data);

                        self::$updated[$key] = false;
                    } catch (ilMarkdownLernmodulException $e) {
                        // Silently ignore if table doesn't exist yet during plugin activation
                        if (strpos($e->getMessage(), 'xmdl_config') !== false) {
                            continue;
                        }
                        return $e->getMessage();
                    }
                }
            }
        }

        // In case there is nothing to update, return true to avoid error messages
        return true;
    }
    
    /**
     * Clear the cached configuration (useful for testing)
     * @return void
     */
    public static function clearCache(): void
    {
        self::$config = [];
        self::$updated = [];
    }
}


