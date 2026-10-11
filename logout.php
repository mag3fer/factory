<?php
require __DIR__ . '/inc/bootstrap.php';
$_SESSION = [];
session_destroy();
redirect('login.php');
