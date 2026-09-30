<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'Employés';
$error = '';
$syncMessage = '';

// Ajout
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $matricule = trim($_POST['matricule'] ?? '');
    $nom       = trim($_POST['nom'] ?? '');
    $prenom    = trim($_POST['prenom'] ?? '');
    $groupe    = trim($_POST['groupe'] ?? 'All Users');
    if ($matricule === '' || $nom === '') {
        $error = 'Matricule et nom sont obligatoires.';
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO employees (matricule, nom, prenom, groupe) VALUES (:m, :n, :p, :g)");
            $stmt->execute([':m' => $matricule, ':n' => $nom, ':p' => $prenom, ':g' => $groupe]);
        } catch (PDOException $e) {
            $error = 'Ce matricule existe déjà.';
        }
    }
}

// Modification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit') {
    $id        = (int) ($_POST['id'] ?? 0);
    $matricule = trim($_POST['matricule'] ?? '');
    $nom       = trim($_POST['nom'] ?? '');
    $prenom    = trim($_POST['prenom'] ?? '');
    $groupe    = trim($_POST['groupe'] ?? 'All Users');
    if ($matricule === '' || $nom === '') {
        $error = 'Matricule et nom sont obligatoires.';
    } else {
        try {
            $stmt = $pdo->prepare(
                "UPDATE employees SET matricule = :m, nom = :n, prenom = :p, groupe = :g WHERE id = :id"
            );
            $stmt->execute([':m' => $matricule, ':n' => $nom, ':p' => $prenom, ':g' => $groupe, ':id' => $id]);
        } catch (PDOException $e) {
            $error = 'Ce matricule existe déjà pour un autre employé.';
        }
    }
    header('Location: employees.php');
    exit;
}

// Suppression
if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare("DELETE FROM employees WHERE id = :id");
    $stmt->execute([':id' => (int)$_GET['delete']]);
    header('Location: employees.php');
    exit;
}

// Synchronisation depuis les pointages (ajoute les manquants, retire les employés sans aucun pointage)
if (isset($_GET['sync'])) {
    $result = syncEmployeesFromPointages($pdo);
    header('Location: employees.php?added=' . $result['added'] . '&removed=' . $result['removed']);
    exit;
}
if (isset($_GET['added'])) {
    $syncMessage = (int) $_GET['added'] . ' employé(s) ajouté(s), ' . (int) ($_GET['removed'] ?? 0) . ' employé(s) sans aucun pointage retiré(s).';
}

$employees = $pdo->query("SELECT * FROM employees ORDER BY nom, prenom")->fetchAll();
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : null;

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1>Employés</h1>
        <p class="subtitle">Gestion des employés enregistrés dans le système de pointage</p>
    </div>
    <div class="header-actions">
        <a href="?sync=1" class="btn btn-outline" title="Synchronise cette liste avec les pointages importés : ajoute les manquants, retire ceux sans aucun pointage">
            &#128260; Synchroniser avec les pointages
        </a>
    </div>
</div>

<?php if ($error): ?>
    <div class="banner banner-danger"><span><?= htmlspecialchars($error) ?></span></div>
<?php endif; ?>
<?php if ($syncMessage): ?>
    <div class="banner banner-success"><span>&#10003; <?= htmlspecialchars($syncMessage) ?></span></div>
<?php endif; ?>

<div class="card">
    <h3>Ajouter un employé</h3>
    <form method="post" class="inline-form">
        <input type="hidden" name="action" value="add">
        <input type="text" name="matricule" placeholder="Matricule" required>
        <input type="text" name="nom" placeholder="Nom" required>
        <input type="text" name="prenom" placeholder="Prénom">
        <input type="text" name="groupe" placeholder="Groupe" value="All Users">
        <button type="submit" class="btn btn-primary">Ajouter</button>
    </form>
</div>

<div class="card">
    <div class="card-header">
        <h3>Liste des employés</h3>
        <span class="badge-dark"><?= count($employees) ?> employé(s)</span>
    </div>
    <div class="table-scroll">
        <table class="table">
            <thead>
                <tr><th>Matricule</th><th>Nom</th><th>Prénom</th><th>Groupe</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($employees as $e): ?>
                <?php if ($editId === (int) $e['id']): ?>
                    <tr>
                        <td colspan="5">
                            <form method="post" class="inline-form">
                                <input type="hidden" name="action" value="edit">
                                <input type="hidden" name="id" value="<?= $e['id'] ?>">
                                <input type="text" name="matricule" value="<?= htmlspecialchars($e['matricule']) ?>" required>
                                <input type="text" name="nom" value="<?= htmlspecialchars($e['nom']) ?>" required>
                                <input type="text" name="prenom" value="<?= htmlspecialchars($e['prenom']) ?>">
                                <input type="text" name="groupe" value="<?= htmlspecialchars($e['groupe']) ?>">
                                <button type="submit" class="btn btn-primary">&#10003; Enregistrer</button>
                                <a href="employees.php" class="btn btn-outline">Annuler</a>
                            </form>
                        </td>
                    </tr>
                <?php else: ?>
                    <tr>
                        <td><?= htmlspecialchars($e['matricule']) ?></td>
                        <td><?= htmlspecialchars($e['nom']) ?></td>
                        <td><?= htmlspecialchars($e['prenom']) ?></td>
                        <td><?= htmlspecialchars($e['groupe']) ?></td>
                        <td>
                            <a href="?edit=<?= $e['id'] ?>" class="btn-edit btn-resolve" style="text-decoration:none;">&#9998; Modifier</a>
                            <a href="?delete=<?= $e['id'] ?>" class="link-danger"
                               onclick="return confirm('Supprimer cet employé ?');">Supprimer</a>
                        </td>
                    </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if (empty($employees)): ?>
                <tr><td colspan="5" class="empty">Aucun employé enregistré.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
