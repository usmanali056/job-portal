<?php
/**
 * JobNexus - Logout Handler
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/AuthController.php';

$auth = new AuthController();
$auth->logout();
