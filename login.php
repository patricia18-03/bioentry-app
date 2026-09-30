<?php
session_start();
$_SESSION['admin_id']  = 1;
$_SESSION['admin_nom'] = 'Admin RH';
header('Location: dashboard.php');
exit;