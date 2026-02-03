<?php
declare(strict_types=1);

use ILIAS\UI\Factory;
use ILIAS\UI\Renderer;
use platform\ilMarkdownLernmodulConfig;
use platform\ilMarkdownLernmodulException;
use platform\ilMarkdownLernmodulFileSecurity;
use platform\ilMarkdownLernmodulXSSProtection;
use platform\ilMarkdownLernmodulRateLimiter;
use ai\ilMarkdownLernmodulGoogleAI;
use ai\ilMarkdownLernmodulGWDG;
use ai\ilMarkdownLernmodulOpenAI;

require_once __DIR__ . '/platform/class.ilMarkdownLernmodulConfig.php';
require_once __DIR__ . '/platform/class.ilMarkdownLernmodulException.php';
require_once __DIR__ . '/platform/class.ilMarkdownLernmodulFileSecurity.php';
require_once __DIR__ . '/platform/class.ilMarkdownLernmodulXSSProtection.php';
require_once __DIR__ . '/platform/class.ilMarkdownLernmodulRateLimiter.php';
require_once __DIR__ . '/ai/class.ilMarkdownLernmodulLLM.php';
require_once __DIR__ . '/ai/class.ilMarkdownLernmodulGWDG.php';
require_once __DIR__ . '/ai/class.ilMarkdownLernmodulGoogleAI.php';
require_once __DIR__ . '/ai/class.ilMarkdownLernmodulOpenAI.php';

/**
 * MarkdownLernmodul GUI Controller
 * 
 * Main controller class handling all user interactions with learning modules.
 * 
 * Key Features:
 * - View learning module pages with markdown rendering
 * - Edit module settings (title, online status, content)
 * - Generate learning modules via AI (OpenAI, Google Gemini, GWDG)
 * - File upload and content extraction
 * - Rate limiting and security controls
 * 
 * Security Measures:
 * - Input validation on all user data
 * - XSS protection via HTML escaping
 * - Rate limiting (20 API calls/hour, 20 files/hour, 5s cooldown)
 * - File type whitelist (txt, pdf, doc, docx, ppt, pptx)
 * - SQL injection prevention via type casting
 * 
 * @ilCtrl_isCalledBy ilObjMarkdownLernmodulGUI: ilRepositoryGUI, ilObjPluginDispatchGUI
 * @ilCtrl_Calls ilObjMarkdownLernmodulGUI: ilPermissionGUI, ilInfoScreenGUI, ilCommonActionDispatcherGUI
 * 
 * @author  Your Name
 * @version 1.0
 */
class ilObjMarkdownLernmodulGUI extends ilObjectPluginGUI
{
    /** @var Factory ILIAS UI factory for creating UI components */
    private Factory $factory;
    
    /** @var Renderer ILIAS UI renderer for rendering components */
    private Renderer $renderer;
    
    /** @var \ILIAS\Refinery\Factory Data refinement factory for transformations */
    protected \ILIAS\Refinery\Factory $refinery;
    
    /** @var ilLanguage Language service */
    protected ilLanguage $lng;

    /**
     * Command to execute after object creation
     * Redirects to settings to configure the new quiz
     * 
     * @return string Command name
     */
    public function getAfterCreationCmd(): string
    {
        return "settings";
    }

    /**
     * Initialize dependencies after constructor
     * Sets up UI factory, renderer, and language service
     */
    protected function afterConstructor(): void
    {
        global $DIC;
        $this->factory = $DIC->ui()->factory();
        $this->renderer = $DIC->ui()->renderer();
        $this->refinery = $DIC->refinery();
        $this->lng = $DIC->language();
    }

    /**
     * Get the object type identifier
     * 
     * @return string Type identifier "xmdl"
     */
    public function getType(): string
    {
        return "xmdl";
    }

    /**
     * Get the default command
     * Command executed when user opens the quiz without specifying an action
     * 
     * @return string Command name "view"
     */
    public function getStandardCmd(): string
    {
        return "view";
    }

    /**
     * Command dispatcher
     * Routes commands to appropriate handler methods
     * 
     * @param string $cmd Command to execute
     */
    public function performCommand(string $cmd): void
    {
        $this->checkPermission("read");
        $this->setTitleAndDescription();
        $this->{$cmd}();
    }

    /**
     * Initialize tab structure
     * Creates navigation tabs based on user permissions
     */
    protected function setTabs(): void
    {
        global $DIC;

        $this->tabs->addTab("view", "Study", $DIC->ctrl()->getLinkTarget($this, "view"));
        $this->tabs->addTab("flashcards", "Manage Lernmodul", $DIC->ctrl()->getLinkTarget($this, "flashcards"));

        if ($this->checkPermissionBool("write")) {
            $this->tabs->addTab("settings", "Settings", $DIC->ctrl()->getLinkTarget($this, "settings"));
            
            // Only show AI Generate tab if AI is enabled in admin config
            ilMarkdownLernmodulConfig::load();
            if (ilMarkdownLernmodulConfig::get('ai_enabled', true)) {
                $this->tabs->addTab("generate", "AI Generate", $DIC->ctrl()->getLinkTarget($this, "generate"));
            }
        }

        if ($this->checkPermissionBool("edit_permission")) {
            $this->tabs->addTab(
                "perm_settings",
                $this->lng->txt("perm_settings"),
                $DIC->ctrl()->getLinkTargetByClass([get_class($this), "ilPermissionGUI"], "perm")
            );
        }
    }

