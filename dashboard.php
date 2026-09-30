<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

// Actions : résoudre une anomalie, ou toutes les résoudre
if (isset($_GET['resolve'])) {
    $stmt = $pdo->prepare("UPDATE anomalies SET resolved = 1 WHERE id = :id");
    $stmt->execute([':id' => (int) $_GET['resolve']]);
    header('Location: dashboard.php');
    exit;
}
if (isset($_GET['resolve_all'])) {
    $pdo->exec("UPDATE anomalies SET resolved = 1 WHERE resolved = 0");
    header('Location: dashboard.php');
    exit;
}
if (isset($_GET['delete_day'])) {
    $jourDel = $_GET['delete_day'];
    $matDel  = $_GET['mat'] ?? '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $jourDel)) {
        if ($matDel !== '') {
            $stmt = $pdo->prepare("DELETE FROM pointages WHERE DATE(date_heure) = :jour AND utilisateur_matricule = :mat");
            $stmt->execute([':jour' => $jourDel, ':mat' => $matDel]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM pointages WHERE DATE(date_heure) = :jour AND utilisateur_matricule IS NULL");
            $stmt->execute([':jour' => $jourDel]);
        }
        detectAnomalies($pdo);
    }
    header('Location: dashboard.php');
    exit;
}
if (isset($_GET['infer_portes'])) {
    inferMissingPortes($pdo);
    detectAnomalies($pdo);
    header('Location: dashboard.php?portes_ok=1');
    exit;
}

$pageTitle = 'Tableau de Bord';

$total = (int) $pdo->query("SELECT COUNT(*) FROM pointages")->fetchColumn();
$totalEmployees = (int) $pdo->query("SELECT COUNT(*) FROM employees")->fetchColumn();
$activeEmployees = (int) $pdo->query(
    "SELECT COUNT(DISTINCT utilisateur_matricule) FROM pointages WHERE utilisateur_matricule IS NOT NULL"
)->fetchColumn();
$todayCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM pointages WHERE DATE(date_heure) = CURDATE()"
)->fetchColumn();

// Seules les anomalies non résolues bloquent l'export / affichent le bandeau rouge
$anomalies = $pdo->query(
    "SELECT a.*, p.date_heure, p.utilisateur_nom, p.porte
     FROM anomalies a
     JOIN pointages p ON p.id = a.pointage_id
     WHERE a.resolved = 0
     ORDER BY p.date_heure DESC
     LIMIT 200"
)->fetchAll();
$anomalyCount = (int) $pdo->query("SELECT COUNT(*) FROM anomalies WHERE resolved = 0")->fetchColumn();

// Données pour le graphique : répartition des pointages par heure (0-23)
// et répartition Entrée / Sortie / Autre, pour le Tableau de Bord.
$parHeureRows = $pdo->query(
    "SELECT HOUR(date_heure) AS h, COUNT(*) AS c FROM pointages GROUP BY HOUR(date_heure) ORDER BY h"
)->fetchAll();
$parHeure = array_fill(0, 24, 0);
foreach ($parHeureRows as $r) {
    $parHeure[(int) $r['h']] = (int) $r['c'];
}
$maxParHeure = max(1, max($parHeure));

$repartitionRows = $pdo->query(
    "SELECT
        SUM(CASE WHEN porte LIKE '%SORTIE%' THEN 1 ELSE 0 END) AS sortie,
        SUM(CASE WHEN porte LIKE '%ENTR%' THEN 1 ELSE 0 END) AS entree,
        SUM(CASE WHEN porte = '' OR porte IS NULL THEN 1 ELSE 0 END) AS autre
     FROM pointages"
)->fetch();
$repSortie = (int) ($repartitionRows['sortie'] ?? 0);
$repEntree = (int) ($repartitionRows['entree'] ?? 0);
$repAutre  = (int) ($repartitionRows['autre'] ?? 0);
$repTotal  = max(1, $repSortie + $repEntree + $repAutre);

// Jours où un(e) employé(e) a une anomalie non résolue : sert à exclure
// ces jours de la liste "heures insuffisantes" (déjà listés ci-dessus,
// comme "erreur de mouvement").
$anomalyDaysRows = $pdo->query(
    "SELECT DISTINCT p.utilisateur_matricule AS matricule, p.utilisateur_nom AS nom, DATE(p.date_heure) AS jour
     FROM anomalies a
     JOIN pointages p ON p.id = a.pointage_id
     WHERE a.resolved = 0"
)->fetchAll();
$anomalyDays = [];
foreach ($anomalyDaysRows as $ar) {
    $anomalyDays[($ar['matricule'] ?: $ar['nom']) . '|' . $ar['jour']] = true;
}

