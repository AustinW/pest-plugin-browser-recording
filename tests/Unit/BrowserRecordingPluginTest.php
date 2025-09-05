<?php

declare(strict_types=1);

it('can create browser recording plugin trait', function () {
    expect(trait_exists('PestPluginBrowserRecording\BrowserRecordingPlugin'))->toBeTrue();
});

it('record method exists on trait', function () {
    $reflection = new ReflectionClass('PestPluginBrowserRecording\BrowserRecordingPlugin');
    expect($reflection->hasMethod('record'))->toBeTrue();
});