    /**
     * Display view with learning module pages
     */
    public function view(): void
    {
        global $DIC;
        $this->tabs->activateTab("view");
        
        $db = $DIC->database();
        $obj_id = $this->object->getId();
        
        // Get current page number from URL
        $current_page = (int)($_GET['page'] ?? 1);
        if ($current_page < 1) $current_page = 1;
        
        // Check if any pages exist
        $count_query = "SELECT COUNT(*) as total FROM rep_robj_xmdl_pages WHERE module_id = " . $db->quote($obj_id, 'integer');
        $count_result = $db->query($count_query);
        $count_row = $db->fetchAssoc($count_result);
        $total_pages = (int)$count_row['total'];
        
        if ($total_pages == 0) {
            $html_output = "
                <div class='alert alert-info'>
                    <h4>No Pages Yet</h4>
                    <p>This learning module doesn't have any pages yet.</p>";
            
            if ($this->checkPermissionBool("write")) {
                $html_output .= "
                    <p>
                        <strong>Get started:</strong><br>
                        • Go to <strong>Manage Pages</strong> to add pages manually<br>
                        • Or go to <strong>AI Generate</strong> to create content automatically
                    </p>";
            }
            
            $html_output .= "</div>";
        } else {
            // Ensure page number is within bounds
            if ($current_page > $total_pages) $current_page = $total_pages;
            
            // Fetch current page
            $offset = $current_page - 1;
            $query = "SELECT id, title, content, page_number FROM rep_robj_xmdl_pages 
                      WHERE module_id = " . $db->quote($obj_id, 'integer') . "
                      ORDER BY sort_order ASC LIMIT 1 OFFSET " . $offset;
            $result = $db->query($query);
            $page = $db->fetchAssoc($result);
            
            // Fetch all pages for sidebar navigation
            $all_pages_query = "SELECT id, title FROM rep_robj_xmdl_pages 
                               WHERE module_id = " . $db->quote($obj_id, 'integer') . "
                               ORDER BY sort_order ASC";
            $all_pages_result = $db->query($all_pages_query);
            $all_pages = [];
            while ($p = $db->fetchAssoc($all_pages_result)) {
                $all_pages[] = $p;
            }
            
            // Build navigation
            $prev_link = $current_page > 1 ? $DIC->ctrl()->getLinkTarget($this, "view") . "&page=" . ($current_page - 1) : "";
            $next_link = $current_page < $total_pages ? $DIC->ctrl()->getLinkTarget($this, "view") . "&page=" . ($current_page + 1) : "";
            
            $progress_percent = round(($current_page / $total_pages) * 100);
            
            $nav_html = "
            <style>
                /* Container */
                .lernmodul-wrapper {
                    background: transparent;
                    min-height: 70vh;
                    padding: 20px 0;
                }
                
                .lernmodul-main-layout {
                    display: flex;
                    gap: 30px;
                    max-width: 1200px;
                    margin: 0;
                    align-items: flex-start;
                    padding: 0 20px;
                }
                
                .lernmodul-content-column {
                    flex: 1;
                    min-width: 0;
                    max-width: 900px;
                }
                
                .lernmodul-sidebar-column {
                    width: 280px;
                    flex-shrink: 0;
                    transition: all 0.3s ease;
                }
                
                .lernmodul-sidebar-column.collapsed {
                    width: 50px;
                }
                
                .lernmodul-container {
                    background: #ffffff;
                    border-radius: 16px;
                    box-shadow: 0 2px 20px rgba(0, 0, 0, 0.08);
                    padding: 40px;
                    border: 1px solid rgba(255, 255, 255, 0.8);
                }
                
                /* Sidebar */
                .lernmodul-sidebar {
                    background: #ffffff;
                    border-radius: 16px;
                    box-shadow: 0 2px 20px rgba(0, 0, 0, 0.08);
                    padding: 24px;
                    position: sticky;
                    top: 20px;
                    max-height: calc(100vh - 120px);
                    overflow-y: auto;
                    overflow-x: hidden;
                    border: 1px solid rgba(255, 255, 255, 0.8);
                    transition: all 0.3s ease;
                }
                
                .lernmodul-sidebar-column.collapsed .lernmodul-sidebar {
                    padding: 12px;
                }
                
                .lernmodul-sidebar-header {
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    margin-bottom: 16px;
                }
                
                .lernmodul-sidebar-title {
                    font-size: 14px;
                    font-weight: 700;
                    color: #6c757d;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                    white-space: nowrap;
                    overflow: hidden;
                    transition: opacity 0.3s ease;
                }
                
                .lernmodul-sidebar-column.collapsed .lernmodul-sidebar-title {
                    opacity: 0;
                    width: 0;
                }
                
                .lernmodul-toggle-btn {
                    width: 28px;
                    height: 28px;
                    border: none;
                    background: #f3f4f6;
                    border-radius: 6px;
                    cursor: pointer;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 14px;
                    color: #6c757d;
                    transition: all 0.2s;
                    flex-shrink: 0;
                }
                
                .lernmodul-toggle-btn:hover {
                    background: #e5e7eb;
                    color: #4c6586;
                }
                
                .lernmodul-sidebar-column.collapsed .lernmodul-toggle-btn {
                    transform: rotate(180deg);
                }
                
                .lernmodul-chapter-list {
                    list-style: none;
                    padding: 0;
                    margin: 0;
                }
                
                .lernmodul-chapter-item {
                    margin-bottom: 4px;
                }
                
                .lernmodul-chapter-link {
                    display: flex;
                    align-items: center;
                    padding: 10px 12px;
                    border-radius: 8px;
                    text-decoration: none;
                    color: #374151;
                    font-size: 14px;
                    transition: all 0.2s;
                    line-height: 1.4;
                }
                
                .lernmodul-chapter-link:hover {
                    background: #f3f4f6;
                    color: #4c6586;
                }
                
                .lernmodul-chapter-link.active {
                    background: linear-gradient(135deg, #4c6586 0%, #667eea 100%);
                    color: white;
                    font-weight: 600;
                }
                
                .lernmodul-chapter-number {
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    width: 24px;
                    height: 24px;
                    background: rgba(0, 0, 0, 0.05);
                    border-radius: 6px;
                    font-size: 12px;
                    font-weight: 600;
                    margin-right: 10px;
                    flex-shrink: 0;
                }
                
                .lernmodul-chapter-link.active .lernmodul-chapter-number {
                    background: rgba(255, 255, 255, 0.2);
                }
                
                .lernmodul-chapter-title {
                    flex: 1;
                    overflow: hidden;
                    text-overflow: ellipsis;
                    display: -webkit-box;
                    -webkit-line-clamp: 2;
                    -webkit-box-orient: vertical;
                    transition: opacity 0.3s ease;
                }
                
                .lernmodul-sidebar-column.collapsed .lernmodul-chapter-list {
                    display: none;
                }
                
                .lernmodul-collapsed-indicator {
                    display: none;
                    flex-direction: column;
                    align-items: center;
                    gap: 8px;
                    padding: 8px 0;
                }
                
                .lernmodul-sidebar-column.collapsed .lernmodul-collapsed-indicator {
                    display: flex;
                }
                
                .lernmodul-collapsed-dot {
                    width: 8px;
                    height: 8px;
                    border-radius: 50%;
                    background: #dee2e6;
                }
                
                .lernmodul-collapsed-dot.active {
                    background: linear-gradient(135deg, #4c6586 0%, #667eea 100%);
                    width: 10px;
                    height: 10px;
                }
                
                /* Header with Progress */
                .lernmodul-header {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    margin-bottom: 40px;
                    padding-bottom: 24px;
                    border-bottom: 1px solid #e9ecef;
                }
                
                .lernmodul-nav-controls {
                    display: flex;
                    align-items: center;
                    gap: 12px;
                }
                
                .lernmodul-nav-btn {
                    width: 44px;
                    height: 44px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    border-radius: 50%;
                    text-decoration: none;
                    font-size: 20px;
                    transition: all 0.2s ease;
                    border: 2px solid #dee2e6;
                }
                
                .lernmodul-nav-btn.enabled {
                    background: white;
                    color: #4c6586;
                    border-color: #4c6586;
                }
                
                .lernmodul-nav-btn.enabled:hover {
                    background: #4c6586;
                    color: white;
                    transform: scale(1.08);
                    box-shadow: 0 4px 12px rgba(76, 101, 134, 0.3);
                }
                
                .lernmodul-nav-btn.disabled {
                    background: #f8f9fa;
                    color: #adb5bd;
                    border-color: #dee2e6;
                    cursor: not-allowed;
                }
                
                .lernmodul-page-counter {
                    font-size: 15px;
                    color: #6c757d;
                    font-weight: 600;
                    min-width: 70px;
                    text-align: center;
                }
                
                .lernmodul-progress-container {
                    flex-grow: 1;
                    max-width: 220px;
                    margin-right: 24px;
                }
                
                .lernmodul-progress-bar {
                    width: 100%;
                    height: 6px;
                    background: #e9ecef;
                    border-radius: 3px;
                    overflow: hidden;
                }
                
                .lernmodul-progress-fill {
                    height: 100%;
                    background: linear-gradient(90deg, #4c6586 0%, #667eea 100%);
                    border-radius: 3px;
                    transition: width 0.4s ease;
                }
                
                .lernmodul-progress-text {
                    font-size: 12px;
                    color: #6c757d;
                    text-align: left;
                    margin-top: 6px;
                    font-weight: 500;
                }
                
                /* Content Area */
                .lernmodul-content {
                    line-height: 1.8;
                    color: #374151;
                }
                
                .lernmodul-content h3 {
                    font-size: 28px;
                    font-weight: 700;
                    color: #1f2937;
                    margin-bottom: 24px;
                    letter-spacing: -0.02em;
                }
                
                .lernmodul-content p {
                    margin-bottom: 16px;
                    font-size: 17px;
                }
                
                .lernmodul-content strong {
                    color: #1f2937;
                    font-weight: 600;
                }
                
                .lernmodul-content ul, .lernmodul-content ol {
                    margin-bottom: 16px;
                    padding-left: 24px;
                }
                
                .lernmodul-content li {
                    margin-bottom: 8px;
                }
                
                .lernmodul-content blockquote {
                    border-left: 4px solid #667eea;
                    padding-left: 20px;
                    margin: 24px 0;
                    color: #6b7280;
                    font-style: italic;
                    background: #f9fafb;
                    padding: 16px 20px;
                    border-radius: 0 8px 8px 0;
                }
                
                .lernmodul-content code {
                    background: #f3f4f6;
                    padding: 2px 8px;
                    border-radius: 6px;
                    font-size: 0.9em;
                    font-family: 'SF Mono', Monaco, 'Courier New', monospace;
                    color: #e11d48;
                }
                
                .lernmodul-content pre {
                    background: #1f2937;
                    color: #e5e7eb;
                    padding: 20px;
                    border-radius: 12px;
                    overflow-x: auto;
                    margin: 24px 0;
                }
                
                .lernmodul-content pre code {
                    background: none;
                    padding: 0;
                    color: inherit;
                }
                
                .lernmodul-content img {
                    max-width: 100%;
                    border-radius: 8px;
                    margin: 16px 0;
                }
                
                .lernmodul-content a {
                    color: #4c6586;
                    text-decoration: underline;
                    text-decoration-color: rgba(76, 101, 134, 0.3);
                    text-underline-offset: 2px;
                    transition: all 0.2s;
                }
                
                .lernmodul-content a:hover {
                    color: #667eea;
                    text-decoration-color: #667eea;
                }
                
                /* Responsive */
                @media (max-width: 1024px) {
                    .lernmodul-sidebar-column {
                        width: 50px;
                    }
                    
                    .lernmodul-sidebar-column .lernmodul-sidebar {
                        padding: 12px;
                    }
                    
                    .lernmodul-sidebar-column .lernmodul-sidebar-title {
                        opacity: 0;
                        width: 0;
                    }
                    
                    .lernmodul-sidebar-column .lernmodul-chapter-list {
                        display: none;
                    }
                    
                    .lernmodul-sidebar-column .lernmodul-collapsed-indicator {
                        display: flex;
                    }
                    
                    .lernmodul-sidebar-column .lernmodul-toggle-btn {
                        transform: rotate(180deg);
                    }
                    
                    /* Expanded state on small screens */
                    .lernmodul-sidebar-column.expanded {
                        width: 280px;
                    }
                    
                    .lernmodul-sidebar-column.expanded .lernmodul-sidebar {
                        padding: 24px;
                    }
                    
                    .lernmodul-sidebar-column.expanded .lernmodul-sidebar-title {
                        opacity: 1;
                        width: auto;
                    }
                    
                    .lernmodul-sidebar-column.expanded .lernmodul-chapter-list {
                        display: block;
                    }
                    
                    .lernmodul-sidebar-column.expanded .lernmodul-collapsed-indicator {
                        display: none;
                    }
                    
                    .lernmodul-sidebar-column.expanded .lernmodul-toggle-btn {
                        transform: rotate(0deg);
                    }
                }
                
                @media (max-width: 768px) {
                    .lernmodul-wrapper {
                        padding: 16px 0;
                    }
                    
                    .lernmodul-main-layout {
                        flex-direction: column;
                        gap: 20px;
                    }
                    
                    .lernmodul-sidebar-column,
                    .lernmodul-sidebar-column.expanded {
                        width: 100%;
                        order: -1;
                    }
                    
                    .lernmodul-sidebar-column .lernmodul-sidebar,
                    .lernmodul-sidebar-column.expanded .lernmodul-sidebar {
                        position: static;
                        max-height: none;
                        padding: 16px;
                    }
                    
                    .lernmodul-sidebar-column .lernmodul-sidebar-title,
                    .lernmodul-sidebar-column.expanded .lernmodul-sidebar-title {
                        opacity: 1;
                        width: auto;
                    }
                    
                    .lernmodul-sidebar-column .lernmodul-chapter-list {
                        display: none;
                    }
                    
                    .lernmodul-sidebar-column.expanded .lernmodul-chapter-list {
                        display: block;
                    }
                    
                    .lernmodul-sidebar-column .lernmodul-collapsed-indicator {
                        display: none;
                    }
                    
                    .lernmodul-sidebar-column .lernmodul-toggle-btn,
                    .lernmodul-sidebar-column.expanded .lernmodul-toggle-btn {
                        transform: rotate(90deg);
                    }
                    
                    .lernmodul-sidebar-column.expanded .lernmodul-toggle-btn {
                        transform: rotate(-90deg);
                    }
                    
                    .lernmodul-container {
                        padding: 24px;
                        border-radius: 12px;
                    }
                    
                    .lernmodul-header {
                        flex-direction: column;
                        gap: 20px;
                        align-items: stretch;
                    }
                    
                    .lernmodul-progress-container {
                        max-width: 100%;
                        margin-right: 0;
                    }
                    
                    .lernmodul-nav-controls {
                        justify-content: center;
                    }
                    
                    .lernmodul-content h3 {
                        font-size: 22px;
                    }
                }
            </style>
            
            <div class='lernmodul-wrapper'>
            <div class='lernmodul-main-layout'>
            ";
            
            // Build page content with Markdown rendering
            $page_content = $page['content'];
            $page_title = htmlspecialchars($page['title']);
            
            // Build sidebar first (will appear on left)
            $html_output = $nav_html;
            // Build collapsed indicator dots
            $collapsed_dots = "";
            for ($i = 1; $i <= $total_pages; $i++) {
                $dot_active = ($i === $current_page) ? " active" : "";
                $collapsed_dots .= "<div class='lernmodul-collapsed-dot{$dot_active}'></div>";
            }
            
            $html_output .= "
            <!-- Sidebar Column (left) -->
            <div class='lernmodul-sidebar-column' id='lernmodul-sidebar'>
                <div class='lernmodul-sidebar'>
                    <div class='lernmodul-sidebar-header'>
                        <div class='lernmodul-sidebar-title'>Inhaltsverzeichnis</div>
                        <button class='lernmodul-toggle-btn' onclick='toggleSidebar()' title='Inhaltsverzeichnis ein-/ausklappen'>◀</button>
                    </div>
                    <div class='lernmodul-collapsed-indicator'>
                        {$collapsed_dots}
                    </div>
                    <ul class='lernmodul-chapter-list'>";
            
            $page_num = 1;
            foreach ($all_pages as $p) {
                $is_active = ($page_num === $current_page) ? " active" : "";
                $chapter_link = $DIC->ctrl()->getLinkTarget($this, "view") . "&page=" . $page_num;
                $chapter_title = htmlspecialchars($p['title']);
                
                $html_output .= "
                        <li class='lernmodul-chapter-item'>
                            <a href='{$chapter_link}' class='lernmodul-chapter-link{$is_active}'>
                                <span class='lernmodul-chapter-number'>{$page_num}</span>
                                <span class='lernmodul-chapter-title'>{$chapter_title}</span>
                            </a>
                        </li>";
                $page_num++;
            }
            
            $html_output .= "
                    </ul>
                </div>
            </div>";
            
            // Then content column (will appear on right)
            $html_output .= "
            <!-- Content Column (right) -->
            <div class='lernmodul-content-column'>
            <div class='lernmodul-container'>
            <div class='lernmodul-header'>
                <div class='lernmodul-progress-container'>
                    <div class='lernmodul-progress-bar'>
                        <div class='lernmodul-progress-fill' style='width: " . $progress_percent . "%;'></div>
                    </div>
                    <div class='lernmodul-progress-text'>" . $progress_percent . "% abgeschlossen</div>
                </div>
                
                <div class='lernmodul-nav-controls'>
                    " . ($current_page > 1 
                        ? "<a href='" . $prev_link . "' class='lernmodul-nav-btn enabled' title='Vorherige Seite'>←</a>"
                        : "<span class='lernmodul-nav-btn disabled' title='Keine vorherige Seite'>←</span>") . "
                    
                    <span class='lernmodul-page-counter'>" . $current_page . " / " . $total_pages . "</span>
                    
                    " . ($current_page < $total_pages
                        ? "<a href='" . $next_link . "' class='lernmodul-nav-btn enabled' title='Nächste Seite'>→</a>"
                        : "<span class='lernmodul-nav-btn disabled' title='Keine nächste Seite'>→</span>") . "
                </div>
            </div>
            
            <script>
            // Sidebar toggle
            function toggleSidebar() {
                var sidebar = document.getElementById('lernmodul-sidebar');
                var isSmallScreen = window.innerWidth <= 1024;
                
                if (isSmallScreen) {
                    sidebar.classList.toggle('expanded');
                } else {
                    sidebar.classList.toggle('collapsed');
                }
                
                // Save state to localStorage
                var isCollapsed = sidebar.classList.contains('collapsed') || 
                                 (isSmallScreen && !sidebar.classList.contains('expanded'));
                localStorage.setItem('lernmodul-sidebar-collapsed', isCollapsed);
            }
            
            // Restore sidebar state on load
            document.addEventListener('DOMContentLoaded', function() {
                var sidebar = document.getElementById('lernmodul-sidebar');
                var isSmallScreen = window.innerWidth <= 1024;
                var savedState = localStorage.getItem('lernmodul-sidebar-collapsed');
                
                if (savedState === 'true') {
                    if (isSmallScreen) {
                        sidebar.classList.remove('expanded');
                    } else {
                        sidebar.classList.add('collapsed');
                    }
                } else if (savedState === 'false' && isSmallScreen) {
                    sidebar.classList.add('expanded');
                }
            });
            
            // Keyboard navigation
            document.addEventListener('keydown', function(e) {
                if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
                
                if (e.key === 'ArrowLeft' && " . ($current_page > 1 ? '1' : '0') . ") {
                    window.location.href = '" . $prev_link . "';
                } else if (e.key === 'ArrowRight' && " . ($current_page < $total_pages ? '1' : '0') . ") {
                    window.location.href = '" . $next_link . "';
                }
            });
            </script>
            ";
            
            $html_output .= "
            <div class='lernmodul-content'>";
            $html_output .= "<h3>" . $page_title . "</h3>";
            $html_output .= "<div id='page-content-md'></div>";
            $html_output .= "</div></div></div>"; // Close content, container, content-column
            
            $html_output .= "</div></div>"; // Close main-layout, wrapper
            
            // Add Markdown rendering via marked.js
            $html_output .= "
            <script src='https://cdn.jsdelivr.net/npm/marked/marked.min.js'></script>
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                marked.setOptions({ breaks: true, gfm: true });
                var content = " . json_encode($page_content) . ";
                document.getElementById('page-content-md').innerHTML = marked.parse(content);
            });
            </script>
            ";
        }
        
