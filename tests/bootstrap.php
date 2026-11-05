<?php
/**
 * Test bootstrap: minimal in-memory WordPress doubles, so tests need no WordPress install
 * and can never reach a real Woodpecker server.
 *
 * @package Shift8\Woodpecker
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/stubs.php';
