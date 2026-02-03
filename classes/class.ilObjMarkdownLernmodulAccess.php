<?php
declare(strict_types=1);

/**
 * Access Control for MarkdownLernmodul Plugin
 * 
 * Handles access permissions and online/offline visibility for flashcard decks.
 * 
 * Key Features:
 * - Enforces online/offline status visibility
 * - Admins with write permission can always access
 * - Regular users can only access online quizzes
 * 
 * @author  Your Name
 * @version 1.0
 */
class ilObjMarkdownLernmodulAccess extends ilObjectPluginAccess
{
    /**
     * Check if goto link target is accessible
     * 
     * @param string $target Target string format: "xmdl_<ref_id>"
     * @return bool True if user can access the target
     */
    public static function _checkGoto(string $target): bool
    {
        global $DIC;
        
        $params = explode("_", $target);
        $ref_id = (int) end($params);

        if ($ref_id <= 0) {
            return false;
        }

        return $DIC->access()->checkAccess("read", "", $ref_id);
    }

    /**
     * Check access permissions for a specific command
     * 
     * This is the main access control method called by ILIAS.
     * Implements online/offline visibility:
     * - Users with 'write' permission (admins) can always access
     * - For 'read'/'visible' permissions, checks online status
     * - Offline quizzes are hidden from regular users
     * 
     * @param string $cmd Command being executed
     * @param string $permission Permission to check (read, write, visible, etc.)
     * @param int $ref_id Reference ID of the object
     * @param int $obj_id Object ID
     * @param int|null $user_id User ID (current user if null)
     * @return bool True if access is granted
     */
    public function _checkAccess(string $cmd, string $permission, int $ref_id, int $obj_id, ?int $user_id = null): bool
    {
        global $DIC;
        $user_id = $user_id ?? $DIC->user()->getId();
        
        // Check if user has write permission - admins can always access
        if ($DIC->access()->checkAccessOfUser($user_id, 'write', '', $ref_id)) {
            return true;
        }
        
        // For read/visible permissions, check if object is online
        if (in_array($permission, ['read', 'visible'])) {
            if (self::_isOffline($obj_id)) {
                // Object is offline, deny access to non-admins
                return false;
            }
        }
        
        return (bool) $DIC->access()->checkAccessOfUser($user_id, $permission, $cmd, $ref_id, "xmdl", $obj_id);
    }

    /**
     * Check if a flashcard deck is offline
     * 
     * Used by ILIAS to determine visibility in lists and access control.
     * 
     * @param int $obj_id Object ID of the quiz
     * @return bool True if offline (not visible to regular users), False if online
     */
    public static function _isOffline(int $obj_id): bool
    {
        global $DIC;
        $db = $DIC->database();
        
        $query = "SELECT is_online FROM rep_robj_xmdl_data WHERE id = " . $db->quote($obj_id, "integer");
        $result = $db->query($query);
        
        if ($row = $db->fetchAssoc($result)) {
            // is_online = 1 means online, so offline = !is_online
            return !(bool)$row['is_online'];
        }
        
        // Default to offline if not found
        return true;
    }
}