<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'Importation CSV / Excel';
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file'];
    if ($file['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'xlsx'], true)) {
            $result = ['inserted' => 0, 'errors' => ['Le fichier doit être au format .csv ou .xlsx']];
        } else {
            // Remplace toutes les données existantes avant chaque import :
            // seul le fichier importé maintenant doit apparaître dans l'application.
            $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
            $pdo->exec("TRUNCATE TABLE pointages");
            $pdo->exec("TRUNCATE TABLE anomalies");
            $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
            $destDir = __DIR__ . '/uploads';
            if (!is_dir($destDir)) mkdir($destDir, 0775, true);
            $dest = $destDir . '/' . uniqid('import_') . '.' . $ext;
            move_uploaded_file($file['tmp_name'], $dest);
            $result = importPointagesFile($pdo, $dest, $file['name']);
            // Synchronise automatiquement la liste "Employés" avec les
            // données fraîchement importées (ajoute/retire au besoin).
            syncEmployeesFromPointages($pdo);
        }
    } else {
        $result = ['inserted' => 0, 'errors' => ["Erreur d'upload (code {$file['error']})."]];
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1>Importation CSV</h1>
        <p class="subtitle">Importer les données de pointage exportées depuis les badgeuses BioEntry</p>
    </div>
</div>

<?php if ($result): ?>
    <?php if (!empty($result['errors'])): ?>
        <div class="banner banner-danger" style="flex-direction:column;align-items:flex-start;gap:6px;">
            <strong><?= $result['inserted'] ?> ligne(s) importée(s)<?= ($result['duplicates'] ?? 0) > 0 ? ', ' . $result['duplicates'] . ' doublon(s) ignoré(s)' : '' ?>, <?= count($result['errors']) ?> erreur(s) :</strong>
            <ul>
                <?php foreach (array_slice($result['errors'], 0, 15) as $e): ?>
                    <li><?= htmlspecialchars($e) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php else: ?>
        <div class="banner banner-success">
            <span>&#10003; <?= $result['inserted'] ?> pointage(s) importé(s) avec succès<?= ($result['duplicates'] ?? 0) > 0 ? ' (' . $result['duplicates'] . ' doublon(s) déjà présents ignoré(s))' : '' ?> !</span>
            <a href="dashboard.php" class="badge-light" style="text-decoration:none;">Voir le tableau de bord</a>
        </div>
    <?php endif; ?>
<?php endif; ?>

<div class="card">
    <h3>Importer un fichier CSV ou Excel (.xlsx)</h3>
    <p class="muted">
        Formats acceptés :<br>
        &bull; <strong>.csv</strong> — Export badgeuse : <code>Date, Porte, ID Equipement, Equipement, Groupe Utilisateur, Utilisateur, Evenement</code><br>
        &bull; <strong>.csv</strong> ou <strong>.xlsx</strong> — Tri/rapport journalier : <code>Date, Date modifiée, Equipement, Utilisateur, Check, #ce, Tot heure</code>
        (Porte et ID Equipement sont alors laissés vides, et <code>Check = OK</code> devient un succès, toute autre valeur est signalée comme anomalie)<br>
        &bull; Pour un classeur <strong>.xlsx</strong> avec plusieurs onglets (un par jour par exemple), <strong>toutes les feuilles sont importées automatiquement</strong> en un seul envoi.<br>
        Le champ <code>Utilisateur</code> peut être au format <code>509(ANDRIANORO Sedera Josué)</code>.
    </p>
    <form method="post" enctype="multipart/form-data" class="upload-form">
        <input type="file" name="csv_file" accept=".csv,.xlsx" required>
        <button type="submit" class="btn btn-primary">Importer</button>
    </form>
    <p class="muted" style="margin-top:10px;">&#9888; Chaque import remplace automatiquement toutes les données précédentes : seul le fichier importé maintenant sera visible dans l'application.</p>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
