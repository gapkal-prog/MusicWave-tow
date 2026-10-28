<?php
/**
 * Dumps live PHP-rendered HTML fixtures for `tests/e2e-browser-runtime-test.mjs`.
 *
 * @package ManaCore\MusicWave\Tests
 */

declare(strict_types=1);

$GLOBALS['mw_dump_e2e_dom'] = true;
require __DIR__ . '/run.php';
