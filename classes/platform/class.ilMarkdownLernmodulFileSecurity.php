<?php
declare(strict_types=1);
/**
 * File security helper for MarkdownLernmodul plugin
 * Implements file size limits, magic byte validation, ZIP bomb protection
 */

namespace platform;

/**
 * File Security Service for MarkdownLernmodul Plugin
 * 
 * Provides security validations for file uploads and processing:
 * - File size limits (10MB default)
 * - Magic byte validation (file signature checks)
 * - ZIP bomb protection (compression ratio checks)
 * - Processing timeouts
 * 
 * @package platform
 */
class ilMarkdownLernmodulFileSecurity
{
    // Maximum file size: 10MB
    private const MAX_FILE_SIZE = 10 * 1024 * 1024;
    
    // Maximum uncompressed size for ZIP files: 50MB
    private const MAX_UNCOMPRESSED_SIZE = 50 * 1024 * 1024;
    
    // Maximum compression ratio (uncompressed/compressed)
    // If ratio > 10, it's likely a ZIP bomb
    private const MAX_COMPRESSION_RATIO = 10;
    
    // Processing timeout in seconds
    private const PROCESSING_TIMEOUT = 30;
    
    // File type magic bytes signatures
    private const MAGIC_BYTES = [
        'pdf' => ['25504446'],  // %PDF
        'zip' => ['504B0304', '504B0506'],  // PK.. (ZIP/DOCX/PPTX)
        'txt' => null,  // Text files don't have magic bytes
    ];
    
    /**
     * Validate file size against limit
     * 
     * @param string $content File content
     * @param int|null $custom_limit Custom size limit in bytes (default: 10MB)
     * @throws ilMarkdownLernmodulException If file exceeds limit
     */
    public static function validateFileSize(string $content, ?int $custom_limit = null): void
    {
        $size = strlen($content);
        $limit = $custom_limit ?? self::MAX_FILE_SIZE;
        
        if ($size > $limit) {
            $size_mb = round($size / 1024 / 1024, 2);
            $limit_mb = round($limit / 1024 / 1024, 2);
            throw new ilMarkdownLernmodulException(
                "File size ({$size_mb}MB) exceeds maximum allowed size ({$limit_mb}MB)"
            );
        }
    }
    
    /**
     * Validate file magic bytes (signature)
     * 
     * Checks first 4 bytes against known file type signatures.
     * Protects against file extension spoofing.
     * 
     * @param string $content File content
     * @param string $expected_type Expected file type (pdf, zip, txt)
     * @return bool True if valid or no check needed
     */
    public static function validateMagicBytes(string $content, string $expected_type): bool
    {
        // Text files and unknown types pass through
        if (!isset(self::MAGIC_BYTES[$expected_type])) {
            return true;
        }
        
        $signatures = self::MAGIC_BYTES[$expected_type];
        if ($signatures === null) {
            return true;
        }
        
        // Get first 4 bytes as hex
        $header = strtoupper(bin2hex(substr($content, 0, 4)));
        
        // Check if header matches any valid signature
        foreach ($signatures as $signature) {
            if (strpos($header, $signature) === 0) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check for ZIP bomb attack
     * 
     * Validates:
     * - Uncompressed size < 50MB
     * - Compression ratio < 10:1
     * 
     * @param string $zip_path Path to temporary ZIP file
     * @throws ilMarkdownLernmodulException If ZIP bomb detected
     */
    public static function validateZipSafety(string $zip_path): void
    {
        $zip = new \ZipArchive();
        
        if ($zip->open($zip_path) !== true) {
            throw new ilMarkdownLernmodulException("Failed to open ZIP file for validation");
        }
        
        $compressed_size = filesize($zip_path);
        $uncompressed_size = 0;
        
        // Calculate total uncompressed size
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                $zip->close();
                throw new ilMarkdownLernmodulException("Failed to read ZIP file statistics");
            }
            
            $uncompressed_size += $stat['size'];
            
            // Check if uncompressed size exceeds limit
            if ($uncompressed_size > self::MAX_UNCOMPRESSED_SIZE) {
                $zip->close();
                throw new ilMarkdownLernmodulException(
                    "ZIP file uncompressed size exceeds maximum allowed (" . 
                    round(self::MAX_UNCOMPRESSED_SIZE / 1024 / 1024) . "MB)"
                );
            }
        }
        
        // Check compression ratio
        if ($compressed_size > 0) {
            $ratio = $uncompressed_size / $compressed_size;
            
            if ($ratio > self::MAX_COMPRESSION_RATIO) {
                $zip->close();
                throw new ilMarkdownLernmodulException(
                    "ZIP file compression ratio ({$ratio}) indicates potential ZIP bomb attack"
                );
            }
        }
        
        $zip->close();
    }
    
