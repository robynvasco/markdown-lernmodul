<?php
declare(strict_types=1);

namespace security;

/**
 * Certificate Pinning for HTTPS API Calls
 * 
 * Verifies SSL certificate fingerprints to prevent MITM attacks.
 * Currently disabled by default (requires manual certificate verification).
 * 
 * To enable:
 * 1. Get certificate fingerprint: openssl s_client -connect api.openai.com:443 | openssl x509 -fingerprint -sha256
 * 2. Add to PINNED_CERTIFICATES array
 * 3. Update periodically (certificates expire)
 * 
 * @package security
 */
class ilMarkdownLernmodulCertificatePinner
{
    // Known good certificate fingerprints (SHA256) - UPDATE THESE PERIODICALLY
    private const PINNED_CERTIFICATES = [
        'api.openai.com' => [
            // OpenAI certificate fingerprints (multiple for rotation)
            // These should be updated periodically - use: openssl s_client -connect api.openai.com:443 | openssl x509 -fingerprint -sha256
            'primary' => null, // Disabled for now - requires manual certificate verification
            'backup' => null
        ],
        'generativelanguage.googleapis.com' => [
            // Google AI certificate fingerprints
            'primary' => null, // Disabled for now
            'backup' => null
        ],
        'chat-ai.academiccloud.de' => [
            // GWDG certificate fingerprints
            'primary' => null, // Disabled for now
            'backup' => null
        ]
    ];
    
    /**
     * Verify SSL certificate fingerprint
     * 
     * Disabled by default. Enable by adding fingerprints to PINNED_CERTIFICATES.
     * 
     * @param string $host Hostname (e.g., 'api.openai.com')
     * @param resource $ch cURL handle with CURLOPT_CERTINFO enabled
     * @return bool True if certificate valid
     * @throws \Exception If fingerprint mismatch (potential MITM)
     */
    public static function verifyCertificate(string $host, $ch): bool
    {
        // Certificate pinning disabled by default
        // To enable: add certificate fingerprints to PINNED_CERTIFICATES array
        
        if (!isset(self::PINNED_CERTIFICATES[$host])) {
            // Host not in pinning list - use standard SSL verification
            return true;
        }
        
        $pins = self::PINNED_CERTIFICATES[$host];
        
        // If no pins configured, skip pinning (but still use standard SSL)
        if (empty(array_filter($pins))) {
            return true;
        }
        
        // Get certificate info
        $certInfo = curl_getinfo($ch, CURLINFO_CERTINFO);
        
        if (empty($certInfo)) {
            throw new \Exception("Certificate verification failed: no certificate info available for {$host}");
        }
        
        // Extract certificate fingerprint
        $fingerprint = self::extractFingerprint($certInfo);
        
        if ($fingerprint === null) {
            throw new \Exception("Certificate verification failed: could not extract fingerprint for {$host}");
        }
        
        // Check if fingerprint matches any pinned certificate
        foreach ($pins as $pinnedFingerprint) {
            if ($pinnedFingerprint !== null && hash_equals($pinnedFingerprint, $fingerprint)) {
                return true;
            }
        }
        
        throw new \Exception(
            "Certificate pinning failed for {$host}: fingerprint mismatch. " .
            "This may indicate a man-in-the-middle attack. " .
            "Expected one of: " . implode(', ', array_filter($pins)) . " " .
            "Got: {$fingerprint}"
        );
    }
    
    /**
     * Extract SHA256 fingerprint from certificate info
     */
    private static function extractFingerprint(array $certInfo): ?string
    {
        // cURL certinfo structure varies by version
        // Try to extract fingerprint from various possible locations
        
        if (isset($certInfo[0]['Cert']) && is_string($certInfo[0]['Cert'])) {
            // Parse PEM certificate
            $cert = openssl_x509_read($certInfo[0]['Cert']);
            if ($cert !== false) {
                $fingerprint = openssl_x509_fingerprint($cert, 'sha256');
                openssl_x509_free($cert);
                return $fingerprint;
            }
        }
        
        return null;
    }
    
    /**
     * Configure cURL for certificate pinning
     * 
     * Sets: SSL_VERIFYPEER, SSL_VERIFYHOST, CERTINFO, minimum TLS 1.2
     * 
     * @param resource $ch cURL handle
     * @param string $host Hostname for pinning
     */
    public static function configureCurl($ch, string $host): void
    {
        // Enable SSL verification
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        
        // Enable certificate info retrieval
        curl_setopt($ch, CURLOPT_CERTINFO, true);
        
        // Set minimum TLS version
        curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
    }
    
    /**
     * Get current certificate fingerprint (admin tool)
     * 
     * Used to update PINNED_CERTIFICATES array when certificates rotate.
     * 
     * @param string $host Hostname
     * @param int $port HTTPS port (default 443)
     * @return string|null SHA256 fingerprint or null on error
     */
    public static function getCurrentFingerprint(string $host, int $port = 443): ?string
    {
        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => true,
                'verify_peer_name' => true
            ]
        ]);
        
        $client = @stream_socket_client(
            "ssl://{$host}:{$port}",
            $errno,
            $errstr,
            30,
            STREAM_CLIENT_CONNECT,
            $context
        );
        
        if ($client === false) {
            return null;
        }
        
        $params = stream_context_get_params($client);
        fclose($client);
        
        if (isset($params['options']['ssl']['peer_certificate'])) {
            $cert = $params['options']['ssl']['peer_certificate'];
            return openssl_x509_fingerprint($cert, 'sha256');
        }
        
        return null;
    }
}
