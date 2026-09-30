<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

// Suppression directe d'un pointage
if (isset($_GET['delete_pointage'])) {
    $stmt = $pdo->prepare("DELETE FROM pointages WHERE id = :id");
    $stmt->execute([':id' => (int) $_GET['delete_pointage']]);
    detectAnomalies($pdo);
    $qs = $_GET;
    unset($qs['delete_pointage']);
    header('Location: reports.php?' . http_build_query($qs));
    exit;
}

// Suppression de TOUS les pointages d'un employé pour un jour
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
    $qs = $_GET;
    unset($qs['delete_day'], $qs['mat']);
    header('Location: reports.php?' . http_build_query($qs));
    exit;
}

$dateFrom = $_GET['from'] ?? '';
$dateTo   = $_GET['to'] ?? '';
$matricule = $_GET['matricule'] ?? '';
$statutFiltre = $_GET['statut'] ?? 'tous';

$sql = "SELECT * FROM pointages WHERE 1=1";
$params = [];
if ($dateFrom !== '') { $sql .= " AND date_heure >= :from"; $params[':from'] = $dateFrom . ' 00:00:00'; }
if ($dateTo   !== '') { $sql .= " AND date_heure <= :to";   $params[':to']   = $dateTo . ' 23:59:59'; }
if ($matricule !== '') { $sql .= " AND utilisateur_matricule = :mat"; $params[':mat'] = $matricule; }
$sql .= " ORDER BY date_heure DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Export CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="rapport_pointages_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Date', 'Porte', 'ID Equipement', 'Equipement', 'Groupe Utilisateur', 'Utilisateur', 'Evenement'], ';');
    foreach ($rows as $r) {
        fputcsv($out, [
            formatDateFr($r['date_heure']),
            $r['porte'],
            $r['equipement_id'],
            $r['equipement_nom'],
            $r['groupe_utilisateur'],
            ($r['utilisateur_matricule'] ? $r['utilisateur_matricule'] . '(' . $r['utilisateur_nom'] . ')' : $r['utilisateur_nom']),
            $r['evenement'],
        ], ';');
    }
    fclose($out);
    exit;
}

$pageTitle = 'Rapports & Export';
$employees = $pdo->query("SELECT matricule, nom, prenom FROM employees ORDER BY nom")->fetchAll();

$heures = calculateWorkedHours(
    $pdo,
    $dateFrom !== '' ? $dateFrom : null,
    $dateTo !== '' ? $dateTo : null,
    $matricule !== '' ? $matricule : null
);
$totalSecondsGeneral = array_sum(array_column($heures, 'seconds'));

// Jours avec anomalie (non résolue)
$anomalyDaysRows = $pdo->query(
    "SELECT DISTINCT p.utilisateur_matricule AS matricule, p.utilisateur_nom AS nom, DATE(p.date_heure) AS jour
     FROM anomalies a
     JOIN pointages p ON p.id = a.pointage_id
     WHERE a.resolved = 0"
)->fetchAll();
$anomalyDays = [];
foreach ($anomalyDaysRows as $ar) {
    $key = ($ar['matricule'] ?: $ar['nom']) . '|' . $ar['jour'];
    $anomalyDays[$key] = true;
}

$normeSecondes = 9 * 3600;
$countErreur = 0; $countInsuffisant = 0; $countComplet = 0;
foreach ($heures as &$h) {
    $key = ($h['matricule'] ?: $h['nom']) . '|' . $h['jour'];
    if (isset($anomalyDays[$key])) {
        $h['statut'] = 'erreur';
        $countErreur++;
    } elseif ($h['seconds'] < $normeSecondes) {
        $h['statut'] = 'insuffisant';
        $countInsuffisant++;
    } else {
        $h['statut'] = 'complet';
        $countComplet++;
    }
}
unset($h);

if ($statutFiltre !== 'tous') {
    $heures = array_values(array_filter($heures, fn($h) => $h['statut'] === $statutFiltre));
}

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1>Rapports &amp; Export</h1>
        <p class="subtitle">Filtrer l'historique des pointages et exporter les résultats</p>
    </div>
</div>

