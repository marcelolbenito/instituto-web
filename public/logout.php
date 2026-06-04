<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/Auth.php';
auth_session_start();
auth_logout();
header('Location: login.php');
exit;
