# MarkdownFlashcards Plugin - API Key Encryption Security

## Overview

The MarkdownFlashcards plugin now implements **AES-256-CBC encryption** for all API keys stored in the database. This addresses the critical security vulnerability of storing sensitive credentials in plain text.

## Implementation Details

### Encryption Method
- **Algorithm**: AES-256-CBC (Advanced Encryption Standard, 256-bit, Cipher Block Chaining)
- **Key Derivation**: PBKDF2 with SHA-256, 10,000 iterations
- **IV Generation**: Random 16-byte initialization vector for each encryption
- **Encoding**: Base64 encoding for database storage

### Encrypted Keys
The following configuration keys are automatically encrypted:
- `gwdg_api_key` - GWDG Academic Cloud API key
- `google_api_key` - Google Gemini API key
- `openai_api_key` - OpenAI ChatGPT API key

### Architecture

#### Files Created/Modified

**New Files:**
- `classes/platform/class.ilMarkdownFlashcardsEncryption.php` - Encryption service class
- `test/test_encryption.php` - Unit tests for encryption

**Modified Files:**
- `classes/platform/class.ilMarkdownFlashcardsConfig.php` - Added encryption/decryption
- `classes/class.ilMarkdownFlashcardsConfigGUI.php` - Changed text inputs to password inputs
- `classes/class.ilMarkdownFlashcardsPlugin.php` - Added migration on plugin update

#### Encryption Flow

```
User Input (API Key)
    ↓
Form Submission
    ↓
ilMarkdownFlashcardsConfig::set($key, $value)
    ↓
Detect if value is API key
    ↓
ilMarkdownFlashcardsEncryption::encrypt($value)
    ↓
  - Derive encryption key from CLIENT_ID + salt
  - Generate random 16-byte IV
  - Encrypt with AES-256-CBC
  - Prepend IV to ciphertext
  - Base64 encode
    ↓
Store encrypted value in xfcd_config table
```

#### Decryption Flow

```
Application needs API key
    ↓
ilMarkdownFlashcardsConfig::get($key)
    ↓
Detect if key is encrypted (API key field)
    ↓
ilMarkdownFlashcardsEncryption::decrypt($value)
    ↓
  - Base64 decode
  - Extract IV from first 16 bytes
  - Derive same encryption key
  - Decrypt with AES-256-CBC
    ↓
Return plain text API key to application
```

### Security Features

#### 1. **Strong Encryption**
- Uses AES-256-CBC, industry-standard symmetric encryption
- 256-bit key size provides strong protection
- CBC mode with random IV prevents pattern analysis

#### 2. **Unique IVs**
- Each encryption generates a new random IV
- Same API key encrypted twice produces different ciphertexts
- Prevents statistical analysis attacks

#### 3. **Key Derivation**
- Encryption key derived from ILIAS installation specifics
- Uses PBKDF2 with 10,000 iterations (slows brute force)
- Combines CLIENT_ID + password_salt for uniqueness

#### 4. **UI Protection**
- Password input fields hide API keys from shoulder surfing
- Keys displayed as dots (••••••) in browser
- Browser autocomplete can be disabled

#### 5. **Backward Compatibility**
- Detects if values are already encrypted
- Gracefully handles plain text keys (returns as-is)
- Auto-migration on plugin update

### Migration Process

When the plugin is updated, existing plain text API keys are automatically encrypted:

1. **Plugin Update Triggered**
   - Administrator clicks "Update" in plugin management

2. **afterUpdate() Hook Called**
   - `ilMarkdownFlashcardsPlugin::afterUpdate()` executes

3. **Migration Logic**
   - `ilMarkdownFlashcardsEncryption::migrateApiKeys()` runs
   - Loads all configuration keys
   - Checks each API key field
   - If not encrypted, encrypts and saves
   - Errors logged but don't fail update

4. **Verification**
   - Admin can test API connections
   - Keys work transparently (auto-decrypted)

### Testing

#### Running Tests

```bash
# From host machine
docker exec ilias-dev-ilias-1 php /var/www/html/public/Customizing/global/plugins/Services/Repository/RepositoryObject/MarkdownFlashcards/test/test_encryption.php
```

#### Test Coverage

The test suite verifies:
- ✓ Basic encryption/decryption correctness
- ✓ Empty value handling
- ✓ Plain text detection (backward compatibility)
- ✓ IV randomness (same input → different output)
- ✓ Long key support (360+ characters)

All tests must pass before deployment.

### Deployment Checklist

- [x] Encryption class implemented
- [x] Config class modified for auto-encryption
- [x] Password input fields in GUI
- [x] Migration logic in plugin class
- [x] Unit tests created and passing
- [ ] Manual testing in ILIAS UI
- [ ] Verify API calls still work
- [ ] Test plugin update process
- [ ] Document for end users

## Usage

### For Administrators

#### Initial Setup
1. Navigate to Administration → Extending ILIAS → Plugins → MarkdownFlashcards → Configure
2. Enter API keys in password fields (keys are automatically encrypted on save)
3. Keys are never visible after saving

