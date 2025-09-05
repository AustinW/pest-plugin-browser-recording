<?php

declare(strict_types=1);

use function PestPluginBrowserRecording\record;

it('can use namespaced record function', function () {
    $result = record(['timeout' => 30]);
    expect($result)->toBeInstanceOf(PHPUnit\Framework\TestCase::class);
});

it('namespaced function works with options', function () {
    expect(function_exists('PestPluginBrowserRecording\record'))->toBeTrue();
});
