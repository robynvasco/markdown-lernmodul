# ILIAS MarkdownFlashcards Plugin

An intelligent flashcard plugin for ILIAS that automatically generates flashcards from Markdown documents and other file formats using AI.

## 🎯 Features

- **AI-powered Question Generation**: Automatic creation of flashcards from uploaded documents
- **Multi-Format Support**: Markdown (.md), Text (.txt), PDF, PowerPoint (.ppt, .pptx), Word (.docx)
- **Multiple AI Providers**: 
  - OpenAI (GPT-4, GPT-4-turbo, GPT-3.5-turbo)
  - Google Gemini (gemini-pro, gemini-1.5-pro)
  - GWDG (for German universities)
- **Online/Offline Management**: Flexible visibility control for flashcard objects
- **Instant Feedback**: Direct visual feedback on correct and incorrect answers
- **Modern UI**: Integration with ILIAS UI Framework for intuitive operation
- **Multilingual**: Fully supported in German and English
- **Comprehensive Security**:
  - AES-256-GCM encryption for API keys
  - Rate limiting to protect against abuse
  - XSS protection for all user inputs
  - HMAC signing for answer validation

## 📋 Requirements

- **ILIAS**: Version 10 or higher
- **PHP**: Version 8.2 or higher
- **MySQL/MariaDB**: Version 5.7+ / 10.2+
- **PHP Extensions**:
  - `openssl` (for encryption)
  - `curl` (for API requests)
  - `json` (for data processing)
  - `mbstring` (for text processing)

## 🚀 Installation

1. Create subdirectories, if necessary for `public/Customizing/global/plugins/Services/Repository/RepositoryObject/`
2. Navigate to `public/Customizing/global/plugins/Services/Repository/RepositoryObject/`
3. Execute:

```bash
git clone https://github.com/robynvasco/ilias-markdown-flashcards.git ./MarkdownFlashcards
```

4. In ILIAS, navigate to **Administration → Plugins**
5. Find the **MarkdownFlashcards** plugin
6. Click **Update** and then **Activate**

### Configure Plugin