        // Output directly without panel wrapper (like ILIAS LearningModule does)
        $this->tpl->setContent($html_output);
    }
    
    /**
     * Study cards from a specific box or all due cards.
     */
    public function studyBox(): void
    {
        global $DIC;
        $this->tabs->activateTab("view");
        
        $db = $DIC->database();
        $obj_id = $this->object->getId();
        $user_id = $DIC->user()->getId();
        $box_number = (int)($_GET['box'] ?? 0);
        
        // Build query based on box selection
        $query = "SELECT c.id, c.front_text, c.back_text, c.difficulty, c.box_number,
                         p.last_review_date, p.next_review_date
                  FROM rep_robj_xmdl_pages c
                  LEFT JOIN rep_robj_xmdl_progress p ON c.id = p.card_id AND p.user_id = " . $db->quote($user_id, 'integer') . "
                  WHERE c.module_id = " . $db->quote($obj_id, 'integer');
        
        if ($box_number > 0) {
            // Study specific box
            $query .= " AND c.box_number = " . $db->quote($box_number, 'integer');
        } else {
            // Study all due cards
            $query .= " AND (p.next_review_date IS NULL OR p.next_review_date <= NOW())";
        }
        
        $query .= " ORDER BY c.sort_order ASC";
        $result = $db->query($query);
        
        $cards = [];
        while ($row = $db->fetchAssoc($result)) {
            $cards[] = $row;
        }
        
        if (empty($cards)) {
            $box_label = $box_number > 0 ? "Box " . $box_number : "due cards";
            $html_output = "
                <div class='alert alert-info'>
                    <h4>No Cards Available</h4>
                    <p>There are no {$box_label} to study right now.</p>
                    <p><a href='" . $DIC->ctrl()->getLinkTarget($this, 'view') . "' class='btn btn-default'>Back to Dashboard</a></p>
                </div>";
        } else {
            $html_output = $this->renderStudyInterface($cards, $box_number);
        }
        
        $box_title = $box_number > 0 ? "Box " . $box_number : "Due Cards";
        $panel = $this->factory->panel()->standard(
            $this->object->getTitle() . " - Study {$box_title}",
            $this->factory->legacy($html_output)
        );

        $this->tpl->setContent($this->renderer->render($panel));
    }
    
    /**
     * Render Leitner box dashboard with overview.
     */
    private function renderBoxDashboard(): string
    {
        global $DIC;
        $db = $DIC->database();
        $obj_id = $this->object->getId();
        $user_id = $DIC->user()->getId();
        
        // Get box statistics
        $boxes = [];
        for ($box = 1; $box <= 5; $box++) {
            $query = "SELECT 
                        COUNT(*) as total_cards,
                        SUM(CASE WHEN p.next_review_date IS NULL OR p.next_review_date <= NOW() THEN 1 ELSE 0 END) as due_cards,
                        MIN(p.next_review_date) as next_due
                      FROM rep_robj_xmdl_pages c
                      LEFT JOIN rep_robj_xmdl_progress p ON c.id = p.card_id AND p.user_id = " . $db->quote($user_id, 'integer') . "
                      WHERE c.module_id = " . $db->quote($obj_id, 'integer') . "
                      AND c.box_number = " . $db->quote($box, 'integer');
            
            $result = $db->query($query);
            $row = $db->fetchAssoc($result);
            
            $boxes[] = [
                'box_number' => $box,
                'total_cards' => (int)$row['total_cards'],
                'due_cards' => (int)($row['due_cards'] ?? 0),
                'next_due' => $row['next_due'],
                'interval_days' => pow(2, $box - 1)
            ];
        }
        
        // Sort boxes: due boxes first, then by next_due date
        usort($boxes, function($a, $b) {
            if ($a['due_cards'] > 0 && $b['due_cards'] == 0) return -1;
            if ($a['due_cards'] == 0 && $b['due_cards'] > 0) return 1;
            if ($a['due_cards'] > 0 && $b['due_cards'] > 0) return 0;
            if ($a['next_due'] === null && $b['next_due'] === null) return $a['box_number'] - $b['box_number'];
            if ($a['next_due'] === null) return 1;
            if ($b['next_due'] === null) return -1;
            return strcmp($a['next_due'], $b['next_due']);
        });
        
        // Calculate total due cards
        $total_due = array_sum(array_column($boxes, 'due_cards'));
        
        $html = "<div style='max-width: 900px; margin: 0 auto;'>";
        
        // Study all due cards button
        if ($total_due > 0) {
            $study_all_url = $DIC->ctrl()->getLinkTarget($this, 'studyBox');
            $html .= "
                <div style='text-align: center; margin-bottom: 30px; padding: 20px; background: #d4edda; border: 2px solid #28a745; border-radius: 8px;'>
                    <h3 style='margin: 0 0 10px 0; color: #155724;'>📚 {$total_due} Cards Ready to Study!</h3>
                    <a href='{$study_all_url}' class='btn btn-lg btn-success'>Study All Due Cards Now</a>
                </div>";
        } else {
            $html .= "
                <div style='text-align: center; margin-bottom: 30px; padding: 20px; background: #d1ecf1; border: 2px solid #17a2b8; border-radius: 8px;'>
                    <h3 style='margin: 0; color: #0c5460;'>✅ All caught up! No cards due right now.</h3>
                    <p style='margin: 10px 0 0 0;'>Come back later or study any box below.</p>
                </div>";
        }
        
        $html .= "<h3>Leitner Box Overview</h3>";
        
        // Render each box
        foreach ($boxes as $box) {
            $box_num = $box['box_number'];
            $total = $box['total_cards'];
            $due = $box['due_cards'];
            $next_due = $box['next_due'];
            $interval = $box['interval_days'];
            
            $is_due = $due > 0;
            $border_color = $is_due ? '#28a745' : '#6c757d';
            $bg_color = $is_due ? '#d4edda' : '#f8f9fa';
            
            $study_url = $DIC->ctrl()->getLinkTargetByClass(get_class($this), 'studyBox') . '&box=' . $box_num;
            
            $html .= "
                <div style='border: 2px solid {$border_color}; border-radius: 8px; padding: 20px; margin-bottom: 15px; background: {$bg_color}; cursor: pointer;' onclick='window.location.href=\"{$study_url}\"'>
                    <div style='display: flex; justify-content: space-between; align-items: center;'>
                        <div>
                            <h4 style='margin: 0 0 5px 0;'>📦 Box {$box_num} <small style='color: #6c757d;'>(Review every {$interval} day" . ($interval > 1 ? 's' : '') . ")</small></h4>
                            <p style='margin: 0; font-size: 0.9em; color: #6c757d;'>{$total} total cards</p>
                        </div>
                        <div style='text-align: right;'>
                            ";
            
            if ($is_due) {
                $html .= "<div style='background: #28a745; color: white; padding: 10px 20px; border-radius: 5px; font-weight: bold; font-size: 1.2em;'>{$due} DUE NOW</div>";
            } else if ($next_due) {
                $next_date = new DateTime($next_due);
                $now = new DateTime();
                
                // Use date-only comparison to avoid time component issues
                $next_date->setTime(0, 0, 0);
                $now->setTime(0, 0, 0);
                
                $diff = $now->diff($next_date);
                $days_until = $diff->days;
                
                // Display the actual date for next review
                $next_date_display = new DateTime($next_due);
                $html .= "<div style='color: #6c757d;'>Next review in <strong>{$days_until} day" . ($days_until != 1 ? 's' : '') . "</strong><br><small>" . $next_date_display->format('M j, Y') . "</small></div>";
            } else if ($total > 0) {
                $html .= "<div style='color: #6c757d;'>Ready to study</div>";
            } else {
                $html .= "<div style='color: #adb5bd;'>Empty</div>";
            }
            
            $html .= "
                        </div>
                    </div>
                </div>";
        }
        
        $html .= "</div>";
        
        return $html;
    }
    
    /**
     * Render study interface with flip card functionality
     */
    private function renderStudyInterface(array $cards, int $box_number = 0): string
    {
        global $DIC;
        
        $dashboard_url = $DIC->ctrl()->getLinkTarget($this, 'view');
        
        $html = "<div class='flashcard-study-container' style='max-width: 800px; margin: 0 auto; position: relative;'>
                    <div class='flashcard-card' style='perspective: 1000px; min-height: 400px;'>
                        <div class='flashcard-inner' id='flashcard-inner' style='position: relative; width: 100%; height: 400px; transition: transform 0.6s; transform-style: preserve-3d;'>
                            <div class='flashcard-front' style='position: absolute; width: 100%; height: 100%; backface-visibility: hidden; background: white; border: 2px solid #ddd; border-radius: 8px; padding: 40px; display: flex; align-items: center; justify-content: center; font-size: 1.2em;'>
                                <div id='card-front-content'>" . htmlspecialchars($cards[0]['front_text']) . "</div>
                            </div>
                            <div class='flashcard-back' style='position: absolute; width: 100%; height: 100%; backface-visibility: hidden; background: #f0f8ff; border: 2px solid #4a90e2; border-radius: 8px; padding: 40px; display: flex; align-items: center; justify-content: center; font-size: 1.2em; transform: rotateY(180deg);'>
                                <div id='card-back-content'>" . htmlspecialchars($cards[0]['back_text']) . "</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class='flashcard-controls' style='text-align: center; margin-top: 20px;'>
                        <button onclick='flipCard()' class='btn btn-lg btn-primary' style='margin: 10px;'>Flip Card</button>
                    </div>
                    
                    <div class='flashcard-rating' id='rating-buttons' style='text-align: center; margin-top: 20px; display: none;'>
                        <p><strong>How well did you know this?</strong></p>
                        <button onclick='rateCard(\"hard\")' class='btn btn-lg btn-danger' style='margin: 5px;'>Hard</button>
                        <button onclick='rateCard(\"medium\")' class='btn btn-lg btn-warning' style='margin: 5px;'>Medium</button>
                        <button onclick='rateCard(\"easy\")' class='btn btn-lg btn-success' style='margin: 5px;'>Easy</button>
                    </div>
                    
                    <div class='flashcard-progress' style='text-align: center; margin-top: 20px;'>
                        <p>Card <span id='current-card'>1</span> of " . count($cards) . "</p>
                        <p style='margin-top: 15px;'><a href='{$dashboard_url}' style='color: #6c757d; font-size: 0.9em; text-decoration: none;'>← Back to Dashboard</a></p>
                    </div>
                </div>
                
                <script>
                var cards = " . json_encode($cards) . ";
                var currentIndex = 0;
                var isFlipped = false;
                var baseUrl = '" . $DIC->ctrl()->getLinkTarget($this, 'rateCard', '', true, false) . "';
                
                function flipCard() {
                    var inner = document.getElementById('flashcard-inner');
                    if (!isFlipped) {
                        inner.style.transform = 'rotateY(180deg)';
                        document.getElementById('rating-buttons').style.display = 'block';
                    } else {
                        inner.style.transform = 'rotateY(0deg)';
                        document.getElementById('rating-buttons').style.display = 'none';
                    }
                    isFlipped = !isFlipped;
                }
                
                function rateCard(rating) {
                    var card = cards[currentIndex];
                    var formData = new FormData();
                    formData.append('card_id', card.id);
                    formData.append('rating', rating);
                    
                    // Send rating to server
                    fetch(baseUrl, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Show feedback
                            var msg = '';
                            if (rating === 'easy') {
                                msg = '✓ Great! Card moved to box ' + data.new_box;
                            } else if (rating === 'medium') {
                                msg = '~ OK. Card stays in box ' + data.new_box;
                            } else {
                                msg = '✗ Card moved back to box 1 for more practice';
                            }
                            
                            // Show brief feedback
                            var feedback = document.createElement('div');
                            feedback.style.cssText = 'position: fixed; top: 20px; right: 20px; background: #4CAF50; color: white; padding: 15px 20px; border-radius: 5px; z-index: 9999;';
                            feedback.textContent = msg;
                            document.body.appendChild(feedback);
                            setTimeout(() => feedback.remove(), 2000);
                            
                            // Move to next card
                            currentIndex++;
                            if (currentIndex < cards.length) {
                                loadCard(currentIndex);
                            } else {
                                alert('🎉 You\\'ve completed all flashcards in this session!\\n\\nCards will reappear based on their review schedule.');
                                location.reload();
                            }
                        } else {
                            alert('Error: ' + data.error);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('Failed to save rating. Please try again.');
                    });
                }
                
                function loadCard(index) {
                    var card = cards[index];
                    document.getElementById('card-front-content').textContent = card.front_text;
                    document.getElementById('card-back-content').textContent = card.back_text;
                    document.getElementById('current-card').textContent = index + 1;
                    
                    // Reset flip
                    if (isFlipped) {
                        flipCard();
                    }
                }
                </script>";
        
        return $html;
    }

    /**
     * Manage Pages View
     * 
     * Displays all pages in a table with edit/delete functionality
     */
    public function flashcards(): void
    {
        global $DIC;
        $this->tabs->activateTab("flashcards");
        
        // Add toolbar button to create new page
        if ($this->checkPermissionBool("write")) {
            $button = $this->factory->button()->primary(
                "Add Page",
                $DIC->ctrl()->getLinkTarget($this, "addLernmodul")
            );
            $DIC->toolbar()->addComponent($button);
            
            $import_button = $this->factory->button()->standard(
                "Import from Markdown",
                $DIC->ctrl()->getLinkTarget($this, "importMarkdown")
            );
            $DIC->toolbar()->addComponent($import_button);
        }
        
        // Fetch all pages from database
        $db = $DIC->database();
        $obj_id = $this->object->getId();
        
        $query = "SELECT id, title, content, page_number, sort_order 
                  FROM rep_robj_xmdl_pages 
                  WHERE module_id = " . $db->quote($obj_id, 'integer') . "
                  ORDER BY sort_order ASC";
        $result = $db->query($query);
        
        $pages = [];
        while ($row = $db->fetchAssoc($result)) {
            $pages[] = $row;
        }
        
        // Build HTML table
        $html = "<div class='ilTableOuter'>";
        
        if (empty($pages)) {
            $html .= "<div class='alert alert-info'>
                        <h4>No Pages Yet</h4>
                        <p>Click 'Add Page' to create your first page, or use the 'AI Generate' tab to create content automatically.</p>
                      </div>";
        } else {
            $html .= "
                <table class='table table-striped'>
                    <thead>
                        <tr>
                            <th style='width: 10%'>Page #</th>
                            <th style='width: 30%'>Title</th>
                            <th style='width: 50%'>Content (Preview)</th>
                            <th style='width: 10%'>Actions</th>
                        </tr>
                    </thead>
                    <tbody>";
            
            foreach ($pages as $page) {
                $title = htmlspecialchars($page['title']);
                $content_preview = substr(htmlspecialchars($page['content']), 0, 100) . "...";
                $page_number = (int)$page['page_number'];
                $page_id = $page['id'];
                
                $edit_link = $DIC->ctrl()->getLinkTarget($this, "editLernmodul") . "&card_id=" . $page_id;
                $delete_link = $DIC->ctrl()->getLinkTarget($this, "deleteLernmodul") . "&card_id=" . $page_id;
                
                $html .= "<tr>
                            <td>{$page_number}</td>
                            <td>{$title}</td>
                            <td>{$content_preview}</td>
                            <td>
                                <a href='" . $edit_link . "' class='btn btn-sm btn-primary'>Edit</a>
                                <a href='" . $delete_link . "' class='btn btn-sm btn-danger' onclick='return confirm(\"Delete this page?\");'>Delete</a>
                            </td>
                          </tr>";
            }
            
            $html .= "</tbody></table>";
        }
        
        $html .= "</div>";
        
        $panel = $this->factory->panel()->standard(
            "Manage Pages",
            $this->factory->legacy($html)
        );

        $this->tpl->setContent($this->renderer->render($panel));
    }
    
    /**
     * AJAX endpoint for inline flashcard updates
     */
    public function updateLernmodulInline(): void
    {
        global $DIC;
        
        $card_id = (int)($_POST['card_id'] ?? 0);
        $field = $_POST['field'] ?? '';
        $value = $_POST['value'] ?? '';
        
        if (!$card_id || !$field) {
            echo json_encode(['success' => false, 'error' => 'Missing parameters']);
            exit;
        }
        
        // Validate field name to prevent SQL injection
        $allowed_fields = ['front_text', 'back_text', 'difficulty'];
        if (!in_array($field, $allowed_fields)) {
            echo json_encode(['success' => false, 'error' => 'Invalid field']);
            exit;
        }
        
        $db = $DIC->database();
        
        // Verify card belongs to this deck
        $obj_id = $this->object->getId();
        $check_query = "SELECT module_id FROM rep_robj_xmdl_pages WHERE id = " . $db->quote($card_id, 'integer');
        $check_result = $db->query($check_query);
        $check_row = $db->fetchAssoc($check_result);
        
        if (!$check_row || $check_row['module_id'] != $obj_id) {
            echo json_encode(['success' => false, 'error' => 'Card not found']);
            exit;
        }
        
        // Update the field
        $db->update(
            'rep_robj_xmdl_pages',
            [$field => ['text', $value]],
            ['id' => ['integer', $card_id]]
        );
        
        echo json_encode(['success' => true]);
        exit;
    }

    /**
     * Settings form handler
     * 
     * Displays and processes the settings form for:
     * - Quiz title
     * - Online/offline status
     * - Markdown content
     * 
     * Uses ILIAS UI components with transformations for automatic saving
     */
    public function settings(): void
    {
        $this->checkPermission("write");
        $this->tabs->activateTab("settings");

        $form = $this->buildSettingsForm();

        if ($this->request->getMethod() === "POST") {
            $form = $form->withRequest($this->request);
            $data = $form->getData();
            if ($data !== null) {
                // Reload object to ensure we have the latest data
                $this->object->read();
                
                // Data already saved via transformations
                $this->tpl->setOnScreenMessage('success', 'Settings saved successfully');
                
                // Rebuild form with fresh data
                $form = $this->buildSettingsForm();
            }
        }

        $this->tpl->setContent($this->renderer->render($form));
    }

    /**
     * Build the settings form
     * 
     * Creates a form with inline save functionality using transformations:
     * - Each field saves immediately when form is submitted
     * - Uses ILIAS UI components for modern interface
     * - Handles title, online status, and markdown content
     * 
     * @return \ILIAS\UI\Component\Input\Container\Form\Form The configured form
     */
    private function buildSettingsForm(): \ILIAS\UI\Component\Input\Container\Form\Form
    {
        // Set form action to explicitly point back to settings command
        $form_action = $this->ctrl->getFormAction($this, 'settings');

        $title_field = $this->factory->input()->field()->text("Lernmodul Deck Title")
            ->withValue((string)$this->object->getTitle())
            ->withAdditionalTransformation(
                $this->refinery->custom()->transformation(
                    function ($v) {
                        $this->object->setTitle(trim($v));
                        $this->object->update();
                        return $v;
                    }
                )
            )->withRequired(true);

        $online_field = $this->factory->input()->field()->checkbox("Online", "Make this quiz available to users")
            ->withValue($this->object->getOnline())
            ->withAdditionalTransformation(
                $this->refinery->custom()->transformation(
                    function ($v) {
                        // Checkbox returns true when checked, null/false when unchecked
                        $is_online = ($v === true || $v === 1 || $v === "1");
                        $this->object->setOnline($is_online);
                        $this->object->update();
                        return $is_online;
                    }
                )
            );

        return $this->factory->input()->container()->form()->standard(
            $form_action,
            ['title' => $title_field, 'online' => $online_field]
        );
    }

    /**
     * AI Quiz Generation View
     * 
     * Main interface for generating quizzes using AI services.
     * 
     * Features:
     * - Supports OpenAI, Google Gemini, and GWDG Academic Cloud
     * - Prompt input with 5000 char limit
     * - Optional context field (10000 chars) or file selection
     * - Difficulty selection (easy, medium, hard, mixed)
     * - Question count (1-20)
     * - Pre-fills last used values for convenience
     * 
     * Security:
     * - Rate limiting enforced
     * - Input validation
     * - File type whitelist
     */
    public function generate(): void
    {
        global $DIC;
        
        $this->checkPermission("write");
        $this->tabs->activateTab("generate");

        ilMarkdownLernmodulConfig::load();
        
        // Determine active provider from available_services
        $available_services = ilMarkdownLernmodulConfig::get('available_services');
        if (!is_array($available_services)) {
            $available_services = [];
        }
        
        $provider = null;
        $api_key = null;
        
        if (isset($available_services['openai']) && $available_services['openai']) {
            $provider = 'openai';
            $api_key = ilMarkdownLernmodulConfig::get('openai_api_key');
        } elseif (isset($available_services['google']) && $available_services['google']) {
            $provider = 'google';
            $api_key = ilMarkdownLernmodulConfig::get('google_api_key');
        } elseif (isset($available_services['gwdg']) && $available_services['gwdg']) {
            $provider = 'gwdg';
            $api_key = ilMarkdownLernmodulConfig::get('gwdg_api_key');
        }
        
        $config_complete = !empty($provider) && !empty($api_key);

        if (!$config_complete) {
            $info = $this->factory->messageBox()->info(
                "AI configuration is incomplete. Please contact an administrator to configure the API settings."
            );
            $this->tpl->setContent($this->renderer->render($info));
            return;
        }

        $form_action = $this->ctrl->getLinkTargetByClass("ilObjMarkdownLernmodulGUI", "generate");
        
        // Load last used prompt from this learning module
        $last_prompt = $this->object->getLastPrompt() ?: 'Create a comprehensive learning module about: ';
        
        $prompt_field = $this->factory->input()->field()->textarea("Prompt", "Describe the topic or paste content for the AI to create learning module pages from. The AI will automatically determine the optimal number of pages.")
            ->withValue($last_prompt)
            ->withRequired(true);
        
        $context_field = $this->factory->input()->field()->textarea(
            "Additional Context (Optional)",
            "Paste content from a PDF or any additional text to provide context for the learning module generation."
        )->withValue($this->object->getLastContext());
        
        // Get available files from parent container
        $available_files = $this->getAvailableFiles();
        
        // DEBUG: Log what's in the array
        error_log("Available files array: " . print_r(array_keys($available_files), true));
        
        // Validate saved file ref_id - reset if deleted or inaccessible
        $saved_ref_id = $this->object->getLastFileRefId();
        if ($saved_ref_id > 0 && !isset($available_files[(string)$saved_ref_id])) {
            // File was deleted or is no longer accessible, reset to 0
            $saved_ref_id = 0;
            $this->object->setLastFileRefId(0);
            $this->object->update();
        }
        
        error_log("Saved ref_id: " . $saved_ref_id);
        
        if (!empty($available_files)) {
            $file_ref_field = $this->factory->input()->field()->select(
                "ILIAS File (Optional)",
                $available_files,
                "Select a file from this course/folder to use its content as context."
            )->withValue((string)$saved_ref_id);
        } else {
            $file_ref_field = $this->factory->input()->field()->numeric(
                "ILIAS File Reference (Optional)",
                "Enter the ref_id of an ILIAS File object to use its content as context."
            )->withValue($saved_ref_id);
        }

        $fields = [
            'prompt' => $prompt_field,
            'context' => $context_field,
            'file_ref_id' => $file_ref_field
        ];
        
        $form = $this->factory->input()->container()->form()->standard(
            $form_action,
            $fields
        );

        if ($this->request->getMethod() == "POST") {
            $form = $form->withRequest($this->request);
            $data = $form->getData();
            
            
            if ($data) {
                try {
                    // RATE LIMIT: Check learning module generation cooldown
                    ilMarkdownLernmodulRateLimiter::recordQuizGeneration();
                    
                    // RATE LIMIT: Increment concurrent request counter
                    ilMarkdownLernmodulRateLimiter::incrementConcurrent();
                    
                    // SECURITY: Validate and sanitize inputs
                    $prompt = ilMarkdownLernmodulXSSProtection::sanitizeUserInput($data['prompt'], 5000);
                    
                    // Get context from textarea or file
                    $context = ilMarkdownLernmodulXSSProtection::sanitizeUserInput($data['context'] ?? '', 10000);
                    
                    // If file ref_id provided, fetch file content
                    if (!empty($data['file_ref_id']) && $data['file_ref_id'] > 0) {
                        $file_context = $this->getFileContent((int)$data['file_ref_id']);
                        if (!empty($file_context)) {
                            $context .= ($context ? "\n\n" : "") . $file_context;
                        }
                    }
                    
                    
                    $markdown = $this->generateMarkdownLernmodul(
                        $prompt, 
                        $context
                    );
                    
                    
                    // SECURITY: Protect generated content before storing
                    $markdown = ilMarkdownLernmodulXSSProtection::protectContent($markdown);
                    
                    
                    
                    if (empty($markdown)) {
                        ilMarkdownLernmodulRateLimiter::decrementConcurrent();
                        $this->tpl->setOnScreenMessage('failure', 'AI returned empty content');
                    } else {
                        // Parse pages and save to database
                        try {
                            $pages = \security\ilMarkdownLernmodulResponseValidator::validateLernmodulFormat($markdown);
                            
                            // DEBUG: Log parsed pages structure
                            error_log("Parsed pages count: " . count($pages));
                            error_log("First page structure: " . print_r($pages[0] ?? 'none', true));
                            
                            // Get max sort_order and page_number
                            $db = $DIC->database();
                            $obj_id = $this->object->getId();
                            $query = "SELECT MAX(sort_order) as max_order, MAX(page_number) as max_page FROM rep_robj_xmdl_pages WHERE module_id = " . $db->quote($obj_id, 'integer');
                            $result = $db->query($query);
                            $row = $db->fetchAssoc($result);
                            $sort_order = ((int)$row['max_order']) + 1;
                            $page_number = ((int)$row['max_page']) + 1;
                            
                            // Insert each page
                            foreach ($pages as $page) {
                                // Validate page structure
                                if (!isset($page['title']) || !isset($page['content'])) {
                                    error_log("Invalid page structure: " . print_r($page, true));
                                    continue;
                                }
                                
                                $next_id = $db->nextId('rep_robj_xmdl_pages');
                                $db->insert('rep_robj_xmdl_pages', [
                                    'id' => ['integer', $next_id],
                                    'module_id' => ['integer', $obj_id],
                                    'title' => ['text', $page['title']],
                                    'content' => ['text', $page['content']],
                                    'page_number' => ['integer', $page_number++],
                                    'sort_order' => ['integer', $sort_order++]
                                ]);
                            }
                            
                            // Save last settings
                            $this->object->setLastPrompt($prompt);
                            $this->object->setLastContext($data['context'] ?? '');
                            $this->object->setLastFileRefId((int)($data['file_ref_id'] ?? 0));
                            $this->object->update();

                            ilMarkdownLernmodulRateLimiter::decrementConcurrent();
                            $this->tpl->setOnScreenMessage('success', count($pages) . ' pages generated successfully!');
                            $this->ctrl->redirect($this, 'view');
                            return;
                        } catch (\Exception $e) {
                            ilMarkdownLernmodulRateLimiter::decrementConcurrent();
                            $this->tpl->setOnScreenMessage('failure', 'Error parsing pages: ' . $e->getMessage());
                        }
                    }
                } catch (\Exception $e) {
                    ilMarkdownLernmodulRateLimiter::decrementConcurrent();
                    $this->tpl->setOnScreenMessage('failure', 'Error: ' . $e->getMessage());
                }
            }
        }

        $this->tpl->setContent($this->renderer->render($form));
    }

    /**
     * Get available files from parent container and nearby objects
     */
    private function getAvailableFiles(): array
    {
        global $DIC;
        
        $files = [];
        
        // Supported file extensions
        $supported_extensions = ['txt', 'pdf', 'doc', 'docx', 'ppt', 'pptx'];
        
        try {
            // Get parent ref_id
            $parent_ref_id = $DIC->repositoryTree()->getParentId($this->object->getRefId());
            
            if ($parent_ref_id > 0) {
                // Get files
                $children = $DIC->repositoryTree()->getChildsByType($parent_ref_id, 'file');
                
                foreach ($children as $child) {
                    $ref_id = $child['ref_id'];
                    
                    // Check read permission
                    if ($DIC->access()->checkAccess('read', '', $ref_id)) {
                        $obj_id = ilObject::_lookupObjectId($ref_id);
                        $title = ilObject::_lookupTitle($obj_id);
                        
                        // Get file info
                        try {
                            $file_obj = new ilObjFile($obj_id, false);
                            $size_kb = round($file_obj->getFileSize() / 1024, 2);
                            $ext = strtolower($file_obj->getFileExtension());
                            
                            // Only include supported file types
                            if (in_array($ext, $supported_extensions)) {
                                $files[$ref_id] = "$title ($ext, $size_kb KB)";
                            }
                        } catch (\Exception $e) {
                            // Skip files with errors
                            continue;
                        }
                    }
                }
                
                // Get learning modules
                $lms = $DIC->repositoryTree()->getChildsByType($parent_ref_id, 'lm');
                
                foreach ($lms as $lm) {
                    $ref_id = $lm['ref_id'];
                    
                    if ($DIC->access()->checkAccess('read', '', $ref_id)) {
                        $obj_id = ilObject::_lookupObjectId($ref_id);
                        $title = ilObject::_lookupTitle($obj_id);
                        
                        $files[$ref_id] = "$title [Learning Module]";
                    }
                }
            }
        } catch (\Exception $e) {
        }
        
        // Filter out any empty keys/values that might cause UI issues
        $files = array_filter($files, function($key, $value) {
            return !empty($key) && $key !== '' && $key !== '-' && !empty($value);
        }, ARRAY_FILTER_USE_BOTH);
        
        // Always add "-- None --" as first option with key "0" (string to avoid ILIAS UI issues)
        return ["0" => "-- None --"] + $files;
    }

    /**
     * Get content from an ILIAS File object
     */
    private function getFileContent(int $ref_id): string
    {
        try {
            // RATE LIMIT: Check file processing limit
            ilMarkdownLernmodulRateLimiter::recordFileProcessing();
            
            global $DIC;
            
            // Check if object exists and is a file
            if (!ilObject::_exists($ref_id, true)) {
                return '';
            }
            
            $type = ilObject::_lookupType($ref_id, true);
            
            // Check read permission
            if (!$DIC->access()->checkAccess('read', '', $ref_id)) {
                return '';
            }
            
            $obj_id = ilObject::_lookupObjectId($ref_id);
            
            // Handle Learning Module
            if ($type === 'lm') {
                return $this->getLearningModuleContent($obj_id);
            }
            
            // Handle File
            if ($type !== 'file') {
                return '';
            }
            
            $file_obj = new ilObjFile($obj_id, false);
            
            // Get file via resource storage
            $resource_id_string = $file_obj->getResourceId();
            $resource_identification = $DIC->resourceStorage()->manage()->find($resource_id_string);
            
            if (!$resource_identification) {
                return '';
            }
            
            // Use consume to get stream
            $stakeholder = new ilObjMarkdownLernmodulStakeholder();
            $stream = $DIC->resourceStorage()->consume()->stream($resource_identification)->getStream();
            $content = $stream->getContents();
            
            // SECURITY: Validate file size
            $suffix = strtolower($file_obj->getFileExtension());
            ilMarkdownLernmodulFileSecurity::validateFileSize($content);
            
            // Try to extract text based on file type
            
            if ($suffix === 'txt') {
                return $content;
            } elseif ($suffix === 'pdf') {
                return $this->extractTextFromPDF($content);
            } elseif (in_array($suffix, ['ppt', 'pptx'])) {
                return $this->extractTextFromPowerPoint($content, $suffix);
            } elseif (in_array($suffix, ['doc', 'docx'])) {
                return $this->extractTextFromWord($content, $suffix);
            } else {
                // Unsupported file type
                throw new \Exception(
                    "Unsupported file type: {$suffix}. Supported types: txt, pdf, doc, docx, ppt, pptx"
                );
            }
            
        } catch (\Exception $e) {
            return '';
        }
    }
    
    /**
     * Get content from Learning Module pages
     */
    private function getLearningModuleContent(int $obj_id): string
    {
        try {
            $lm_obj = new ilObjLearningModule($obj_id, false);
            $text = '';
            
            // Get all pages
            $pages = ilLMPageObject::getPageList($obj_id);
            
            
            foreach ($pages as $page) {
                $page_obj = new ilLMPageObject($lm_obj, $page['obj_id']);
                $page_xml = $page_obj->getPageObject()->getXMLContent();
                
                // Extract text from XML/HTML content
                $page_text = $this->extractTextFromHTML($page_xml);
                
                if (!empty($page_text)) {
                    $text .= $page['title'] . ": " . $page_text . "\n\n";
                }
            }
            
            
            // Limit length
            if (strlen($text) > 8000) {
                $text = substr($text, 0, 8000) . '...';
            }
            
            return $text;
        } catch (\Exception $e) {
            return '';
        }
    }
    
    /**
     * Extract text from HTML/XML content
     */
    private function extractTextFromHTML(string $html): string
    {
        // Remove script and style tags
        $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
        $html = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $html);
        
        // Strip all HTML tags
        $text = strip_tags($html);
        
        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // Clean up whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);
        
        return $text;
    }
    
    /**
     * Extract text from PDF content
     */
    private function extractTextFromPDF(string $content): string
    {
        try {
            // SECURITY: Set timeout and validate file
            ilMarkdownLernmodulFileSecurity::setProcessingTimeout();
            ilMarkdownLernmodulFileSecurity::validateFile($content, 'pdf');
            
            $text = '';
            
            // Extract text from Tj and TJ operators (text showing operators in PDF)
            // Look for patterns like: (text string) Tj or [(text) (string)] TJ
            if (preg_match_all('/\(([^)]*)\)\s*T[jJ*\']/', $content, $matches)) {
                foreach ($matches[1] as $match) {
                    $decoded = $this->decodePDFString($match);
                    if (!empty(trim($decoded))) {
                        $text .= $decoded . ' ';
                    }
                }
            }
            
            // Also look for text in array format: [(text1) (text2)] TJ
            if (preg_match_all('/\[\s*\((.*?)\)\s*\]\s*TJ/', $content, $matches)) {
                foreach ($matches[1] as $match) {
                    $decoded = $this->decodePDFString($match);
                    if (!empty(trim($decoded))) {
                        $text .= $decoded . ' ';
                    }
                }
            }
            
            // Fallback: If still empty, try to extract readable text from anywhere
            if (empty($text)) {
                // Look for any parenthesized content that looks like text
                if (preg_match_all('/\(([A-Za-z0-9äöüÄÖÜß\s,\.;:\-?!]{3,})\)/', $content, $matches)) {
                    foreach ($matches[1] as $match) {
                        $decoded = $this->decodePDFString($match);
                        if (!empty(trim($decoded))) {
                            $text .= $decoded . ' ';
                        }
                    }
                }
            }
            
            // Clean up
            $text = preg_replace('/\s+/', ' ', $text);
            $text = trim($text);
            
            
            // Limit length
            if (strlen($text) > 5000) {
                $text = substr($text, 0, 5000) . '...';
            }
            
            return $text;
        } catch (\Exception $e) {
            return '';
        }
    }
    
    /**
     * Decode PDF string (handle escape sequences)
     */
    private function decodePDFString(string $str): string
    {
        // Handle common PDF escape sequences
        $str = str_replace(['\\n', '\\r', '\\t', '\\\\', '\\(', '\\)'], ["\n", "\r", "\t", "\\", "(", ")"], $str);
        // Remove octal codes
        $str = preg_replace('/\\\\[0-7]{1,3}/', '', $str);
        return $str;
    }
    
    /**
     * Extract text from PowerPoint content (.pptx)
     */
    private function extractTextFromPowerPoint(string $content, string $format): string
    {
        try {
            // SECURITY: Set timeout and validate file size
            ilMarkdownLernmodulFileSecurity::setProcessingTimeout();
            ilMarkdownLernmodulFileSecurity::validateFileSize($content);
            
            if ($format !== 'pptx') {
                return '';
            }
            
            // Save content to temp file (PPTX is a ZIP archive)
            $temp_file = tempnam(sys_get_temp_dir(), 'mdquiz_pptx_');
            file_put_contents($temp_file, $content);
            
            // SECURITY: Validate ZIP file (magic bytes, compression ratio, virus scan)
            ilMarkdownLernmodulFileSecurity::validateFile($content, 'pptx', $temp_file);
            // SECURITY: Validate ZIP file (magic bytes, compression ratio, virus scan)
            ilMarkdownLernmodulFileSecurity::validateFile($content, 'pptx', $temp_file);
            // Save content to temporary file (PPTX is a ZIP archive)
            $temp_file = tempnam(sys_get_temp_dir(), 'pptx_');
            file_put_contents($temp_file, $content);
            
            $text = '';
            
            // Open PPTX as ZIP archive
            $zip = new ZipArchive();
            if ($zip->open($temp_file) === true) {
                
                // Extract text from all slides
                for ($i = 1; $i <= 100; $i++) { // Try up to 100 slides
                    $slide_path = "ppt/slides/slide{$i}.xml";
                    $slide_content = $zip->getFromName($slide_path);
                    
                    if ($slide_content === false) {
                        break; // No more slides
                    }
                    
                    
                    // Parse XML and extract text
                    $slide_text = $this->extractTextFromPowerPointXML($slide_content);
                    if (!empty($slide_text)) {
                        $text .= "Slide $i: " . $slide_text . "\n\n";
                    }
                }
                
                $zip->close();
            } else {
            }
            
            // Clean up temporary file
            unlink($temp_file);
            
            // Clean up whitespace
            $text = preg_replace('/\s+/', ' ', $text);
            $text = trim($text);
            
            
            // Limit length
            if (strlen($text) > 5000) {
                $text = substr($text, 0, 5000) . '...';
            }
            
            return $text;
        } catch (\Exception $e) {
            return '';
        }
    }
    
    /**
     * Extract text from PowerPoint slide XML
     */
    private function extractTextFromPowerPointXML(string $xml): string
    {
        try {
            // Disable external entity loading to prevent XXE attacks
            $previous_value = libxml_disable_entity_loader(true);
            
            // Parse XML with security flags
            $dom = new DOMDocument();
            $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_DTDLOAD | LIBXML_DTDATTR);
            
            // Restore previous setting
            libxml_disable_entity_loader($previous_value);
            
            if (!$loaded) {
                throw new Exception('Failed to parse PowerPoint XML');
            }
            
            // Get all text elements (a:t tags in PowerPoint XML)
            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
            $text_nodes = $xpath->query('//a:t');
            
            $text = '';
            foreach ($text_nodes as $node) {
                $text .= $node->textContent . ' ';
            }
            
            return trim($text);
        } catch (\Exception $e) {
            return '';
        }
    }
    
    /**
     * Extract text from Word content (.docx)
     */
    private function extractTextFromWord(string $content, string $format): string
    {
        try {
            // SECURITY: Set timeout and validate file size
            ilMarkdownLernmodulFileSecurity::setProcessingTimeout();
            ilMarkdownLernmodulFileSecurity::validateFileSize($content);
            
            if ($format !== 'docx') {
                return '';
            }
            
            // Save content to temp file (DOCX is a ZIP archive)
            $temp_file = tempnam(sys_get_temp_dir(), 'mdquiz_docx_');
            file_put_contents($temp_file, $content);
            
            // SECURITY: Validate ZIP file (magic bytes, compression ratio, virus scan)
            ilMarkdownLernmodulFileSecurity::validateFile($content, 'docx', $temp_file);
            
            $text = '';
            
            // Open DOCX as ZIP archive
            $zip = new ZipArchive();
            if ($zip->open($temp_file) === true) {
                
                // Extract text from main document
                $doc_content = $zip->getFromName('word/document.xml');
                
                if ($doc_content !== false) {
                    $text = $this->extractTextFromWordXML($doc_content);
                } else {
                }
                
                $zip->close();
            } else {
            }
            
            // Clean up temporary file
            unlink($temp_file);
            
            // Clean up whitespace
            $text = preg_replace('/\s+/', ' ', $text);
            $text = trim($text);
            
            
            // Limit length
            if (strlen($text) > 5000) {
                $text = substr($text, 0, 5000) . '...';
            }
            
            return $text;
        } catch (\Exception $e) {
            return '';
        }
    }
    
    /**
     * Extract text from Word document XML
     */
    private function extractTextFromWordXML(string $xml): string
    {
        try {
            // Disable external entity loading to prevent XXE attacks
            $previous_value = libxml_disable_entity_loader(true);
            
            // Parse XML with security flags
            $dom = new DOMDocument();
            $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_DTDLOAD | LIBXML_DTDATTR);
            
            // Restore previous setting
            libxml_disable_entity_loader($previous_value);
            
            if (!$loaded) {
                throw new Exception('Failed to parse Word XML');
            }
            
            // Get all text elements (w:t tags in Word XML)
            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $text_nodes = $xpath->query('//w:t');
            
            $text = '';
            $last_was_paragraph = false;
            
            foreach ($text_nodes as $node) {
                $text .= $node->textContent . ' ';
            }
            
            // Get paragraph breaks for better formatting
            $paragraph_nodes = $xpath->query('//w:p');
            if ($paragraph_nodes->length > 0) {
                // If we have paragraph structure, extract with paragraph breaks
                $text = '';
                foreach ($paragraph_nodes as $p_node) {
                    $p_xpath = new DOMXPath($dom);
                    $p_xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
                    
                    // Get text nodes within this paragraph
                    $p_text_nodes = $p_xpath->query('.//w:t', $p_node);
                    $paragraph_text = '';
                    foreach ($p_text_nodes as $t_node) {
                        $paragraph_text .= $t_node->textContent;
                    }
                    
                    if (!empty(trim($paragraph_text))) {
                        $text .= trim($paragraph_text) . "\n";
                    }
                }
            }
            
            return trim($text);
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * @throws ilMarkdownLernmodulAIException
     */
    private function generateMarkdownLernmodul(string $user_prompt, string $context = ''): string
    {
        // RATE LIMIT: Check API call limit
        ilMarkdownLernmodulRateLimiter::recordApiCall();
        
        ilMarkdownLernmodulConfig::load();

        $available_services = ilMarkdownLernmodulConfig::get('available_services');
        if (empty($available_services) || !is_array($available_services)) {
            $available_services = [];
        }
        
        $ai = null;

        // Try OpenAI first if available
        if (isset($available_services['openai']) && $available_services['openai']) {
            $api_key = ilMarkdownLernmodulConfig::get('openai_api_key');
            $model = ilMarkdownLernmodulConfig::get('openai_model') ?: 'gpt-4o-mini';

            if (!empty($api_key)) {
                $ai = new ilMarkdownLernmodulOpenAI($api_key, $model);
            }
        }

        // Try Google if OpenAI not available
        if ($ai === null && isset($available_services['google']) && $available_services['google']) {
            $api_key = ilMarkdownLernmodulConfig::get('google_api_key');

            if (!empty($api_key)) {
                $ai = new ilMarkdownLernmodulGoogleAI($api_key, 'gemini-2.5-flash');
            }
        }

        // Fall back to GWDG if available
        if ($ai === null && isset($available_services['gwdg']) && $available_services['gwdg']) {
            $api_key = ilMarkdownLernmodulConfig::get('gwdg_api_key');
            $models = ilMarkdownLernmodulConfig::get('gwdg_models');

            if (!empty($api_key) && !empty($models) && is_array($models)) {
                // Get the first available model
                $model_id = array_key_first($models);
                $ai = new ilMarkdownLernmodulGWDG($api_key, $model_id);
            }
        }

        if ($ai === null) {
            throw new ilMarkdownLernmodulAIException("No AI provider is properly configured");
        }
        
        // Combine user prompt with context if available
        $full_prompt = $user_prompt;
        if (!empty($context)) {
            $full_prompt .= "\n\n[Additional Context:]\n" . $context;
        }

        return $ai->generateLernmodul($full_prompt);
    }

    private function renderQuiz(string $markdown_content): string
    {
        $lines = explode("\n", $markdown_content);
        $html = "<div id='quiz-wrapper' style='padding: 20px;'>";

        $question_num = 0;
        $in_question = false;

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            if (str_ends_with($line, '?')) {
                // Close previous question if exists
                if ($in_question) {
                    $html .= "</div></div>";
                }
                
                $question_num++;
                $html .= "<div class='question' style='margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 4px; background-color: #f9f9f9;'>";
                // SECURITY: Escape question text
                $html .= "<h4 style='margin: 0 0 15px 0; color: #333;'>" . 
                         ilMarkdownLernmodulXSSProtection::escapeHTML($line) . "</h4>";
                $html .= "<div class='options' style='margin-left: 10px;'>";
                $in_question = true;
            } elseif (str_starts_with($line, '-')) {
                if ($in_question) {
                    $is_correct = str_contains($line, '[x]');
                    $answer_text = trim(str_replace(['- [x]', '- [ ]', '-'], '', $line));

                    // SECURITY: Create safe data attribute
                    $correct_attr = $is_correct ? "data-correct='true'" : "data-correct='false'";
                    $safe_name = ilMarkdownLernmodulXSSProtection::createSafeDataAttribute("q_{$question_num}");
                    
                    $html .= "<label style='display: block; margin: 8px 0; padding: 5px; cursor: pointer; border-radius: 3px;'>";
                    $html .= "<input type='radio' name='{$safe_name}' {$correct_attr} style='margin-right: 8px;'>";
                    // SECURITY: Escape answer text
                    $html .= ilMarkdownLernmodulXSSProtection::escapeHTML($answer_text);
                    $html .= "</label>";
                }
            }
        }

        // Close last question
        if ($in_question) {
            $html .= "</div></div>";
        }

        $html .= "<div style='margin-top: 20px;'>";
        $html .= "<button type='button' class='btn btn-primary' onclick='checkQuiz()' style='margin-right: 10px;'>Check Answers</button>";
        $html .= "<button type='button' class='btn btn-secondary' onclick='resetQuiz()'>Reset</button>";
        $html .= "</div>";
        $html .= $this->getCheckQuizScript();
        $html .= "</div>";

        return $html;
    }

    private function getCheckQuizScript(): string
    {
        return <<<'JS'
<script>
function checkQuiz() {
    const questions = document.querySelectorAll('.question');
    let correct = 0;
    let total = 0;

    questions.forEach(question => {
        const radios = question.querySelectorAll('input[type="radio"]');
        total++;

        let question_correct = false;
        radios.forEach(radio => {
            if (radio.checked && radio.getAttribute('data-correct') === 'true') {
                question_correct = true;
            }
            radio.parentElement.style.backgroundColor = 'transparent';
        });

        if (question_correct) {
            correct++;
            radios.forEach(radio => {
                if (radio.getAttribute('data-correct') === 'true') {
                    radio.parentElement.style.backgroundColor = '#dff0d8';
                }
            });
        } else {
            radios.forEach(radio => {
                if (radio.getAttribute('data-correct') === 'true') {
                    radio.parentElement.style.backgroundColor = '#dff0d8';
                } else if (radio.checked) {
                    radio.parentElement.style.backgroundColor = '#f2dede';
                }
            });
        }
    });

    const percentage = total > 0 ? Math.round(correct / total * 100) : 0;
    alert('Score: ' + correct + '/' + total + ' (' + percentage + '%)');
}

function resetQuiz() {
    const radios = document.querySelectorAll('input[type="radio"]');
    radios.forEach(radio => {
        radio.checked = false;
        radio.parentElement.style.backgroundColor = 'transparent';
    });
}
</script>
JS;
    }

    /**
     * Import pages from markdown paste
     */
    public function importMarkdown(): void
    {
        global $DIC;
        
        $this->checkPermission("write");
        $this->tabs->activateTab("flashcards");
        
        $form_action = $this->ctrl->getFormAction($this, 'importMarkdown');
        
        $example = "## Title\nIntroduction to the Topic\n\n## Content\nThis is the content of the first page. You can use **markdown** formatting.\n\n---\n\n## Title\nSecond Page\n\n## Content\nContent for the second page goes here.";
        
        $markdown_field = $this->factory->input()->field()->textarea(
            "Markdown Content",
            "Paste your markdown content here. Each page must have '## Title' and '## Content' sections, separated by '---'"
        )->withValue("")->withRequired(true);
        
        $form = $this->factory->input()->container()->form()->standard(
            $form_action,
            ['markdown' => $markdown_field]
        );
        
        if ($this->request->getMethod() === "POST") {
            $form = $form->withRequest($this->request);
            $data = $form->getData();
            
            if ($data !== null) {
                try {
                    require_once __DIR__ . '/security/class.ilMarkdownLernmodulResponseValidator.php';
                    $pages = \security\ilMarkdownLernmodulResponseValidator::validateLernmodulFormat($data['markdown']);
                    
                    // Get max sort_order and page_number
                    $db = $DIC->database();
                    $obj_id = $this->object->getId();
                    $query = "SELECT MAX(sort_order) as max_order, MAX(page_number) as max_page FROM rep_robj_xmdl_pages WHERE module_id = " . $db->quote($obj_id, 'integer');
                    $result = $db->query($query);
                    $row = $db->fetchAssoc($result);
                    $sort_order = ((int)$row['max_order']) + 1;
                    $page_number = ((int)$row['max_page']) + 1;
                    
                    // Insert each page
                    foreach ($pages as $page) {
                        $next_id = $db->nextId('rep_robj_xmdl_pages');
                        $db->insert('rep_robj_xmdl_pages', [
                            'id' => ['integer', $next_id],
                            'module_id' => ['integer', $obj_id],
                            'title' => ['text', $page['title']],
                            'content' => ['text', $page['content']],
                            'page_number' => ['integer', $page_number++],
                            'sort_order' => ['integer', $sort_order++]
                        ]);
                    }
                    
                    $this->tpl->setOnScreenMessage('success', count($pages) . ' pages imported successfully!');
                    $this->ctrl->redirect($this, 'flashcards');
                    return;
                } catch (\Exception $e) {
                    $this->tpl->setOnScreenMessage('failure', 'Import failed: ' . $e->getMessage());
                }
            }
        }
        
        $html = "<div class='alert alert-info'>
                    <h4>Format Example</h4>
                    <p>Your markdown should follow this format:</p>
                    <pre style='background: #f5f5f5; padding: 10px; border-radius: 4px;'>" . htmlspecialchars($example) . "</pre>
                </div>";
        
        $combined = $this->factory->legacy($html);
        $this->tpl->setContent($this->renderer->render([$combined, $form]));
    }

    /**
     * Add new flashcard form
     */
    public function addLernmodul(): void
    {
        $this->checkPermission("write");
        $this->tabs->activateTab("flashcards");
        
        $form = $this->buildLernmodulForm();
        $this->tpl->setContent($this->renderer->render($form));
    }

    /**
     * Save new flashcard
     */
    public function saveLernmodul(): void
    {
        global $DIC;
        $this->checkPermission("write");
        
        $form = $this->buildLernmodulForm();
        
        if ($this->request->getMethod() === "POST") {
            $form = $form->withRequest($this->request);
            $data = $form->getData();
            
            if ($data !== null) {
                $db = $DIC->database();
                $obj_id = $this->object->getId();
                
                // Get max sort_order and page_number
                $query = "SELECT MAX(sort_order) as max_order, MAX(page_number) as max_page FROM rep_robj_xmdl_pages WHERE module_id = " . $db->quote($obj_id, 'integer');
                $result = $db->query($query);
                $row = $db->fetchAssoc($result);
                $sort_order = ((int)$row['max_order']) + 1;
                $page_number = $data['page_number'] ?? (((int)$row['max_page']) + 1);
                
                // Insert new page
                $next_id = $db->nextId('rep_robj_xmdl_pages');
                $db->insert('rep_robj_xmdl_pages', [
                    'id' => ['integer', $next_id],
                    'module_id' => ['integer', $obj_id],
                    'title' => ['text', $data['title']],
                    'content' => ['text', $data['content']],
                    'page_number' => ['integer', $page_number],
                    'sort_order' => ['integer', $sort_order]
                ]);
                
                $this->tpl->setOnScreenMessage('success', 'Lernmodul added successfully');
                $DIC->ctrl()->redirect($this, "flashcards");
            }
        }
        
        $this->tabs->activateTab("flashcards");
        $this->tpl->setContent($this->renderer->render($form));
    }

    /**
     * Edit flashcard form
     */
    public function editLernmodul(): void
    {
        global $DIC;
        $this->checkPermission("write");
        $this->tabs->activateTab("flashcards");
        
        $card_id = (int)($_GET['card_id'] ?? 0);
        if ($card_id === 0) {
            $this->tpl->setOnScreenMessage('failure', 'Invalid flashcard ID');
            $DIC->ctrl()->redirect($this, "flashcards");
        }
        
        // Fetch card data
        $db = $DIC->database();
        $query = "SELECT * FROM rep_robj_xmdl_pages WHERE id = " . $db->quote($card_id, 'integer') . 
                 " AND module_id = " . $db->quote($this->object->getId(), 'integer');
        $result = $db->query($query);
        $card = $db->fetchAssoc($result);
        
        if (!$card) {
            $this->tpl->setOnScreenMessage('failure', 'Lernmodul not found');
            $DIC->ctrl()->redirect($this, "flashcards");
        }
        
        $form = $this->buildLernmodulForm($card);
        $this->tpl->setContent($this->renderer->render($form));
    }

    /**
     * Update flashcard
     */
    public function updateLernmodul(): void
    {
        global $DIC;
        $this->checkPermission("write");
        
        $card_id = (int)($_GET['card_id'] ?? 0);
        $form = $this->buildLernmodulForm();
        
        if ($this->request->getMethod() === "POST") {
            $form = $form->withRequest($this->request);
            $data = $form->getData();
            
            if ($data !== null && $card_id > 0) {
                $db = $DIC->database();
                
                $db->update('rep_robj_xmdl_pages', 
                    [
                        'title' => ['text', $data['title']],
                        'content' => ['text', $data['content']],
                        'page_number' => ['integer', $data['page_number'] ?? 1]
                    ],
                    [
                        'id' => ['integer', $card_id],
                        'module_id' => ['integer', $this->object->getId()]
                    ]
                );
                
                $this->tpl->setOnScreenMessage('success', 'Lernmodul updated successfully');
                $DIC->ctrl()->redirect($this, "flashcards");
            }
        }
        
        $this->tabs->activateTab("flashcards");
        $this->tpl->setContent($this->renderer->render($form));
    }

    /**
     * Delete flashcard
     */
    public function deleteLernmodul(): void
    {
        global $DIC;
        $this->checkPermission("write");
        
        $page_id = (int)($_GET['card_id'] ?? 0);
        if ($page_id === 0) {
            $this->tpl->setOnScreenMessage('failure', 'Invalid page ID');
            $DIC->ctrl()->redirect($this, "view");
        }
        
        $db = $DIC->database();
        
        // Delete page
        $db->manipulate("DELETE FROM rep_robj_xmdl_pages 
                        WHERE id = " . $db->quote($page_id, 'integer') . "
                        AND module_id = " . $db->quote($this->object->getId(), 'integer'));
        
        // Update progress records to remove this page from completed_pages JSON
        $result = $db->queryF(
            "SELECT user_id, completed_pages FROM rep_robj_xmdl_progress 
             WHERE module_id = %s",
            ['integer'],
            [$this->object->getId()]
        );
        
        while ($row = $db->fetchAssoc($result)) {
            $completed_pages = json_decode($row['completed_pages'] ?? '[]', true);
            if (($key = array_search($page_id, $completed_pages)) !== false) {
                unset($completed_pages[$key]);
                $completed_pages = array_values($completed_pages); // Re-index array
                
                $db->update('rep_robj_xmdl_progress', 
                    ['completed_pages' => ['text', json_encode($completed_pages)]],
                    [
                        'user_id' => ['integer', $row['user_id']],
                        'module_id' => ['integer', $this->object->getId()]
                    ]
                );
            }
        }
        
        $this->tpl->setOnScreenMessage('success', 'Page deleted successfully');
        $DIC->ctrl()->redirect($this, "view");
    }

    /**
     * Build flashcard add/edit form
     */
    private function buildLernmodulForm(array $card = null): \ILIAS\UI\Component\Input\Container\Form\Form
    {
        global $DIC;
        
        $is_edit = ($card !== null);
        $form_action = $is_edit 
            ? $DIC->ctrl()->getLinkTarget($this, 'updateLernmodul') . "&card_id=" . $card['id']
            : $DIC->ctrl()->getLinkTarget($this, 'saveLernmodul');
        
        $title_field = $this->factory->input()->field()->text("Page Title")
            ->withValue($card['title'] ?? '')
            ->withRequired(true);
        
        $content_field = $this->factory->input()->field()->textarea("Page Content (Markdown)")
            ->withValue($card['content'] ?? '')
            ->withRequired(true);
        
        $page_number_field = $this->factory->input()->field()->numeric("Page Number", "Leave empty for auto-increment")
            ->withValue($card['page_number'] ?? null);
        
        return $this->factory->input()->container()->form()->standard(
            $form_action,
            [
                'title' => $title_field,
                'content' => $content_field,
                'page_number' => $page_number_field
            ]
        );
    }
    
    /**
     * Rate a flashcard (AJAX endpoint)
     * Updates Leitner box and schedules next review
     */
    public function rateCard(): void
    {
        global $DIC;
        $db = $DIC->database();
        $user_id = $DIC->user()->getId();
        
        $card_id = (int)($_POST['card_id'] ?? 0);
        $rating = $_POST['rating'] ?? ''; // 'easy', 'medium', 'hard'
        
        if ($card_id === 0 || !in_array($rating, ['easy', 'medium', 'hard'])) {
            echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
            exit;
        }
        
        // Get current card box
        $query = "SELECT box_number FROM rep_robj_xmdl_pages WHERE id = " . $db->quote($card_id, 'integer');
        $result = $db->query($query);
        $card = $db->fetchAssoc($result);
        
        if (!$card) {
            echo json_encode(['success' => false, 'error' => 'Card not found']);
            exit;
        }
        
        $current_box = (int)$card['box_number'];
        $new_box = $this->calculateNewBox($current_box, $rating);
        $next_review = $this->calculateNextReviewDate($new_box);
        
        // Update card box
        $db->update('rep_robj_xmdl_pages',
            ['box_number' => ['integer', $new_box]],
            ['id' => ['integer', $card_id]]
        );
        
        // Update or insert user progress
        $query = "SELECT user_id FROM rep_robj_xmdl_progress 
                  WHERE user_id = " . $db->quote($user_id, 'integer') . "
                  AND card_id = " . $db->quote($card_id, 'integer');
        $result = $db->query($query);
        
        if ($db->fetchAssoc($result)) {
            // Update existing progress
            $db->manipulate("UPDATE rep_robj_xmdl_progress SET
                last_review_date = " . $db->now() . ",
                next_review_date = " . $db->quote($next_review, 'timestamp') . ",
                success_count = success_count + " . ($rating === 'hard' ? '0' : '1') . "
                WHERE user_id = " . $db->quote($user_id, 'integer') . "
                AND card_id = " . $db->quote($card_id, 'integer'));
        } else {
            // Insert new progress
            $db->insert('rep_robj_xmdl_progress', [
                'user_id' => ['integer', $user_id],
                'card_id' => ['integer', $card_id],
                'last_review_date' => ['timestamp', date('Y-m-d H:i:s')],
                'next_review_date' => ['timestamp', $next_review],
                'success_count' => ['integer', $rating === 'hard' ? 0 : 1]
            ]);
        }
        
        echo json_encode([
            'success' => true,
            'new_box' => $new_box,
            'next_review' => $next_review
        ]);
        exit;
    }
    
    /**
     * Calculate new Leitner box based on rating
     * 
     * @param int $current_box Current box (1-5)
     * @param string $rating User rating ('easy', 'medium', 'hard')
     * @return int New box number (1-5)
     */
    private function calculateNewBox(int $current_box, string $rating): int
    {
        switch ($rating) {
            case 'easy':
                // Move to next box (max 5)
                return min(5, $current_box + 1);
                
            case 'medium':
                // Stay in same box
                return $current_box;
                
            case 'hard':
                // Move back to box 1
                return 1;
                
            default:
                return $current_box;
        }
    }
    
    /**
     * Calculate next review date based on Leitner box
     * 
     * Box intervals:
     * - Box 1: 1 day
     * - Box 2: 2 days
     * - Box 3: 4 days
     * - Box 4: 8 days
     * - Box 5: 16 days
     * 
     * @param int $box Box number (1-5)
     * @return string Next review date (Y-m-d H:i:s)
     */
    private function calculateNextReviewDate(int $box): string
    {
        $days = pow(2, $box - 1); // 2^0=1, 2^1=2, 2^2=4, 2^3=8, 2^4=16
        return date('Y-m-d H:i:s', strtotime("+{$days} days"));
    }
}
