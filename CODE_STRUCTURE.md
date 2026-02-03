# MarkdownFlashcards Plugin - Code Structure

## Overview

This document provides a comprehensive overview of the MarkdownFlashcards plugin's code structure, architecture, and key components. It serves as a guide for developers who want to understand, maintain, or extend the plugin.

## Directory Structure

```
MarkdownFlashcards/
├── classes/                          # Main plugin classes
│   ├── class.ilObjMarkdownFlashcards.php           # Data model
│   ├── class.ilObjMarkdownFlashcardsGUI.php         # Main controller/UI
│   ├── class.ilObjMarkdownFlashcardsAccess.php      # Access control
│   ├── class.ilObjMarkdownFlashcardsListGUI.php     # Repository list view
│   ├── class.ilMarkdownFlashcardsPlugin.php         # Plugin definition
│   ├── class.ilObjMarkdownFlashcardsUploadHandler.php  # File uploads
│   ├── class.ilObjMarkdownFlashcardsStakeholder.php    # Background tasks
│   │
│   ├── ai/                          # AI integration classes
│   │   ├── class.ilMarkdownFlashcardsLLM.php        # Base AI interface
│   │   ├── class.ilMarkdownFlashcardsOpenAI.php     # OpenAI ChatGPT
│   │   ├── class.ilMarkdownFlashcardsGoogleAI.php   # Google Gemini
│   │   └── class.ilMarkdownFlashcardsGWDG.php       # GWDG Academic Cloud
│   │
│   ├── platform/                    # Platform utilities
│   │   ├── class.ilMarkdownFlashcardsConfig.php        # Configuration storage
│   │   ├── class.ilMarkdownFlashcardsEncryption.php    # AES-256 encryption
│   │   ├── class.ilMarkdownFlashcardsException.php     # Custom exceptions
│   │   ├── class.ilMarkdownFlashcardsFileSecurity.php  # File validation
│   │   ├── class.ilMarkdownFlashcardsRateLimiter.php   # Rate limiting
│   │   └── class.ilMarkdownFlashcardsXSSProtection.php # XSS prevention
│   │
│   └── security/                    # API security layer
│       ├── class.ilMarkdownFlashcardsCircuitBreaker.php      # Failure protection
│       ├── class.ilMarkdownFlashcardsResponseValidator.php   # Response validation
│       ├── class.ilMarkdownFlashcardsRequestSigner.php       # HMAC signing
│       └── class.ilMarkdownFlashcardsCertificatePinner.php   # SSL/TLS pinning
│
├── sql/                             # Database schemas
│   ├── dbupdate.php                           # Installation/update scripts
│   └── README.md                              # Database documentation
│
├── templates/                       # Frontend templates
│   └── default/
│       └── tpl.flashcard_view.html                # Flashcard display template
│
├── docs/                            # Documentation
│   └── SECURITY_OVERVIEW.md                  # Security documentation
│
├── test/                            # Test suites
│   ├── test_rate_limiter.php
│   ├── test_input_validation.php
│   └── test_api_security.php
│
└── lang/                            # Language files
    └── ilias_de.lang                         # German translations
```

## Core Classes

### 1. ilObjMarkdownFlashcards (Data Model)

**Location**: `classes/class.ilObjMarkdownFlashcards.php`

**Purpose**: Represents a flashcard object in the ILIAS repository.

**Key Properties**:
- `$online` - Online/offline status (visibility control)
- `$md_content` - Flashcard content in markdown format
- `$last_prompt`, `$last_difficulty`, etc. - Last used generation parameters

**Key Methods**:
- `doRead()` - Load data from database
- `doUpdate()` - Save data to database
- `doDelete()` - Remove data from database
- Getters/Setters for all properties

**Database Table**: `rep_robj_xfcd_data`

**Security Features**:
- SQL injection prevention via explicit type casting
- Backwards compatibility checks for column existence

---

