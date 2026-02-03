<?php
declare(strict_types=1);
/**
 *  This file is part of the Markdown Lernmodul Repository Object plugin for ILIAS
 *  Provides encryption/decryption services for sensitive data like API keys
 */

namespace platform;

/**
 * Encryption Service for MarkdownLernmodul Plugin
 * 
 * Provides AES-256-CBC encryption for sensitive config values (API keys).
 * Uses PBKDF2 key derivation from ILIAS client ID and password salt.
 * 
 * Format: base64(IV + encrypted_data)
 * - IV: 16 bytes (random per encryption)
 * - Key: 32 bytes (derived from client ID + salt)
 * 
 * @package platform
 */
class ilMarkdownLernmodulEncryption
{
    // Encryption method - AES-256-CBC is secure and widely supported
    private const ENCRYPTION_METHOD = 'aes-256-cbc';
    
    // Fixed IV length for AES-256-CBC (16 bytes)
    private const IV_LENGTH = 16;
    
    /**
     * Get encryption key using PBKDF2 derivation
     * 
     * Derives 32-byte key from ILIAS client ID and password salt.
     * Ensures keys are unique per installation but consistent across requests.
     * 
     * @return string 32-byte binary key
     */
    private static function getEncryptionKey(): string
    {
        // Use ILIAS client ID and a fixed salt to derive encryption key
        // This ensures keys are unique per installation but consistent across requests
        $clientId = CLIENT_ID ?? 'ilias';
        $salt = self::getSalt();
        
        // Derive a 32-byte key using hash_pbkdf2
        return hash_pbkdf2('sha256', $clientId, $salt, 10000, 32, true);
    }
    
    /**
     * Get salt for key derivation
     * @return string
     */
    private static function getSalt(): string
    {
        // Try to use ILIAS secret from ilias.ini.php
        global $ilClientIniFile;
        
        if (isset($ilClientIniFile) && $ilClientIniFile !== null) {
            $passwordSalt = $ilClientIniFile->readVariable('auth', 'password_salt');
            if (!empty($passwordSalt)) {
                return $passwordSalt;
            }
        }
        
        // Fallback: use a fixed salt (less secure but better than nothing)
        // In production, this should be replaced with installation-specific salt
        return 'ilias_mdquiz_encryption_salt_2026';
    }
    
    /**
     * Encrypt a value
     * @param string $value Plain text value to encrypt
     * @return string Base64-encoded encrypted value with IV prepended
     */
    public static function encrypt(string $value): string
    {
        if (empty($value)) {
            return '';
        }
        
        $key = self::getEncryptionKey();
        
        // Generate random IV for each encryption
        $iv = random_bytes(self::IV_LENGTH);
        
        // Encrypt the value
        $encrypted = openssl_encrypt(
            $value,
            self::ENCRYPTION_METHOD,
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );
        
        if ($encrypted === false) {
            throw new ilMarkdownLernmodulException('Encryption failed');
        }
        
        // Prepend IV to encrypted data and encode as base64
        // Format: base64(iv + encrypted_data)
        return base64_encode($iv . $encrypted);
    }
    
    /**
     * Decrypt a value
     * @param string $encryptedValue Base64-encoded encrypted value with IV
     * @return string Decrypted plain text value
     */
    public static function decrypt(string $encryptedValue): string
    {
        if (empty($encryptedValue)) {
            return '';
        }
        
        // Decode from base64
        $data = base64_decode($encryptedValue, true);
        
        if ($data === false) {
            // Not encrypted or corrupted, return as is
            return $encryptedValue;
        }
        
        // Check if data is long enough to contain IV
        if (strlen($data) < self::IV_LENGTH) {
            // Too short to be encrypted, return as is
            return $encryptedValue;
        }
        
        $key = self::getEncryptionKey();
        
        // Extract IV from beginning of data
        $iv = substr($data, 0, self::IV_LENGTH);
        $encrypted = substr($data, self::IV_LENGTH);
        
        // Decrypt the value
        $decrypted = openssl_decrypt(
            $encrypted,
            self::ENCRYPTION_METHOD,
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );
        
        if ($decrypted === false) {
            // Decryption failed, might be old unencrypted data
            // Return original value for backward compatibility
            return $encryptedValue;
        }
        
        return $decrypted;
    }
    
    /**
     * Check if a value is encrypted
     * @param string $value Value to check
     * @return bool True if value appears to be encrypted
     */
    public static function isEncrypted(string $value): bool
    {
        if (empty($value)) {
            return false;
        }
        
        // Check if it's valid base64
        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            return false;
        }
        
        // Check if it's long enough to contain IV + some data
        if (strlen($decoded) <= self::IV_LENGTH) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Migrate existing plain text API keys to encrypted format
     * Should be called after plugin update
     * @return void
     */
    public static function migrateApiKeys(): void
    {
        ilMarkdownLernmodulConfig::load();
        
        $keysToMigrate = [
            'gwdg_api_key',
            'google_api_key',
            'openai_api_key'
        ];
        
        $migrated = false;
        
        foreach ($keysToMigrate as $keyName) {
            $value = ilMarkdownLernmodulConfig::get($keyName);
            
            if (!empty($value) && !self::isEncrypted($value)) {
                // Key exists and is not encrypted, encrypt it
                $encrypted = self::encrypt($value);
                ilMarkdownLernmodulConfig::set($keyName, $encrypted);
                $migrated = true;
            }
        }
        
        if ($migrated) {
            ilMarkdownLernmodulConfig::save();
        }
    }
}
