<?php $current = basename($_SERVER['PHP_SELF']); ?>
<header class="topnav">
    <div class="topnav-brand">Système de Pointage (BioEntry)</div>
    <nav class="topnav-links">
        <a href="dashboard.php" class="<?= $current === 'dashboard.php' ? 'active' : '' ?>">
            Dashboard
        </a>
        <a href="import.php" class="<?= $current === 'import.php' ? 'active' : '' ?>">
            Importation
        </a>
        <a href="employees.php" class="<?= $current === 'employees.php' ? 'active' : '' ?>">
            Employés
        </a>
        <a href="reports.php" class="<?= $current === 'reports.php' ? 'active' : '' ?>">
            Rapports &amp; Export
        </a>
        <a href="logout.php" class="topnav-logout-tab">Déconnexion</a>
    </nav>
</header>