### 2. ilObjMarkdownFlashcardsGUI (Controller/UI)

**Location**: `classes/class.ilObjMarkdownFlashcardsGUI.php`

**Purpose**: Main controller handling all user interactions and UI rendering.

**Key Commands**:
- `view()` - Display flashcard to users (with markdown rendering)
- `settings()` - Edit flashcard settings (title, online, content)
- `generate()` - AI flashcard generation interface
- `submitGenerate()` - Process AI generation requests

**UI Components**:
- Uses ILIAS UI Framework (Factory/Renderer pattern)
- Form fields with inline save via transformations
- Markdown rendering with syntax highlighting
- Interactive flashcard features (expand/collapse, copy answers)

**Security Features**:
- Content Security Policy headers
- XSS protection via HTML escaping
- Rate limiting enforcement
- Input validation (length, format)
- File type whitelisting

**Key Dependencies**:
- `Factory`, `Renderer` - ILIAS UI components
- `ilMarkdownFlashcardsRateLimiter` - Rate limit enforcement
- `ilMarkdownFlashcardsXSSProtection` - XSS prevention
- AI classes (`ilMarkdownFlashcardsOpenAI`, `ilMarkdownFlashcardsGoogleAI`, `ilMarkdownFlashcardsGWDG`)

---

### 3. ilObjMarkdownFlashcardsAccess (Access Control)

**Location**: `classes/class.ilObjMarkdownFlashcardsAccess.php`

**Purpose**: Controls who can see and access flashcard objects.

**Key Methods**:
- `_checkAccess()` - Main access control logic
- `_isOffline()` - Check if flashcard is offline
- `_checkGoto()` - Validate goto links

**Access Logic**:
1. Users with `write` permission (admins) can always access
2. For `read`/`visible` permissions, check online status
3. Offline flashcardzes are hidden from regular users

**Integration**:
- Called automatically by ILIAS access control system
- Used in repository lists and permission checks

---

### 4. ilObjMarkdownFlashcardsListGUI (List View)

**Location**: `classes/class.ilObjMarkdownFlashcardsListGUI.php`

**Purpose**: Controls how flashcardzes appear in repository lists.

**Features**:
- Defines available commands (view, settings)
- Shows status badges (e.g., "Offline" alert)
- Custom properties display

---

### 5. ilMarkdownFlashcardsPlugin (Plugin Definition)

**Location**: `classes/class.ilMarkdownFlashcardsPlugin.php`

**Purpose**: Main plugin class defining identity and lifecycle.

**Key Features**:
- Plugin constants (ID: "xfcd", Name: "MarkdownFlashcards")
- Activation/deactivation handling
- Update hook with API key migration
- Uninstall cleanup (removes all data)
- Enables copy functionality

---

## AI Integration Layer

### Architecture

The plugin supports three AI providers through a common interface:

```
ilMarkdownFlashcardsLLM (Abstract Base)
    ├── ilMarkdownFlashcardsOpenAI      (ChatGPT GPT-4/3.5)
    ├── ilMarkdownFlashcardsGoogleAI    (Gemini Pro)
    └── ilMarkdownFlashcardsGWDG        (Academic Cloud)
```

### Base Interface (ilMarkdownFlashcardsLLM)

**Location**: `classes/ai/class.ilMarkdownFlashcardsLLM.php`

**Abstract Methods**:
- `generateFlashcard()` - Generate flashcard from prompt and parameters
- `getServiceName()` - Get provider name

### OpenAI Implementation

**Location**: `classes/ai/class.ilMarkdownFlashcardsOpenAI.php`

**API**: OpenAI Chat Completions API
**Models**: GPT-4, GPT-4 Turbo, GPT-3.5 Turbo
**Endpoint**: `https://api.openai.com/v1/chat/completions`

**Features**:
- Supports multiple models
- JSON response mode
- Circuit breaker integration
- HMAC request signing

### Google AI Implementation

