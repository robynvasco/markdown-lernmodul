<?php
declare(strict_types=1);

/**
 * MarkdownLernmodul Repository Object
 * 
 * This class represents a learning module stored in the ILIAS repository.
 * Learning modules can be generated via AI from documents or manually created.
 * Uses linear page-based navigation for educational content.
 * 
 * @author  Robyn Vasco
 * @version 0.1.0
 */
class ilObjMarkdownLernmodul extends ilObjectPlugin
{
    /** @var bool Whether the learning module is online and visible to users */
    protected bool $online = false;
    
    /** @var string Last used prompt for AI generation (for convenience) */
    protected string $last_prompt = "";
    
    /** @var string Last used context text for AI generation */
    protected string $last_context = "";
    
    /** @var int Last used file reference ID for AI generation */
    protected int $last_file_ref_id = 0;

    /**
     * Initialize the object type
     * Sets the ILIAS object type identifier to "xmdl"
     */
    protected function initType(): void
    {
        $this->setType("xmdl");
    }

    // ========================================
    // GETTERS AND SETTERS
    // ========================================

    /**
     * Set online status
     * @param bool $online True = online and visible, False = offline (only admins can see)
     */
    public function setOnline(bool $online): void
    {
        $this->online = $online;
    }

    /**
     * Get online status
     * @return bool True if learning module is online and visible to users
     */
    public function getOnline(): bool
    {
        return $this->online;
    }

    /**
     * Set last used prompt (for AI generation convenience)
     * @param string $prompt The prompt text used for module generation
     */
    public function setLastPrompt(string $prompt): void
    {
        $this->last_prompt = $prompt;
    }

    /**
     * Get last used prompt
     * @return string The last prompt text
     */
    public function getLastPrompt(): string
    {
        return $this->last_prompt;
    }

    /**
     * Set last used difficulty level
     * @param string $difficulty One of: easy, medium, hard
     */
    public function setLastDifficulty(string $difficulty): void
    {
        $this->last_difficulty = $difficulty;
    }

    /**
     * Get last used difficulty level
     * @return string The difficulty level
     */
    /**
     * Set last used context text
     * @param string $context Additional context for AI generation
     */
    public function setLastContext(string $context): void
    {
        $this->last_context = $context;
    }

    /**
     * Get last used context text
     * @return string The context text
     */
    public function getLastContext(): string
    {
        return $this->last_context;
    }

    /**
     * Set last used file reference ID
     * @param int $ref_id ILIAS file object reference ID
     */
    public function setLastFileRefId(int $ref_id): void
    {
        $this->last_file_ref_id = $ref_id;
    }

    /**
     * Get last used file reference ID
     * @return int The file reference ID (0 if none)
     */
    public function getLastFileRefId(): int
    {
        return $this->last_file_ref_id;
    }

    // ========================================
    // DATABASE OPERATIONS
    // ========================================

    /**
     * Load object data from database
     * Called automatically by ILIAS when object is read
     * 
     * SECURITY: Uses explicit type casting for SQL injection prevention
     */
    public function doRead(): void
    {
        global $DIC;
        
        // Check if columns exist (backwards compatibility during migration)
        $has_last_prompt = $DIC->database()->tableColumnExists('rep_robj_xmdl_data', 'last_prompt');
        $has_context = $DIC->database()->tableColumnExists('rep_robj_xmdl_data', 'last_context');
        $has_file_ref_id = $DIC->database()->tableColumnExists('rep_robj_xmdl_data', 'last_file_ref_id');
        
        $select_fields = "is_online";
        if ($has_last_prompt) $select_fields .= ", last_prompt";
        if ($has_context) $select_fields .= ", last_context";
        if ($has_file_ref_id) $select_fields .= ", last_file_ref_id";
        
        // SECURITY: Explicit integer type casting for ID parameter
        $res = $DIC->database()->query("SELECT {$select_fields} FROM rep_robj_xmdl_data WHERE id = " . 
            $DIC->database()->quote((int)$this->getId(), "integer"));
        while ($row = $DIC->database()->fetchAssoc($res)) {
            $this->online = (bool) ($row["is_online"] ?? 0);
            $this->last_prompt = $has_last_prompt ? (string) ($row["last_prompt"] ?? '') : '';
            $this->last_context = $has_context ? (string) ($row["last_context"] ?? '') : '';
            $this->last_file_ref_id = $has_file_ref_id ? (int) ($row["last_file_ref_id"] ?? 0) : 0;
        }
    }

    /**
     * Update object data in database
     * Called automatically by ILIAS when update() is called
     * 
     * Uses REPLACE to insert or update data atomically
     * SECURITY: Uses explicit type casting for SQL injection prevention
     */
    public function doUpdate(): void
    {
        global $DIC;
        
        // Check if columns exist (backwards compatibility during migration)
        $has_last_prompt = $DIC->database()->tableColumnExists('rep_robj_xmdl_data', 'last_prompt');
        $has_context = $DIC->database()->tableColumnExists('rep_robj_xmdl_data', 'last_context');
        $has_file_ref_id = $DIC->database()->tableColumnExists('rep_robj_xmdl_data', 'last_file_ref_id');
        
        $fields = ["is_online" => ["integer", (int)$this->online]];
        if ($has_last_prompt) $fields["last_prompt"] = ["text", $this->last_prompt];
        if ($has_context) $fields["last_context"] = ["text", $this->last_context];
        if ($has_file_ref_id) $fields["last_file_ref_id"] = ["integer", $this->last_file_ref_id];
        
        // SECURITY: Explicit integer type casting for ID parameter
        $DIC->database()->replace(
            "rep_robj_xmdl_data",
            ["id" => ["integer", (int)$this->getId()]],
            $fields
        );
    }

    /**
     * Delete object data from database
     * Called automatically by ILIAS when object is deleted
     * 
     * SECURITY: Uses explicit type casting for SQL injection prevention
     */
    public function doDelete(): void
    {
        global $DIC;
        
        $obj_id = (int)$this->getId();
        
        // Delete pages
        if ($DIC->database()->tableExists('rep_robj_xmdl_pages')) {
            $DIC->database()->manipulate("DELETE FROM rep_robj_xmdl_pages WHERE module_id = " . 
                $DIC->database()->quote($obj_id, "integer"));
        }
        
        // Delete user progress
        if ($DIC->database()->tableExists('rep_robj_xmdl_progress')) {
            $DIC->database()->manipulate(
                "DELETE FROM rep_robj_xmdl_progress WHERE module_id = " .
                $DIC->database()->quote($obj_id, "integer")
            );
        }
        
        // Delete main deck data
        $DIC->database()->manipulate("DELETE FROM rep_robj_xmdl_data WHERE id = " . 
            $DIC->database()->quote($obj_id, "integer"));
    }
}