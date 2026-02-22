<?php
require_once 'functions.php';

// destroy session and redirect to login
session_unset();
session_destroy();
header('Location: login.php');
exit;
?>