**Location**: `classes/ai/class.ilMarkdownFlashcardsGoogleAI.php`

**API**: Google Generative AI API
**Model**: Gemini Pro
**Endpoint**: `https://generativelanguage.googleapis.com/v1beta/models/gemini-pro:generateContent`

**Features**:
- Free tier support
- Circuit breaker integration
- HMAC request signing

### GWDG Implementation

**Location**: `classes/ai/class.ilMarkdownFlashcardsGWDG.php`

**API**: GWDG Academic Cloud (vLLM-compatible)
**Endpoint**: Configurable
**Models**: Various academic models

**Features**:
- Academic institution support
- Circuit breaker integration
- HMAC request signing

---

## Security Layer

### 1. Rate Limiting

**Class**: `ilMarkdownFlashcardsRateLimiter`
**Location**: `classes/platform/class.ilMarkdownFlashcardsRateLimiter.php`

**Limits**:
- API calls: 20/hour per user session
- File processing: 20/hour per user session
- Flashcard generation cooldown: 5 seconds
- Concurrent requests: 3 maximum

**Storage**: PHP session (no database)

**Methods**:
- `recordApiCall()` - Track API usage
- `recordFileProcessing()` - Track file reads
- `recordFlashcardGeneration()` - Enforce cooldown
- `incrementConcurrent()` / `decrementConcurrent()` - Track concurrent operations

---

### 2. Circuit Breaker

**Class**: `ilMarkdownFlashcardsCircuitBreaker`
**Location**: `classes/security/class.ilMarkdownFlashcardsCircuitBreaker.php`

**Purpose**: Prevent cascading failures from failing AI services

**States**:
- `CLOSED` - Normal operation
- `OPEN` - Service blocked after failures
- `HALF_OPEN` - Testing recovery

**Configuration**:
- Failure threshold: 5 consecutive failures
- Timeout: 60 seconds before retry
- Success threshold: 2 successes to close circuit

**Methods**:
- `checkAvailability()` - Check if service is available
- `recordSuccess()` - Record successful call
- `recordFailure()` - Record failed call

---

### 3. XSS Protection

**Class**: `ilMarkdownFlashcardsXSSProtection`
**Location**: `classes/platform/class.ilMarkdownFlashcardsXSSProtection.php`

**Features**:
- Content Security Policy headers
- HTML escaping for all user input
- Markdown sanitization
- Script tag blocking
- Event handler blocking

**Methods**:
- `setCSPHeaders()` - Set security headers
- `escapeHTML()` - Escape user input
- `protectContent()` - Sanitize markdown

---

### 4. Request Signing (HMAC)

**Class**: `ilMarkdownFlashcardsRequestSigner`
**Location**: `classes/security/class.ilMarkdownFlashcardsRequestSigner.php`

**Purpose**: Verify request authenticity and prevent tampering

**Algorithm**: HMAC-SHA256
**Format**: `Base64(timestamp:signature)`
**Replay Protection**: 5-minute timestamp window

**Methods**:
- `signRequest()` - Generate signature
- `verifySignature()` - Validate signature

---

### 5. Response Validation

**Class**: `ilMarkdownFlashcardsResponseValidator`
**Location**: `classes/security/class.ilMarkdownFlashcardsResponseValidator.php`

**Validates**:
- Response structure matches expected format
- Required fields present
- Content not empty
- No malicious patterns (scripts, SQL injection)
- Response size < 100KB

**Methods**:
- `validateOpenAIResponse()` - Validate OpenAI format
- `validateGoogleResponse()` - Validate Google format
- `validateMarkdownFlashcardsFormat()` - Validate flashcard structure

---

### 6. File Security

**Class**: `ilMarkdownFlashcardsFileSecurity`
**Location**: `classes/platform/class.ilMarkdownFlashcardsFileSecurity.php`

