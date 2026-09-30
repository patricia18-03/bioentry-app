<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $pageTitle ?? 'Tableau de Bord' ?> - Attendance Management</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php include __DIR__ . '/sidebar.php'; ?>
<main class="content">
    <div class="greeting-line">Bonjour <?= htmlspecialchars($_SESSION['admin_nom'] ?? 'Admin RH') ?> !</div>