#### After Plugin Update
- Existing plain text keys are automatically encrypted
- No manual action required
- Verify API connections still work

#### Verifying Encryption
Check the database directly:
```sql
SELECT name, value FROM xfcd_config WHERE name LIKE '%_api_key';
```
Values should look like: `xfMeSr2T/sMWAV+yQDmNvV7eh7S+...` (base64-encoded)

### For Developers

#### Adding New Encrypted Fields

1. **Update ENCRYPTED_KEYS constant**:
```php
// In class.ilMarkdownFlashcardsConfig.php
private const ENCRYPTED_KEYS = [
    'gwdg_api_key',
    'google_api_key',
    'openai_api_key',
    'new_service_api_key',  // Add here
];
```

2. **Update Migration Method**:
```php
// In class.ilMarkdownFlashcardsEncryption.php
public static function migrateApiKeys(): void
{
    $keysToMigrate = [
        'gwdg_api_key',
        'google_api_key',
        'openai_api_key',
        'new_service_api_key',  // Add here
    ];
    // ... migration logic
}
```

3. **Use Password Input in GUI**:
```php
$inputs[] = $this->factory->input()->field()->password(
    $this->plugin_object->txt("config_newservice_key_label"),
    $this->plugin_object->txt("config_newservice_key_info")
)->withValue(ilMarkdownFlashcardsConfig::get("new_service_api_key"))
  ->withAdditionalTransformation($this->refinery->custom()->transformation(
    function ($v) {
        ilMarkdownFlashcardsConfig::set('new_service_api_key', $v);
    }
))->withRequired(true);
```

#### Direct Encryption Usage

```php
use platform\ilMarkdownFlashcardsEncryption;

// Encrypt a value
$plainText = "my-secret-api-key";
$encrypted = ilMarkdownFlashcardsEncryption::encrypt($plainText);

// Decrypt a value
$decrypted = ilMarkdownFlashcardsEncryption::decrypt($encrypted);

// Check if encrypted
$isEncrypted = ilMarkdownFlashcardsEncryption::isEncrypted($someValue);
```

## Security Considerations

### Strengths
✓ API keys encrypted at rest in database
✓ Strong AES-256-CBC encryption
✓ Random IVs prevent ciphertext analysis
✓ Key derivation makes brute force harder
✓ Password fields hide keys in UI
✓ Automatic migration of existing keys

### Limitations
⚠️ Keys decrypted in memory during use (necessary for API calls)
⚠️ Encryption key derived from installation data (not external key management)
⚠️ No key rotation mechanism
⚠️ Keys may appear in logs if debug logging enabled
⚠️ PHP process memory could be dumped

### Additional Recommendations

#### 1. **Remove Debug Logging**
Ensure API keys never logged:
```php
// REMOVE ALL THESE:
error_log("API Key: " . $api_key);  // NEVER DO THIS
file_put_contents('/tmp/debug.log', $api_key);  // NEVER DO THIS
```

#### 2. **Environment Variables (Future Enhancement)**
Consider moving to environment variables:
- Stored outside web root
- Never in database
- Per-environment configuration
- Docker secrets integration

#### 3. **Key Rotation**
Implement periodic key rotation:
- Admin UI to update API keys
- Automatic re-encryption with new key
- Audit trail of key changes

#### 4. **Secrets Management (Enterprise)**
For high-security deployments:
- HashiCorp Vault integration
- AWS Secrets Manager
- Azure Key Vault
- Google Secret Manager

## Troubleshooting

### Keys Not Working After Update
**Symptom**: API calls fail after plugin update
**Solution**:
1. Check logs: `docker exec ilias-dev-ilias-1 cat /var/log/apache2/error.log`
2. Verify encryption: Run test script
3. Re-enter API keys in configuration
4. Test single API call

### Decryption Errors
**Symptom**: "Decryption failed" or corrupted keys
**Possible Causes**:
- Database encoding changed (e.g., UTF-8 → Latin1)
- Encryption key derivation changed
- Manual database edits

**Solution**:
1. Re-enter API keys in admin UI
2. Check CLIENT_ID is consistent
3. Verify password_salt in ilias.ini.php

### Migration Fails
**Symptom**: Plugin update completes but keys still plain text
**Solution**:
1. Manually trigger migration:
```php
require_once 'classes/platform/class.ilMarkdownFlashcardsEncryption.php';
use platform\ilMarkdownFlashcardsEncryption;
ilMarkdownFlashcardsEncryption::migrateApiKeys();
```
2. Check error logs for exceptions
3. Verify table structure unchanged

## References

- [OWASP Cryptographic Storage Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Cryptographic_Storage_Cheat_Sheet.html)
- [PHP OpenSSL Functions](https://www.php.net/manual/en/book.openssl.php)
- [AES-256-CBC Specification](https://csrc.nist.gov/publications/detail/fips/197/final)
- [PBKDF2 Key Derivation](https://tools.ietf.org/html/rfc2898)

## Version History

- **v0.0.5** (2026-01-29): API key encryption implemented
  - AES-256-CBC encryption for all API keys
  - Password input fields in configuration UI
  - Automatic migration on plugin update
  - Comprehensive test suite