**Limits**:
- File size: 10 MB
- Uncompressed ZIP: 50 MB
- Compression ratio: 10:1 (ZIP bomb protection)
- Processing timeout: 30 seconds
- Extracted text: 5,000 characters

**Allowed Types**: txt, pdf, doc, docx, ppt, pptx

**Methods**:
- `validateFile()` - Check file type and size
- `extractText()` - Extract text from documents

---

## Platform Utilities

### 1. Configuration Management

**Class**: `ilMarkdownFlashcardsConfig`
**Location**: `classes/platform/class.ilMarkdownFlashcardsConfig.php`

**Purpose**: Centralized configuration storage

**Database Table**: `xfcd_config`

**Stored Values**:
- API keys (encrypted)
- Available services
- Model selections
- GWDG endpoints

**Methods**:
- `load()` - Load all config from database
- `get($key)` - Get config value
- `set($key, $value)` - Save config value

---

### 2. Encryption

**Class**: `ilMarkdownFlashcardsEncryption`
**Location**: `classes/platform/class.ilMarkdownFlashcardsEncryption.php`

**Algorithm**: AES-256-CBC
**Key Derivation**: PBKDF2 with ILIAS client salt

**Purpose**: Encrypt API keys at rest in database

**Methods**:
- `encrypt($data)` - Encrypt data
- `decrypt($data)` - Decrypt data
- `migrateApiKeys()` - Migrate plaintext to encrypted

---

## Database Schema

### Table: `rep_robj_xfcd_data`

**Purpose**: Stores flashcard content and metadata

| Column | Type | Description |
|--------|------|-------------|
| id | INTEGER | Object ID (primary key) |
| md_content | CLOB | Markdown flashcard content |
| is_online | INTEGER | Online status (0=offline, 1=online) |
| last_prompt | TEXT | Last used prompt (5000 chars) |
| last_difficulty | TEXT | Last used difficulty (50 chars) |
| last_card_count | INTEGER | Last used card count |
| last_context | TEXT | Last used context (10000 chars) |
| last_file_ref_id | INTEGER | Last used file reference ID |

### Table: `xfcd_config`

**Purpose**: Stores plugin configuration

| Column | Type | Description |
|--------|------|-------------|
| name | TEXT(250) | Configuration key |
| value | TEXT(4000) | Configuration value (may be encrypted) |

**Encrypted Values**:
- `openai_api_key`
- `google_api_key`
- `gwdg_api_key`

---

## Data Flow

### Flashcard Generation Flow

```
User fills form
    ↓
submitGenerate() - GUI validates input
    ↓
Rate Limiter - Check limits
    ↓
File extraction (if selected)
    ↓
Circuit Breaker - Check AI service availability
    ↓
Request Signer - Generate HMAC signature
    ↓
AI Provider (OpenAI/Google/GWDG) - Generate flashcard
    ↓
Response Validator - Check response format
    ↓
XSS Protection - Sanitize content
    ↓
Save to database
    ↓
Redirect to view
```

### Access Control Flow

```
User requests flashcard
    ↓
ILIAS checks permission (read/write)
    ↓
ilObjMarkdownFlashcardsAccess::_checkAccess()
    ↓
    ├─ Has write permission? → Allow access
    │
    └─ Check online status
        ↓
        ├─ Online? → Allow access
        └─ Offline? → Deny access (403)
```

---

## Testing

### Test Suites

1. **Rate Limiter Tests** (`test/test_rate_limiter.php`)
   - API call limits
   - File processing limits
   - Cooldown enforcement
   - Concurrent request limits

2. **Input Validation Tests** (`test/test_input_validation.php`)
   - Prompt length validation
   - Context length validation
   - Difficulty enum validation
   - Question count range
   - HTML escaping
   - Null byte sanitization

3. **API Security Tests** (`test/test_api_security.php`)
   - Circuit breaker functionality
   - Response validation
   - Request signing
   - Malicious content blocking

