<?php

declare(strict_types=1);

/**
 * Pest Plugin Browser Recording
 * 
 * This plugin extends the Pest Browser plugin with interactive recording capabilities.
 * It allows developers to perform manual browser actions and automatically generate
 * corresponding Pest test code.
 * 
 * @author Your Name
 * @package PestPluginBrowserRecording
 * @version 1.0.0
 */

namespace PestPluginBrowserRecording;

use Pest\Plugin;
use PHPUnit\Framework\TestCase;

// Ensure we're running on a compatible version of Pest
if (!class_exists('Pest\Plugin')) {
    throw new \RuntimeException('Pest Plugin Browser Recording requires Pest v4.0 or higher.');
}

// Register the BrowserRecordingPlugin trait with Pest
// This makes the record() method available on all test instances
Plugin::uses(BrowserRecordingPlugin::class);

/**
 * Start recording user actions in the browser and automatically generate Pest test code.
 *
 * @param array<string, mixed> $options Recording configuration options
 * @return TestCase
 */
function record(array $options = [])
{
    return test()->record(...func_get_args()); // @phpstan-ignore-line
}
