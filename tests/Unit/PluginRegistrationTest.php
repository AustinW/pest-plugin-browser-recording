<?php

declare(strict_types=1);

it('has record method available on test instance', function () {
    expect(method_exists($this, 'record'))->toBeTrue();
});

it('record method returns test instance for chaining', function () {
    $result = $this->record(['timeout' => 60]);
    expect($result)->toBe($this);
});

it('global record function is available', function () {
    expect(function_exists('PestPluginBrowserRecording\record'))->toBeTrue();
});