**Run Tests**:
```bash
docker exec ilias-dev-ilias-1 php /var/www/html/public/Customizing/global/plugins/Services/Repository/RepositoryObject/MarkdownFlashcards/test/test_rate_limiter.php
```

---

## Extension Points

### Adding a New AI Provider

1. Create class extending `ilMarkdownFlashcardsLLM`
2. Implement `generateFlashcard()` method
3. Add provider to configuration UI
4. Add to `generate()` method in GUI class

**Example**:
```php
class ilMarkdownFlashcardsNewProvider extends ilMarkdownFlashcardsLLM
{
    public function generateFlashcard(
        string $prompt,
        string $difficulty,
        int $card_count,
        string $additional_context = ""
    ): string {
        // Implementation
    }
    
    public function getServiceName(): string {
        return 'newprovider';
    }
}
```

### Adding New Security Checks

1. Create validation class in `classes/security/`
2. Add validation call in appropriate flow
3. Add tests in `test/`

### Customizing Flashcard Display

1. Modify `templates/default/tpl.flashcard_view.html`
2. Update CSS/JS in template
3. Adjust `renderFlashcard()` method in GUI class

---

## Dependencies

### ILIAS Framework
- `ilObjectPlugin` - Base object class
- `ilObjectPluginGUI` - Base GUI class
- `ilObjectPluginAccess` - Base access class
- UI Framework (Factory/Renderer)
- Database abstraction
- Access control system

### PHP Extensions
- `openssl` - Encryption and HMAC
- `curl` - API requests
- `json` - Data encoding/decoding
- `session` - Rate limiting storage

### External APIs
- OpenAI Chat Completions API
- Google Generative AI API
- GWDG Academic Cloud API

---

## Best Practices

### Code Style
- **Type Declarations**: Use strict types (`declare(strict_types=1);`)
- **Return Types**: Always specify return types
- **Comments**: PHPDoc blocks for all public methods
- **Naming**: Descriptive variable and method names

### Security
- **Never trust user input**: Validate and sanitize everything
- **Type casting**: Always cast SQL parameters
- **Escape output**: Use `htmlspecialchars()` for HTML output
- **Rate limiting**: Enforce limits on expensive operations
- **Error handling**: Catch exceptions, log errors, don't expose internals

### Database
- **Use ILIAS abstraction**: Never write raw SQL
- **Type casting**: `$db->quote((int)$id, 'integer')`
- **Null safety**: Always check for null/empty values
- **Backwards compatibility**: Check column existence before reading

### Performance
- **Lazy loading**: Load data only when needed
- **Caching**: Cache expensive operations (circuit breaker)
- **Timeouts**: Set reasonable timeouts for API calls
- **Chunking**: Process large files in chunks

---

## Troubleshooting

### Common Issues

**Issue**: Flashcard doesn't appear in repository
**Solution**: Check online status, verify access permissions

**Issue**: API generation fails
**Solution**: Check circuit breaker status, verify API key, check rate limits

**Issue**: File upload not working
**Solution**: Verify file type in whitelist, check file size limits

**Issue**: XSS content blocked
**Solution**: Content may contain dangerous patterns, review and sanitize

### Debug Mode

Enable error logging:
```php
error_log("MarkdownFlashcards: Debug message here");
```

Check logs:
```bash
docker exec ilias-dev-ilias-1 tail -f /var/log/apache2/error.log
```

### Reset Rate Limits

```php
ilMarkdownFlashcardsRateLimiter::resetAll();
```

---

## Contributing

When contributing code:

1. **Follow existing patterns**: Consistent code style
2. **Add tests**: Cover new functionality
3. **Document changes**: Update this file and inline comments
4. **Security review**: Consider security implications
5. **Backwards compatibility**: Don't break existing installations

---

## License

This plugin follows the ILIAS licensing model (GPL-3.0).

---

**Document Version**: 1.0  
**Last Updated**: January 30, 2026  
**Plugin Version**: Compatible with ILIAS 10+