1. Go to **Administration → Plugins → MarkdownFlashcards → Configuration**
2. Select your preferred **AI Service**:
   - **OpenAI**: Enter API key from [platform.openai.com](https://platform.openai.com)
   - **Google Gemini**: Enter API key from [ai.google.dev](https://ai.google.dev)
   - **GWDG**: Obtain credentials from your institution
3. Save the configuration

## 📖 Usage

### For Instructors/Trainers

#### Create flashcard

1. Navigate to your desired course or repository
2. Click **Add New Object → MarkdownFlashcards**
3. Enter a **Title** and optionally a **Description**
4. Click **Create flashcard**

#### Generate Questions

1. Open your MarkdownFlashcards object
2. Upload a file (supported formats: .md, .txt, .pdf, .ppt, .pptx, .docx)
3. Click **Generate Questions**
4. Wait while the AI creates the cards (may take 10-30 seconds)
5. Review the generated cards

#### Adjust Settings

- **Online/Offline**: Control visibility for learners
  - **Online**: flashcard is visible to all participants
  - **Offline**: flashcard is only visible to administrators/trainers
- **Title & Description**: Edit the flashcard metadata

### For Learners

1. Open the MarkdownFlashcards object in the course
2. Read the card carefully
3. Select one or more answers (depending on card type)
4. Receive instant feedback:
   - ✅ **Green**: Correct answer
   - ❌ **Red**: Incorrect answer

## 🔒 Security Features

The plugin implements multi-layered security measures:

### API Key Encryption
- **AES-256-GCM**: Military-grade encryption for stored API keys
- **Unique Key**: Individually generated per ILIAS installation
- **Secure Storage**: Encrypted keys in `ilias.ini.php`

### Rate Limiting
- **Session-based**: Protection against automated requests
- **Configurable Limits**: Default 5 requests per 60 seconds
- **User-friendly**: Clear error messages when exceeded

### Input Validation
- **XSS Protection**: All user inputs are filtered
- **Type Safety**: Strict PHP typing in all classes
- **SQL Injection Protection**: Use of prepared statements

### Answer Validation
- **HMAC Signing**: Tamper protection for flashcard answers
- **Session Validation**: Protection against CSRF attacks

## 🏗️ Architecture

The plugin follows the ILIAS Repository Object Pattern:

```
MarkdownFlashcards/
├── classes/
│   ├── class.ilObjMarkdownFlashcards.php          # Data model
│   ├── class.ilObjMarkdownFlashcardsGUI.php       # UI Controller
│   ├── class.ilObjMarkdownFlashcardsAccess.php    # Access control
│   ├── class.ilObjMarkdownFlashcardsListGUI.php   # List view
│   ├── class.ilMarkdownFlashcardsPlugin.php       # Plugin entry point
│   └── AI/
│       ├── ilMarkdownFlashcardsAIService.php      # AI base service
│       ├── ilMarkdownFlashcardsOpenAIService.php  # OpenAI integration
│       ├── ilMarkdownFlashcardsGeminiService.php  # Gemini integration
│       └── ilMarkdownFlashcardsGWDGService.php    # GWDG integration
├── lang/                                     # Language files (de/en)
├── sql/                                      # Database setup
├── templates/                                # UI templates
├── docs/                                     # Extended documentation
└── test/                                     # Unit tests
```

Detailed architecture documentation can be found in [CODE_STRUCTURE.md](CODE_STRUCTURE.md).

## 🧪 Testing

```bash
# Run unit tests
cd test/
php run_tests.php

# Run specific tests
php run_tests.php --filter testflashcardGeneration
```

## 🔧 Configuration

### Global Settings (Administration)

| Setting | Description | Default |
|---------|-------------|---------|
| **AI Service** | Which provider to use | OpenAI |
| **API Key** | Encrypted access key | - |
| **Model** | Specific AI model (e.g. gpt-4) | gpt-4 |
| **Rate Limit** | Max requests per time window | 5/60s |

### Object Settings (per flashcard)

| Setting | Description | Default |
|---------|-------------|---------|
| **Online** | Visibility for learners | Online |
| **Title** | Name of the flashcard object | - |
| **Description** | Detailed explanation | - |

## 🐛 Troubleshooting

### "Rate limit exceeded"
- **Cause**: Too many requests in a short time
- **Solution**: Wait 60 seconds and try again

### "API key not configured"
- **Cause**: No valid API key configured
- **Solution**: Go to Administration → Plugins → MarkdownFlashcards → Configuration

### "Failed to generate cards"
- **Cause**: AI service unreachable or file too large
- **Solution**: 
  - Check your internet connection
  - Reduce file size (recommended: < 5 MB)
  - Try a different file format

### flashcard shows no cards
- **Cause**: Generation not yet completed or failed
- **Solution**: 
  - Check ILIAS logs under `data/logs/`
  - Regenerate cards with "Generate Questions"

## 📄 License

This plugin is licensed under the **GNU General Public License v3.0**.

See [LICENSE](LICENSE) for details.

## 👤 Author

**Robyn Vasco**
- GitHub: [@robynvasco](https://github.com/robynvasco)

## 📝 Changelog

### Version 1.0.0 (January 2026)
- ✨ Initial release
- ✨ Multi-format support (MD, TXT, PDF, PPT, DOCX)
- ✨ Three AI providers (OpenAI, Gemini, GWDG)
- ✨ Online/Offline management
- ✨ Comprehensive security features
- ✨ Multilingual support (DE/EN)

## 🔮 Roadmap

- [ ] Export/Import of flashcard cards
- [ ] Advanced card types (free text, matching)
- [ ] flashcard statistics and analytics
- [ ] Question pool and reusability
- [ ] Integration with ILIAS Test & Assessment
- [ ] Support for additional AI providers

## 📞 Support

For cards or issues:
1. Check the [CODE_STRUCTURE.md](CODE_STRUCTURE.md) documentation
2. Search the [Issues](https://github.com/robynvasco/ilias-markdown-flashcards/issues)
3. Create a new issue with detailed description

---

**Made with ❤️ for the ILIAS Community**

