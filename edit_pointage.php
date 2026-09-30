<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete'])) {
        // Suppression du pointage (utile si la ligne est erronée dans son ensemble)
        $stmt = $pdo->prepare("DELETE FROM pointages WHERE id = :id");
        $stmt->execute([':id' => $id]);
        detectAnomalies($pdo);
        header('Location: dashboard.php');
        exit;
    }

    $dateHeure = trim($_POST['date_heure'] ?? '');
    $porte     = trim($_POST['porte'] ?? '');
    $matricule = trim($_POST['matricule'] ?? '');
    $nom       = trim($_POST['nom'] ?? '');
    $evenement = trim($_POST['evenement'] ?? '');

    $ts = strtotime($dateHeure);
    if (!$ts) {
        $error = 'Date/heure invalide. Format attendu : AAAA-MM-JJ HH:MM:SS';
    } elseif ($nom === '') {
        $error = "Le nom de l'utilisateur est obligatoire.";
    } else {
        $stmt = $pdo->prepare(
            "UPDATE pointages
             SET date_heure = :date_heure, porte = :porte,
                 utilisateur_matricule = :matricule, utilisateur_nom = :nom,
                 evenement = :evenement
             WHERE id = :id"
        );
        $stmt->execute([
            ':date_heure' => date('Y-m-d H:i:s', $ts),
            ':porte'      => $porte,
            ':matricule'  => $matricule !== '' ? $matricule : null,
            ':nom'        => $nom,
            ':evenement'  => $evenement !== '' ? $evenement : "Succès de l'Authentification",
            ':id'         => $id,
        ]);
        // Recalcule les anomalies : la correction peut en résoudre ou en révéler
        detectAnomalies($pdo);
        header('Location: dashboard.php');
        exit;
    }
}

$stmt = $pdo->prepare("SELECT * FROM pointages WHERE id = :id");
$stmt->execute([':id' => $id]);
$pointage = $stmt->fetch();

if (!$pointage) {
    header('Location: dashboard.php');
    exit;
}

$anomaliesForRow = $pdo->prepare(
    "SELECT type, description FROM anomalies WHERE pointage_id = :id AND resolved = 0"
);
$anomaliesForRow->execute([':id' => $id]);
$rowAnomalies = $anomaliesForRow->fetchAll();

$pageTitle = 'Modifier un pointage';
include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1>Modifier le pointage #<?= $pointage['id'] ?></h1>
        <p class="subtitle">Corrigez les informations pour résoudre l'anomalie à la source</p>
    </div>
</div>

<?php if ($error): ?>
    <div class="banner banner-danger"><span><?= htmlspecialchars($error) ?></span></div>
<?php endif; ?>

<?php if (!empty($rowAnomalies)): ?>
    <div class="card">
        <h3>Anomalie(s) liée(s) à ce pointage</h3>
        <table class="table">
            <thead><tr><th>Type</th><th>Description</th></tr></thead>
            <tbody>
            <?php foreach ($rowAnomalies as $a): ?>
                <tr>
                    <td><span class="pill pill-warning"><?= htmlspecialchars($a['type']) ?></span></td>
                    <td><?= htmlspecialchars($a['description']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<div class="card">
    <h3>Informations du pointage</h3>
    <form method="post" class="edit-form">
        <input type="hidden" name="id" value="<?= $pointage['id'] ?>">

        <label>Date et heure
            <input type="text" name="date_heure" value="<?= htmlspecialchars(formatDateFr($pointage['date_heure'])) ?>" placeholder="AAAA-MM-JJ HH:MM:SS" required>
        </label>

        <label>Porte
            <input type="text" name="porte" value="<?= htmlspecialchars($pointage['porte']) ?>" placeholder="PORTE ENTREE / PORTE SORTIE">
        </label>

        <label>Matricule
            <input type="text" name="matricule" value="<?= htmlspecialchars($pointage['utilisateur_matricule'] ?? '') ?>">
        </label>

        <label>Nom de l'utilisateur
            <input type="text" name="nom" value="<?= htmlspecialchars($pointage['utilisateur_nom']) ?>" required>
        </label>

        <label>Évènement
            <input type="text" name="evenement" value="<?= htmlspecialchars($pointage['evenement']) ?>">
        </label>

        <div class="edit-form-actions">
            <button type="submit" class="btn btn-primary">&#10003; Enregistrer la correction</button>
            <a href="dashboard.php" class="btn btn-outline">Annuler</a>
        </div>
    </form>
</div>

<div class="card">
    <h3>Zone dangereuse</h3>
    <p class="muted">Si ce pointage est entièrement erroné (ex. ligne parasite), vous pouvez le supprimer définitivement.</p>
    <form method="post" onsubmit="return confirm('Supprimer définitivement ce pointage ?');">
        <input type="hidden" name="id" value="<?= $pointage['id'] ?>">
        <button type="submit" name="delete" value="1" class="btn" style="background: var(--red-100); color: var(--red-600);">
            &#128465; Supprimer ce pointage
        </button>
    </form>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
