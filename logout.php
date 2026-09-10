<?php
require __DIR__ . '/session_timer.php';

session_unset();
session_destroy();
header('Location: index.php');
exit;
