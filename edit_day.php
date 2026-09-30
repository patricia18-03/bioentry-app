<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

$matricule = $_GET['matricule'] ?? '';
$jour      = $_GET['jour'] ?? '';

if ($jour === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $jour)) {
    header('Location: reports.php');
    exit;
}

$error = '';

// Traitement du formulaire : mise à jour de chaque pointage, ou suppression
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_id'])) {
        $stmt = $pdo->prepare("DELETE FROM pointages WHERE id = :id");
        $stmt->execute([':id' => (int) $_POST['delete_id']]);
    } elseif (isset($_POST['save'])) {
        $ids       = $_POST['id'] ?? [];
        $dates     = $_POST['date_heure'] ?? [];
        $portes    = $_POST['porte'] ?? [];
        $stmtUpd = $pdo->prepare("UPDATE pointages SET date_heure = :dh, porte = :porte WHERE id = :id");
        foreach ($ids as $i => $id) {
            $ts = strtotime($dates[$i] ?? '');
            if (!$ts) {
                $error = "Date invalide pour un des pointages.";
                continue;
            }
            $stmtUpd->execute([
                ':dh'    => date('Y-m-d H:i:s', $ts),
                ':porte' => trim($portes[$i] ?? ''),
                ':id'    => (int) $id,
            ]);
        }
    }
    detectAnomalies($pdo);
    if ($error === '') {
        header('Location: edit_day.php?matricule=' . urlencode($matricule) . '&jour=' . urlencode($jour) . '&saved=1');
        exit;
    }
}

$sql = "SELECT * FROM pointages WHERE DATE(date_heure) = :jour";
$params = [':jour' => $jour];
if ($matricule !== '') {
    $sql .= " AND utilisateur_matricule = :mat";
    $params[':mat'] = $matricule;
}
$sql .= " ORDER BY date_heure ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$pointagesJour = $stmt->fetchAll();

$nomAffiche = $pointagesJour[0]['utilisateur_nom'] ?? ($matricule ?: 'cet employé');

$pageTitle = 'Régulariser une journée';
include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1>Régulariser la journée du <?= htmlspecialchars(date('d/m/Y', strtotime($jour))) ?></h1>
        <p class="subtitle"><?= htmlspecialchars($nomAffiche) ?> — modifiez ou supprimez les pointages ci-dessous en une seule fois</p>
    </div>
</div>

<?php if ($error): ?>
    <div class="banner banner-danger"><span><?= htmlspecialchars($error) ?></span></div>
<?php endif; ?>
<?php if (isset($_GET['saved'])): ?>
    <div class="banner banner-success"><span>&#10003; Modifications enregistrées.</span></div>
<?php endif; ?>

<div class="card">
    <h3>Pointages de la journée</h3>
    <?php if (empty($pointagesJour)): ?>
        <p class="empty">Aucun pointage trouvé pour cette journée.</p>
    <?php else: ?>
        <form method="post">
            <table class="table">
                <thead>
                    <tr><th>Date et heure</th><th>Porte</th><th>Équipement</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($pointagesJour as $p): ?>
                    <tr>
                        <td>
                            <input type="hidden" name="id[]" value="<?= $p['id'] ?>">
                            <input type="text" name="date_heure[]" value="<?= htmlspecialchars(formatDateFr($p['date_heure'])) ?>" style="padding:8px;border:1px solid var(--gray-200);border-radius:8px;">
                        </td>
                        <td>
                            <select name="porte[]" style="padding:8px;border:1px solid var(--gray-200);border-radius:8px;">
                                <option value="PORTE ENTREE" <?= stripos($p['porte'], 'entr') !== false ? 'selected' : '' ?>>Entrée</option>
                                <option value="PORTE SORTIE" <?= stripos($p['porte'], 'sortie') !== false ? 'selected' : '' ?>>Sortie</option>
                            </select>
                        </td>
                        <td><?= htmlspecialchars($p['equipement_nom']) ?></td>
                        <td>
                            <button type="submit" name="delete_id" value="<?= $p['id'] ?>" class="link-danger" style="background:none;border:none;cursor:pointer;"
                                    onclick="return confirm('Supprimer ce pointage précis ?');">Supprimer cette ligne</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="edit-form-actions">
                <button type="submit" name="save" value="1" class="btn btn-primary">&#10003; Enregistrer les modifications</button>
                <a href="reports.php" class="btn btn-outline">Annuler</a>
            </div>
        </form>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
