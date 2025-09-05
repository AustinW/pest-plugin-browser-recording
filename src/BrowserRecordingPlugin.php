<?php

declare(strict_types=1);

namespace PestPluginBrowserRecording;

use PestPluginBrowserRecording\Recorder\RecordingSession;
use PestPluginBrowserRecording\Config\RecordingConfig;

/**
 * Browser Recording Plugin Trait
 * 
 * This trait adds the record() method to browser page instances, enabling
 * interactive recording of user actions and automatic test code generation.
 * 
 * @mixin \Pest\Browser\Api\Webpage
 */
trait BrowserRecordingPlugin
{
    /**
     * Start recording user actions in the browser and automatically generate Pest test code.
     * 
     * Opens a headed browser session, records user interactions, and injects the
     * corresponding Pest code into the test file at the point where record() was called.
     *
     * @param array<string, mixed> $options Recording configuration options
     * @return static Returns the current instance to support method chaining
     * 
     * @example
     * ```php
     * $page = visit('/checkout')
     *     ->actingAs($user)
     *     ->record(['timeout' => 600]); // Start recording
     * 
     * // Generated code appears here after session ends
     * ```
     */
    public function record(array $options = []): static
    {
        // Merge with default configuration
        $config = RecordingConfig::merge($options);
        
        // Initialize recording session with current browser context
        $session = new RecordingSession($this, $config);
        $session->start();
        
        return $this;
    }
}
