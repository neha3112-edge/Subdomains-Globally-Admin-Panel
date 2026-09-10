<?php
require_once __DIR__ . '/config/config.php';
require_login();
redirect(BASE_URL . '/dashboard.php');