// Heures insuffisantes (< 9h/jour), en excluant les jours déjà comptés
// comme "erreur de mouvement" ci-dessus.
$normeSecondes = 9 * 3600;
$heuresInsuffisantes = array_filter(
    calculateWorkedHours($pdo),
    function ($h) use ($normeSecondes, $anomalyDays) {
        $key = ($h['matricule'] ?: $h['nom']) . '|' . $h['jour'];
        return $h['seconds'] < $normeSecondes && !isset($anomalyDays[$key]);
    }
);
$heuresInsuffisantes = array_slice(array_values($heuresInsuffisantes), 0, 200);

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$pointages = $pdo->prepare(
    "SELECT * FROM pointages ORDER BY date_heure DESC LIMIT :limit OFFSET :offset"
);
$pointages->bindValue(':limit', $perPage, PDO::PARAM_INT);
$pointages->bindValue(':offset', $offset, PDO::PARAM_INT);
$pointages->execute();
$rows = $pointages->fetchAll();

$totalPages = max(1, (int)ceil($total / $perPage));

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1>Tableau de Bord</h1>
        <p class="subtitle">Suivi des pointages et correction des anomalies</p>
    </div>
    <div class="header-actions">
        <?php if (isset($_GET['portes_ok'])): ?>
            <span class="badge-ok">&#10003; Portes déduites avec succès</span>
        <?php endif; ?>
        <a href="?infer_portes=1" class="btn btn-outline" title="Déduit la porte (Entrée/Sortie) manquante à partir de l'équipement utilisé">
            &#128273; Déduire les portes manquantes
        </a>
        <a href="import.php" class="btn btn-outline">&#8593; Importer CSV</a>
        <a href="reports.php" class="btn btn-dark">&#128196; Voir le Rapport</a>
    </div>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon stat-icon-purple">&#128101;</div>
        <div class="stat-label">Total Employés</div>
        <div class="stat-value"><?= $totalEmployees ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon stat-icon-green">&#9989;</div>
        <div class="stat-label">Employés Actifs</div>
        <div class="stat-value"><?= $activeEmployees ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon stat-icon-blue">&#128197;</div>
        <div class="stat-label">Pointages Aujourd'hui</div>
        <div class="stat-value"><?= $todayCount ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon stat-icon-multi">&#128202;</div>
        <div class="stat-label">Total Pointages</div>
        <div class="stat-value"><?= $total ?></div>
    </div>
</div>

<div class="chart-grid">
    <div class="card">
        <h3>&#128202; Pointages par heure de la journée</h3>
        <svg viewBox="0 0 700 220" class="chart-svg">
            <?php
                $barGap = 4; $chartW = 660; $chartH = 160; $chartX = 30; $chartY = 10;
                $barW = ($chartW / 24) - $barGap;
            ?>
            <line x1="<?= $chartX ?>" y1="<?= $chartY + $chartH ?>" x2="<?= $chartX + $chartW ?>" y2="<?= $chartY + $chartH ?>" stroke="#e7ebf3" stroke-width="1"/>
            <?php foreach ($parHeure as $h => $c): ?>
                <?php
                    $barH = $c > 0 ? max(2, ($c / $maxParHeure) * $chartH) : 0;
                    $x = $chartX + $h * ($barW + $barGap);
                    $y = $chartY + $chartH - $barH;
                ?>
                <rect x="<?= $x ?>" y="<?= $y ?>" width="<?= $barW ?>" height="<?= $barH ?>" rx="2" fill="<?= $c > 0 ? '#1e6fd9' : '#e7ebf3' ?>">
                    <title><?= $h ?>h : <?= $c ?> pointage(s)</title>
                </rect>
                <?php if ($h % 2 === 0): ?>
                <text x="<?= $x + $barW / 2 ?>" y="<?= $chartY + $chartH + 16 ?>" font-size="10" fill="#6b7280" text-anchor="middle"><?= $h ?>h</text>
                <?php endif; ?>
            <?php endforeach; ?>
        </svg>
    </div>

    <div class="card">
        <h3>&#128273; Répartition Entrée / Sortie</h3>
        <?php
            $pctEntree = round($repEntree / $repTotal * 100);
            $pctSortie = round($repSortie / $repTotal * 100);
            $pctAutre  = max(0, 100 - $pctEntree - $pctSortie);
        ?>
        <div class="stacked-bar">
            <div style="width:<?= $pctEntree ?>%; background:var(--green-600);" title="Entrée : <?= $repEntree ?>"></div>
            <div style="width:<?= $pctSortie ?>%; background:var(--red-600);" title="Sortie : <?= $repSortie ?>"></div>
            <?php if ($pctAutre > 0): ?>
            <div style="width:<?= $pctAutre ?>%; background:var(--gray-200);" title="Autre : <?= $repAutre ?>"></div>
            <?php endif; ?>
        </div>
        <div class="chart-legend">
            <span><i style="background:var(--green-600);"></i> Entrée (<?= $repEntree ?>, <?= $pctEntree ?>%)</span>
            <span><i style="background:var(--red-600);"></i> Sortie (<?= $repSortie ?>, <?= $pctSortie ?>%)</span>
            <?php if ($repAutre > 0): ?>
            <span><i style="background:var(--gray-200);"></i> Autre (<?= $repAutre ?>, <?= $pctAutre ?>%)</span>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($anomalyCount === 0): ?>
    <div class="banner banner-success">
        <span>&#10003; Aucune anomalie détectée - Tous les pointages sont valides !</span>
        <span class="badge-light">Prêt pour export</span>
    </div>