<div class="card">
    <form method="get" class="inline-form">
        <label>Du <input type="date" name="from" value="<?= htmlspecialchars($dateFrom) ?>"></label>
        <label>Au <input type="date" name="to" value="<?= htmlspecialchars($dateTo) ?>"></label>
        <label>Employé
            <select name="matricule">
                <option value="">Tous</option>
                <?php foreach ($employees as $e): ?>
                    <option value="<?= htmlspecialchars($e['matricule']) ?>" <?= $matricule === $e['matricule'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($e['matricule'] . ' - ' . $e['nom'] . ' ' . $e['prenom']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit" class="btn btn-primary">Filtrer</button>
        <a class="btn btn-dark" href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>">&#8595; Exporter en CSV</a>
    </form>
</div>

<div class="card">
    <div class="card-header">
        <h3>&#9200; Heures travaillées</h3>
        <span class="badge-dark">Total période : <?= formatDuration($totalSecondsGeneral) ?></span>
    </div>
    <p class="muted">
        Calculées en associant chaque pointage "entrée" au prochain pointage "sortie" du même jour (norme : <strong>9h/jour</strong>).
    </p>

    <div class="filter-tabs">
        <a href="?<?= http_build_query(array_merge($_GET, ['statut' => 'tous'])) ?>" class="filter-tab <?= $statutFiltre === 'tous' ? 'active' : '' ?>">
            Tous (<?= $countErreur + $countInsuffisant + $countComplet ?>)
        </a>
        <a href="?<?= http_build_query(array_merge($_GET, ['statut' => 'erreur'])) ?>" class="filter-tab filter-tab-erreur <?= $statutFiltre === 'erreur' ? 'active' : '' ?>">
            &#128721; Erreur de mouvement (<?= $countErreur ?>)
        </a>
        <a href="?<?= http_build_query(array_merge($_GET, ['statut' => 'insuffisant'])) ?>" class="filter-tab filter-tab-insuffisant <?= $statutFiltre === 'insuffisant' ? 'active' : '' ?>">
            &#9888; Insuffisant (<?= $countInsuffisant ?>)
        </a>
        <a href="?<?= http_build_query(array_merge($_GET, ['statut' => 'complet'])) ?>" class="filter-tab filter-tab-complet <?= $statutFiltre === 'complet' ? 'active' : '' ?>">
            &#10003; Complet (<?= $countComplet ?>)
        </a>
    </div>

    <div class="table-scroll">
        <table class="table">
            <thead>
                <tr><th>Employé</th><th>Matricule</th><th>Jour</th><th>Heures travaillées</th><th>Statut</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($heures as $h): ?>
                <?php $manque = $normeSecondes - $h['seconds']; ?>
                <tr>
                    <td><?= htmlspecialchars($h['nom']) ?></td>
                    <td><?= htmlspecialchars($h['matricule'] ?? '—') ?></td>
                    <td><?= htmlspecialchars(date('d/m/Y', strtotime($h['jour']))) ?></td>
                    <td><strong><?= formatDuration($h['seconds']) ?></strong></td>
                    <td>
                        <?php if ($h['statut'] === 'erreur'): ?>
                            <span class="pill pill-erreur">&#9888; Erreur de mouvement</span>
                        <?php elseif ($h['statut'] === 'insuffisant'): ?>
                            <span class="pill pill-danger">&#9888; Insuffisant (-<?= formatDuration($manque) ?>)</span>
                        <?php else: ?>
                            <span class="pill pill-success">&#10003; Complet</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a class="btn-resolve" style="text-decoration:none;"
                           href="reports.php?from=<?= urlencode($h['jour']) ?>&to=<?= urlencode($h['jour']) ?>&matricule=<?= urlencode((string)$h['matricule']) ?>#pointages-jour">
                           Voir les pointages
                        </a>
                        <?php if ($h['statut'] === 'erreur'): ?>
                            <a class="btn-resolve btn-edit" style="text-decoration:none;" href="dashboard.php#historique">&#128269; Voir l'anomalie</a>
                        <?php else: ?>
                            <a class="btn-resolve btn-edit" style="text-decoration:none;"
                               href="edit_day.php?matricule=<?= urlencode((string)$h['matricule']) ?>&jour=<?= urlencode($h['jour']) ?>">
                               &#9998; Modifier
                            </a>
                            <a class="btn-resolve btn-delete" style="text-decoration:none;"
                               href="?<?= http_build_query(array_merge($_GET, ['delete_day' => $h['jour'], 'mat' => (string)$h['matricule']])) ?>"
                               onclick="return confirm('Supprimer tous les pointages de <?= htmlspecialchars(addslashes($h['nom'])) ?> pour le <?= htmlspecialchars(date('d/m/Y', strtotime($h['jour']))) ?> ?');">
                               &#128465; Supprimer
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($heures)): ?>
                <tr><td colspan="6" class="empty">Aucun résultat pour ce filtre.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card" id="pointages-jour">
    <div class="card-header">
        <h3>Résultats</h3>
        <span class="badge-dark"><?= count($rows) ?> entrée(s)</span>
    </div>
    <div class="table-scroll">
        <table class="table">
            <thead>
                <tr>
                    <th>Date</th><th>Porte</th><th>ID Équipement</th><th>Équipement</th>
                    <th>Groupe</th><th>Utilisateur</th><th>Évènement</th><th>Action</th>
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
                    <td style="white-space:nowrap;">
                        <a href="edit_pointage.php?id=<?= $r['id'] ?>" style="color:blue; font-weight:bold; margin-right:15px;">MODIFIER</a>
                        <a href="?delete_pointage=<?= $r['id'] ?>" onclick="return confirm('Supprimer ce pointage ?');" style="color:red; font-weight:bold;">SUPPRIMER</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?>
                <tr><td colspan="8" class="empty">Aucun résultat pour ces filtres.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>