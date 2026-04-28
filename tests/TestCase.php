<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $tmpDir = storage_path('framework/testing/tmp');

        if (! is_dir($tmpDir)) {
            mkdir($tmpDir, 0775, true);
        }

        chmod($tmpDir, 0775);
        ini_set('sys_temp_dir', $tmpDir);
        putenv("TMPDIR={$tmpDir}");
        putenv("TMP={$tmpDir}");
        putenv("TEMP={$tmpDir}");
        $_ENV['TMPDIR'] = $tmpDir;
        $_ENV['TMP'] = $tmpDir;
        $_ENV['TEMP'] = $tmpDir;
    }
}