    /**
     * Set processing timeout
     * @param int|null $timeout Timeout in seconds, null for default
     */
    public static function setProcessingTimeout(?int $timeout = null): void
    {
        $timeout_value = $timeout ?? self::PROCESSING_TIMEOUT;
        set_time_limit($timeout_value);
    }
    
    /**
     * Check if ClamAV antivirus is available
     * @return bool
     */
    public static function isAntivirusAvailable(): bool
    {
        // Check if clamdscan or clamscan commands exist
        $clamdscan = shell_exec('which clamdscan 2>/dev/null');
        $clamscan = shell_exec('which clamscan 2>/dev/null');
        
        return !empty($clamdscan) || !empty($clamscan);
    }
    
    /**
     * Scan file with ClamAV if available
     * @param string $file_path Path to file
     * @return array ['clean' => bool, 'result' => string]
     */
    public static function scanFileWithAntivirus(string $file_path): array
    {
        if (!self::isAntivirusAvailable()) {
            return ['clean' => true, 'result' => 'Antivirus not available'];
        }
        
        // Try clamdscan first (faster, uses daemon)
        $clamdscan = shell_exec('which clamdscan 2>/dev/null');
        $clamscan = shell_exec('which clamscan 2>/dev/null');
        
        $command = null;
        if (!empty($clamdscan)) {
            $command = 'clamdscan --no-summary ' . escapeshellarg($file_path) . ' 2>&1';
        } elseif (!empty($clamscan)) {
            $command = 'clamscan --no-summary ' . escapeshellarg($file_path) . ' 2>&1';
        }
        
        if ($command === null) {
            return ['clean' => true, 'result' => 'Antivirus not available'];
        }
        
        $output = shell_exec($command);
        $clean = (strpos($output, 'OK') !== false) && (strpos($output, 'FOUND') === false);
        
        return [
            'clean' => $clean,
            'result' => trim($output)
        ];
    }
    
    /**
     * Validate and secure file for processing
     * @param string $content File content
     * @param string $file_type File type (pdf, zip, txt, etc.)
     * @param string|null $temp_file_path Optional temp file path for virus scanning
     * @throws ilMarkdownLernmodulException
     */
    public static function validateFile(string $content, string $file_type, ?string $temp_file_path = null): void
    {
        // Set processing timeout
        self::setProcessingTimeout();
        
        // Check file size
        self::validateFileSize($content);
        
        // Validate magic bytes
        $magic_type = $file_type;
        if (in_array($file_type, ['docx', 'pptx'])) {
            $magic_type = 'zip';
        }
        
        if (!self::validateMagicBytes($content, $magic_type)) {
            throw new ilMarkdownLernmodulException(
                "File signature does not match expected type: {$file_type}"
            );
        }
        
        // For ZIP-based files (DOCX, PPTX), check for ZIP bombs
        if ($temp_file_path && in_array($file_type, ['pptx', 'docx', 'zip'])) {
            self::validateZipSafety($temp_file_path);
        }
        
        // Virus scan if available and temp file provided
        if ($temp_file_path && self::isAntivirusAvailable()) {
            $scan_result = self::scanFileWithAntivirus($temp_file_path);
            
            if (!$scan_result['clean']) {
                throw new ilMarkdownLernmodulException(
                    "File failed virus scan: " . $scan_result['result']
                );
            }
        }
    }
    
    /**
     * Get maximum allowed file size
     * @return int Size in bytes
     */
    public static function getMaxFileSize(): int
    {
        return self::MAX_FILE_SIZE;
    }
    
    /**
     * Get maximum processing timeout
     * @return int Timeout in seconds
     */
    public static function getProcessingTimeout(): int
    {
        return self::PROCESSING_TIMEOUT;
    }
}
