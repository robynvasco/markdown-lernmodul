<?php
declare(strict_types=1);

namespace security;

use platform\ilMarkdownLernmodulConfig;

require_once __DIR__ . '/../platform/class.ilMarkdownLernmodulConfig.php';

/**
 * HMAC Request Signing for API Audit Trail
 * 
 * Adds HMAC-SHA256 signatures to API requests:
 * - Verifies request authenticity
 * - Prevents tampering
 * - Includes timestamp (5-minute expiry)
 * 
 * Format: base64(timestamp:signature)
 * Signature: HMAC-SHA256(timestamp:service:canonical_payload, API_key)
 * 
 * @package security
 */
class ilMarkdownLernmodulRequestSigner
{
    /**
     * Generate HMAC signature for API request
     * 
     * @param string $service Service name (for audit trail)
     * @param array $payload Request payload
     * @param string $apiKey API key (used as signing key)
     * @return string Base64-encoded signature (timestamp:signature)
     */
    public static function signRequest(string $service, array $payload, string $apiKey): string
    {
        // Use API key as signing key
        $signingKey = hash('sha256', $apiKey, true);
        
        // Create canonical string from payload
        $canonicalString = self::createCanonicalString($payload);
        
        // Add timestamp to prevent replay attacks
        $timestamp = time();
        $dataToSign = $timestamp . ':' . $service . ':' . $canonicalString;
        
        // Generate HMAC
        $signature = hash_hmac('sha256', $dataToSign, $signingKey);
        
        return base64_encode($timestamp . ':' . $signature);
    }
    
    /**
     * Verify HMAC signature
     * 
     * Checks:
     * - Signature format valid
     * - Timestamp within 5 minutes
     * - HMAC matches expected value
     * 
     * @param string $service Service name
     * @param array $payload Request payload
     * @param string $receivedSignature Signature from request header
     * @param string $apiKey API key
     * @return bool True if signature valid
     */
    public static function verifySignature(string $service, array $payload, string $receivedSignature, string $apiKey): bool
    {
        try {
            // Decode signature
            $decoded = base64_decode($receivedSignature);
            list($timestamp, $signature) = explode(':', $decoded, 2);
            
            // Check timestamp is recent (within 5 minutes)
            if (time() - (int)$timestamp > 300) {
                return false; // Signature expired
            }
            
            // Recreate signature
            $signingKey = hash('sha256', $apiKey, true);
            $canonicalString = self::createCanonicalString($payload);
            $dataToSign = $timestamp . ':' . $service . ':' . $canonicalString;
            $expectedSignature = hash_hmac('sha256', $dataToSign, $signingKey);
            
            // Compare signatures (timing-safe)
            return hash_equals($expectedSignature, $signature);
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Create canonical string representation of payload
     */
    private static function createCanonicalString(array $payload): string
    {
        // Sort keys for consistent ordering
        ksort($payload);
        
        $parts = [];
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value);
            }
            $parts[] = $key . '=' . $value;
        }
        
        return implode('&', $parts);
    }
    
    /**
     * Create request metadata for audit logging
     * 
     * @param string $service Service name
     * @return array Metadata: request_id, timestamp, service, client_ip, user_agent
     */
    public static function createRequestMetadata(string $service): array
    {
        return [
            'request_id' => self::generateRequestId(),
            'timestamp' => time(),
            'service' => $service,
            'client_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ];
    }
    
    /**
     * Generate unique request ID
     */
    private static function generateRequestId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
