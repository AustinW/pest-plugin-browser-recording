# Pest Plugin Browser Recording

**Interactive browser test recording for Pest v4** - Record user actions and automatically generate clean, maintainable Pest test code.

[![Tests](https://github.com/AustinW/pest-plugin-browser-recording/actions/workflows/tests.yml/badge.svg)](https://github.com/AustinW/pest-plugin-browser-recording/actions/workflows/tests.yml)

## ✨ Overview

This plugin extends Pest's browser testing capabilities with **interactive recording**, allowing you to perform manual browser actions and automatically generate corresponding Pest test code. Perfect for:

-   **Rapid test creation** from manual testing workflows
-   **Complex user journey testing** with real browser interactions
-   **Cross-browser compatibility testing** with device emulation
-   **Accessibility testing** with ARIA-aware selector generation

## 🚀 Quick Start

### Installation

```bash
composer require pestphp/pest-plugin-browser-recording --dev
```

### Requirements

-   **PHP**: ^8.2
-   **Pest**: ^4.0
-   **Pest Browser Plugin**: ^4.0
-   **Playwright**: Installed via `php artisan pest:install-browser`

### Basic Usage

```php
it('can complete user registration', function() {
    $page = visit('/register')
        ->record([
            'timeout' => 3600,
            'autoAssertions' => true,
            'generateComments' => true
        ]);

    // 🎬 Browser opens - perform your actions manually
    // ✨ Code is automatically generated and injected here

    // Example generated output:
    // $page->type('#email', 'user@example.com')
    //     ->type('#password', 'secure123')
    //     ->click('#register-button')
    //     ->assertUrlContains('/dashboard')
    //     ->assertNoJavascriptErrors();
});
```

## 📖 Documentation

### Core Features

#### 🎯 Smart Selector Generation

Automatically generates stable, maintainable selectors with intelligent prioritization:

```php
// Priority: data-testid > id > name > class > hierarchy
'[data-testid="submit-button"]'  // Highest priority - test-stable
'#email-field'                   // ID-based - reliable
'[name="user_email"]'            // Name-based - form-friendly
'button.btn.btn-primary'         // Class-based - styling-aware
'form > div:nth-child(2) > input' // Hierarchical - fallback
```

#### ⚙️ Flexible Configuration

**Global Configuration** (`config/recording.php`):

```php
<?php

return [
    // Session settings
    'timeout' => 1800,              // 30 minutes
    'autoAssertions' => true,       // Generate assertions automatically
    'generateComments' => true,     // Add explanatory comments

    // Selector strategy
    'selectorPriority' => ['data-testid', 'id', 'name', 'class'],
    'useStableSelectors' => true,
    'includeAriaAttributes' => true,

    // Recording behavior
    'includeHoverActions' => false,
    'captureKeyboardShortcuts' => false,
    'recordScrollPosition' => false,

    // File safety (disabled by default for smooth workflow)
    'backupFiles' => false,         // Enable for extra safety
    'backupDirectory' => '.pest-recording-backups',
    'maxBackupsPerFile' => 10,

    // Code generation
    'useTypeForInputs' => true,     // Use type() vs fill() for better simulation
    'chainMethods' => true,         // Enable fluent method chaining
    'deviceEmulation' => null,      // 'mobile', 'desktop', or null
    'colorScheme' => null,          // 'dark', 'light', or null
];
```

**Per-Test Configuration**:

```php
// Array-based configuration
$page->record([
    'timeout' => 3600,
    'autoAssertions' => false,
    'deviceEmulation' => 'mobile'
]);

// Fluent API configuration
$config = (new RecordingConfig())
    ->timeout(3600)
    ->backupFiles(true)
    ->deviceEmulation('mobile')
    ->includeHoverActions(true);
```

#### 🛡️ File Safety & Backups

**Backups are disabled by default** for a smooth development experience, but can be enabled for extra safety:

```php
// Enable backups for cautious development
$page->record(['backupFiles' => true]);

// Or globally in config/recording.php
'backupFiles' => true,
'backupDirectory' => '.pest-recording-backups',
'maxBackupsPerFile' => 10,
'autoCleanupBackups' => true,
```

#### 🔧 Advanced Features

**Device Emulation**:

```php
$page->record(['deviceEmulation' => 'mobile']);  // Test mobile experience
$page->record(['colorScheme' => 'dark']);        // Test dark mode
```

**Custom Selector Strategies**:

```php
$page->record([
    'selectorPriority' => ['data-cy', 'data-testid', 'id'],
    'includeAriaAttributes' => true,
    'useStableSelectors' => true
]);
```

**Performance Optimization**:

```php
$page->record([
    'throttleScrollEvents' => true,
    'debounceInputEvents' => true,
    'maxActionsPerSession' => 5000
]);
```

### Recording Workflow

1. **Start Recording**: Call `->record()` in your test
2. **Manual Interaction**: Browser opens - perform actions manually
3. **Automatic Generation**: Actions are captured and converted to Pest code
4. **Code Injection**: Generated code is safely injected into your test file
5. **Verification**: Run the test to ensure it works correctly

### Generated Code Examples

#### Form Interactions

```php
// Manual actions → Generated code
$page->type('#email', 'user@example.com')
    ->type('#password', 'secure123')
    ->click('#login-button');
```

#### Navigation & Assertions

```php
// With auto-assertions enabled
$page->visit('/dashboard')
    ->click('#settings-link')
    ->assertUrlContains('/settings')
    ->assertNoJavascriptErrors();
```

#### Mobile Testing

```php
// Device emulation
$page = visit('/')->on()->mobile();
$page->click('#mobile-menu')
    ->assertSee('Navigation Menu');
```

## 🔍 Troubleshooting

### Common Issues

**Browser doesn't open:**

-   Ensure Playwright is installed: `npx playwright install`
-   Check browser path in Pest configuration
-   Verify permissions for browser execution

**Recording doesn't start:**

-   Check that `->record()` is called on a page instance
-   Verify JavaScript is enabled in the browser
-   Check browser console for errors

**Code injection fails:**

-   Ensure test file contains a `->record()` call
-   Check file write permissions
-   Enable backups for safety: `'backupFiles' => true`

**Generated selectors are unreliable:**

-   Add `data-testid` attributes to your HTML
-   Customize selector priority: `'selectorPriority' => ['data-testid', 'id']`
-   Enable stable selectors: `'useStableSelectors' => true`

### Error Recovery

The plugin includes comprehensive error handling:

-   **Browser crashes**: Partial code generation + backup restoration
-   **Injection failures**: Automatic clipboard fallback
-   **Communication errors**: Retry logic with exponential backoff
-   **File corruption**: Automatic backup restoration

## 🧪 Testing

Run the test suite:

```bash
# All tests
vendor/bin/pest

# Unit tests only
vendor/bin/pest tests/Unit/

# With coverage
vendor/bin/pest --coverage --min=90
```

**Test Statistics**: 159+ tests with 559+ assertions covering all components.

## 🏗️ Architecture

### Core Components

-   **BrowserRecordingPlugin**: Main trait providing `record()` method
-   **RecordingSession**: Manages browser recording lifecycle
-   **ActionRecorder**: Structured storage for user actions
-   **CodeGenerator**: Converts actions to idiomatic Pest code
-   **SelectorStrategy**: Intelligent, stable selector generation
-   **FileInjector**: Safe AST-based code injection
-   **BackupManager**: File safety and recovery system
-   **ErrorHandler**: Comprehensive error handling and recovery

### Data Flow

```
Browser Actions → JavaScript Recorder → PHP Communication → ActionRecorder
                                                                    ↓
Generated Test Code ← CodeGenerator ← SelectorStrategy ← Structured Actions
                           ↓
                    FileInjector → Test File (with backups)
```

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch: `git checkout -b feature/amazing-feature`
3. Run tests: `vendor/bin/pest`
4. Commit changes: `git commit -m 'Add amazing feature'`
5. Push to branch: `git push origin feature/amazing-feature`
6. Open a Pull Request

### Development Setup

```bash
git clone https://github.com/AustinW/pest-plugin-browser-recording.git
cd pest-plugin-browser-recording
composer install
vendor/bin/pest
```

## 📋 Changelog

### v1.0.0 (Initial Release)

-   ✨ Interactive browser recording with automatic code generation
-   🎯 Smart selector generation with stability prioritization
-   🛡️ File safety system with optional backups (disabled by default)
-   ⚙️ Comprehensive configuration system with fluent API
-   🔧 AST-based code injection for guaranteed syntax correctness
-   🚨 Advanced error handling and recovery mechanisms
-   📱 Device emulation and accessibility testing support
-   🧪 Extensive test suite with 159+ tests and 559+ assertions

## 📄 License

This package is open-sourced software licensed under the [MIT license](LICENSE.md).

## 🙏 Acknowledgments

-   **Pest Team**: For the amazing testing framework and browser plugin foundation
-   **Playwright**: For robust browser automation capabilities
-   **PHP-Parser**: For safe and reliable AST manipulation
-   **Symfony Components**: For DOM manipulation and CSS selector support

---

**Built with ❤️ for the PHP testing community**

_Make browser testing as enjoyable as writing unit tests!_
