<?php

declare(strict_types=1);

use PestPluginBrowserRecording\BrowserRecordingPlugin;
use PestPluginBrowserRecording\Config\RecordingConfig;

it('trait can be used on any class', function () {
    $mockPage = new class {
        use BrowserRecordingPlugin;
    };
    
    expect(method_exists($mockPage, 'record'))->toBeTrue();
});

it('record method accepts options and returns self', function () {
    $mockPage = new class {
        use BrowserRecordingPlugin;
    };
    
    $result = $mockPage->record(['timeout' => 300]);
    expect($result)->toBe($mockPage);
});

it('record method works with default options', function () {
    $mockPage = new class {
        use BrowserRecordingPlugin;
    };
    
    $result = $mockPage->record();
    expect($result)->toBe($mockPage);
});

it('record method supports method chaining', function () {
    $mockPage = new class {
        use BrowserRecordingPlugin;
        
        public function someOtherMethod(): self {
            return $this;
        }
    };
    
    $result = $mockPage->record()->someOtherMethod();
    expect($result)->toBe($mockPage);
});

it('validates configuration options', function () {
    $mockPage = new class {
        use BrowserRecordingPlugin;
    };
    
    // This should not throw an exception with valid config
    $result = $mockPage->record(['timeout' => 600]);
    expect($result)->toBe($mockPage);
});