<?php else: ?>
    <div class="banner banner-danger">
        <span>&#9888; <?= $anomalyCount ?> anomalie(s) détectée(s) - Correction requise</span>
        <a href="?resolve_all=1" class="badge-light badge-action"
           onclick="return confirm('Marquer toutes les anomalies comme résolues ?');">
           &#10003; Résoudre tout
        </a>
    </div>
    <div class="card anomaly-list">
        <div class="card-header">
            <h3>&#128721; Erreurs de mouvement (anomalies à corriger)</h3>
        </div>
        <table class="table">
            <thead>
                <tr><th>Type</th><th>Description</th><th>Date</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($anomalies as $a): ?>
                <tr>
                    <td><span class="pill pill-erreur"><?= htmlspecialchars($a['type']) ?></span></td>
                    <td><?= htmlspecialchars($a['description']) ?></td>
                    <td><?= htmlspecialchars(formatDateFr($a['date_heure'])) ?></td>
                    <td>
                        <a href="edit_pointage.php?id=<?= $a['pointage_id'] ?>" class="btn-resolve btn-edit" title="Modifier le pointage">
                            &#9998; Modifier
                        </a>
                        <a href="?resolve=<?= $a['id'] ?>" class="btn-resolve" title="Marquer comme résolu">
                            &#10003; Résoudre
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($anomalyCount > count($anomalies)): ?>
            <p class="muted" style="margin-top:12px;">Affichage des 200 premières anomalies sur <?= $anomalyCount ?>. Utilisez "Résoudre tout" pour les traiter en une fois.</p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h3>&#9200; Heures insuffisantes (&lt; 9h/jour)</h3>
        <span class="badge-dark"><?= count($heuresInsuffisantes) ?> cas</span>
    </div>
    <p class="muted">Distinct des erreurs de mouvement ci-dessus : ici, le pointage est valide mais la durée travaillée ce jour-là est inférieure à la norme de 9h.</p>
    <table class="table">
        <thead>
            <tr><th>Employé</th><th>Matricule</th><th>Jour</th><th>Heures</th><th>Manque</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($heuresInsuffisantes as $h): ?>
            <?php $manque = $normeSecondes - $h['seconds']; ?>
            <tr>
                <td><?= htmlspecialchars($h['nom']) ?></td>
                <td><?= htmlspecialchars($h['matricule'] ?? '—') ?></td>
                <td><?= htmlspecialchars(date('d/m/Y', strtotime($h['jour']))) ?></td>
                <td><strong><?= formatDuration($h['seconds']) ?></strong></td>
                <td><span class="pill pill-danger">-<?= formatDuration($manque) ?></span></td>
                <td>
                    <a class="btn-resolve btn-edit" style="text-decoration:none;"
                       href="edit_day.php?matricule=<?= urlencode((string)$h['matricule']) ?>&jour=<?= urlencode($h['jour']) ?>">
                       &#9998; Modifier
                    </a>
                    <a class="btn-resolve btn-delete" style="text-decoration:none;"
                       href="?delete_day=<?= urlencode($h['jour']) ?>&mat=<?= urlencode((string)$h['matricule']) ?>"
                       onclick="return confirm('Supprimer tous les pointages de <?= htmlspecialchars(addslashes($h['nom'])) ?> pour le <?= htmlspecialchars(date('d/m/Y', strtotime($h['jour']))) ?> ?');">
                       &#128465; Supprimer
                    </a>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($heuresInsuffisantes)): ?>
            <tr><td colspan="6" class="empty">Aucun cas d'heures insuffisantes.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="card" id="historique">
    <div class="card-header">
        <h3>&#9776; Historique Global des Pointages</h3>
        <span class="badge-dark">Total: <?= $total ?> entrées</span>
    </div>
    <div class="table-scroll">
        <table class="table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Porte</th>
                    <th>ID de l'Équipement</th>
                    <th>Équipement</th>
                    <th>Group Utilisateur</th>
                    <th>Utilisateur</th>
                    <th>Évènement</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= htmlspecialchars(formatDateFr($r['date_heure'])) ?></td>
                    <td><?= htmlspecialchars($r['porte']) ?></td>
                    <td><?= htmlspecialchars($r['equipement_id']) ?></td>
                    <td><?= htmlspecialchars($r['equipement_nom']) ?></td>
                    <td><?= htmlspecialchars($r['groupe_utilisateur']) ?></td>
                    <td><?= htmlspecialchars(($r['utilisateur_matricule'] ? $r['utilisateur_matricule'].'(' : '') . $r['utilisateur_nom'] . ($r['utilisateur_matricule'] ? ')' : '')) ?></td>
                    <td><span class="pill <?= stripos($r['evenement'], 'succ') !== false ? 'pill-success' : 'pill-warning' ?>"><?= htmlspecialchars($r['evenement']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?>
                <tr><td colspan="7" class="empty">Aucun pointage importé pour le moment.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a href="?page=<?= $i ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
