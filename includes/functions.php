<?php
/**
 * Fonctions utilitaires du système de pointage
 */

/**
 * Normalise un intitulé de colonne : minuscules + accents français
 * retirés, pour que la correspondance des en-têtes ne dépende plus de la
 * présence ou non d'un accent (ex. "Evènement", "Évènement", "Evenement"
 * sont tous reconnus comme la même colonne).
 */
function normalizeHeaderLabel(string $h): string
{
    // Retire un éventuel BOM UTF-8 (souvent ajouté par Excel/Windows lors
    // d'un "Enregistrer sous CSV UTF-8"), qui sinon empêche la toute
    // première colonne (souvent "Date") d'être reconnue.
    $h = str_replace("\xEF\xBB\xBF", '', $h);
    // Uniformise les apostrophes "intelligentes" (’ ‘ `) qu'Excel/Word
    // substituent parfois automatiquement à l'apostrophe simple ('),
    // ce qui sinon empêche "ID de l'Equipement" d'être reconnu.
    $h = str_replace(["’", "‘", "`", "´"], "'", $h);
    $h = strtolower(trim($h));
    // Espaces multiples -> un seul espace
    $h = preg_replace('/\s+/', ' ', $h);
    static $map = [
        'à' => 'a', 'â' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'î' => 'i', 'ï' => 'i',
        'ô' => 'o', 'ö' => 'o',
        'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c',
    ];
    return strtr($h, $map);
}

/**
 * Construit les paramètres d'insertion "pointages" à partir d'une ligne
 * (tableau associatif en-tête-normalisée => valeur), commune au CSV
 * et au XLSX. Les clés de $data doivent avoir été passées par
 * normalizeHeaderLabel() (minuscules, sans accents).
 *
 * Formats d'en-têtes reconnus automatiquement :
 *  - Format "export badgeuse" : Date, Porte, ID de l'Equipement,
 *    Equipement, Group(e) Utilisateur, Utilisateur, Evenement/Evènement
 *  - Format "tri/rapport" (ex. classeur Excel journalier) : Date,
 *    Date modifiée, Equipement, Utilisateur, Check, #ce, Tot heure
 *    (Porte, ID Equipement et Groupe Utilisateur sont alors absents et
 *    laissés vides ; "Check" sert de champ Evenement : "OK" devient
 *    "Succès de l'Authentification", toute autre valeur est conservée
 *    telle quelle et remontée comme anomalie).
 *
 * Le champ "Utilisateur" peut être au format "509(ANDRIANORO Sedera Josué)".
 *
 * Certaines lignes d'un export "brut" ne concernent pas un utilisateur
 * (ex. "Porte Verrouillée", "Porte déverrouillée", évènements matériels
 * de la badgeuse) : elles n'ont pas de champ Utilisateur rempli. Ces
 * lignes ne sont pas des pointages -> retourne NULL pour les ignorer
 * silencieusement (elles ne comptent pas comme des erreurs).
 *
 * Retourne un tableau de paramètres prêt pour le PDOStatement, NULL si
 * la ligne doit être ignorée silencieusement (pas un pointage), ou une
 * chaîne de caractères décrivant l'erreur si la ligne est invalide.
 */
function buildPointageParams(array $data): array|string|null
{
    $rawDate = trim((string)($data['date'] ?? ''));
    $ts = is_numeric($rawDate) ? excelSerialToTimestamp((float)$rawDate) : strtotime($rawDate);
    if (!$ts) {
        return "date invalide (\"{$rawDate}\")";
    }

    $utilisateurRaw = trim((string)($data['utilisateur'] ?? ''));
    $matricule = null;
    $nom = $utilisateurRaw;
    if (preg_match('/^(\d+)\s*\((.+)\)$/', $utilisateurRaw, $m)) {
        $matricule = $m[1];
        $nom = $m[2];
    }
    // Une ligne sans utilisateur (ex. évènement matériel de la badgeuse
    // "Porte Verrouillée") est quand même importée et affichée (tout le
    // contenu du fichier source doit apparaître dans les résultats),
    // mais avec un nom vide : elle est ensuite exclue des calculs
    // (anomalies, heures travaillées, liste des employés) qui ne
    // concernent que de vrais pointages utilisateur.

    // Le champ "Evenement" peut aussi arriver sous le nom "Check"
    // (format tri/rapport, où la valeur est en général "OK").
    $checkRaw = trim((string)($data['evenement'] ?? $data['check'] ?? ''));
    if ($checkRaw === '' || strtoupper($checkRaw) === 'OK') {
        $evenement = "Succès de l'Authentification";
    } else {
        $evenement = $checkRaw; // valeur brute conservée -> anomalie possible
    }

    return [
        ':date_heure'     => date('Y-m-d H:i:s', $ts),
        ':porte'          => trim((string)($data['porte'] ?? '')),
        ':equipement_id'  => trim((string)($data["id de l'equipement"] ?? $data['id equipement'] ?? findValueByKeyContaining($data, ['id', 'equipement']) ?? '')),
        ':equipement_nom' => trim((string)($data['equipement'] ?? '')),
        ':groupe'         => trim((string)($data['group utilisateur'] ?? $data['groupe utilisateur'] ?? 'All Users')),
        ':matricule'      => $matricule,
        ':nom'            => $nom,
        ':evenement'      => $evenement,
    ];
}

/**
 * Filet de sécurité : cherche, parmi les clés d'un tableau, la première
 * qui contient tous les fragments donnés (utile quand l'intitulé exact
 * d'une colonne varie légèrement d'un export à l'autre).
 */
function findValueByKeyContaining(array $data, array $fragments): ?string
{
    foreach ($data as $key => $value) {
        $ok = true;
        foreach ($fragments as $frag) {
            if (stripos((string) $key, $frag) === false) {
                $ok = false;
                break;
            }
        }
        if ($ok) {
            return (string) $value;
        }
    }
    return null;
}

/**
 * Convertit un numéro de série de date Excel (ex. 45838.749) en timestamp Unix.
 */
function excelSerialToTimestamp(float $serial): int
{
    // 25569 = nombre de jours entre 1899-12-30 (époque Excel) et 1970-01-01 (époque Unix)
    $days = $serial - 25569;
    return (int) round($days * 86400);
}

/**
 * Apprend la correspondance Équipement -> Porte à partir d'un ensemble de
 * lignes déjà découpées en colonnes (en-têtes normalisées). Certaines
 * lignes "techniques" (évènements matériels de la porte, sans
 * utilisateur) renseignent la Porte ; on s'en sert pour déduire la Porte
 * des lignes d'authentification du même équipement, qui elles ne
 * l'indiquent pas. Retourne un tableau [equipement_nom => porte].
 */
function learnEquipementPorteMap(array $rows): array
{
    $counts = []; // [equipement_nom][porte] => nombre d'occurrences
    foreach ($rows as $entry) {
        $data = $entry['data'];
        $porte = trim((string)($data['porte'] ?? ''));
        $equip = trim((string)($data['equipement'] ?? ''));
        if ($porte === '' || $equip === '') {
            continue;
        }
        $counts[$equip][$porte] = ($counts[$equip][$porte] ?? 0) + 1;
    }

    $map = [];
    foreach ($counts as $equip => $portes) {
        arsort($portes); // la porte la plus fréquente pour cet équipement
        $map[$equip] = array_key_first($portes);
    }
    return $map;
}

/**
 * Filet de sécurité final quand aucune ligne du fichier ne permet
 * d'apprendre la correspondance Équipement -> Porte (ex. fichier qui ne
 * contient aucune ligne technique avec la Porte renseignée) : déduit la
 * porte du nom de l'équipement lui-même, sur la convention observée
 * "R2" = sortie, "P2" = entrée (ex. "BioEntry R2 CENTRE").
 */
function guessPorteFromEquipementName(string $equipNom): ?string
{
    if (preg_match('/\bR\d\b/i', $equipNom)) {
        return 'PORTE SORTIE';
    }
    if (preg_match('/\bP\d\b/i', $equipNom)) {
        return 'PORTE ENTREE';
    }
    return null;
}

/**
 * Point d'entrée générique : détecte l'extension du fichier et appelle
 * l'importeur adapté (CSV ou XLSX).
 */
function importPointagesFile(PDO $pdo, string $filePath, string $originalName): array
{
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($ext === 'xlsx') {
        return importXlsxPointages($pdo, $filePath);
    }
    return importCsvPointages($pdo, $filePath);
}

/**
 * Importe un fichier CSV (une seule feuille de données) dans la table pointages.
 */
function importCsvPointages(PDO $pdo, string $filePath): array
{
    $inserted = 0;
    $duplicates = 0;
    $errors   = [];

    if (($handle = fopen($filePath, 'r')) === false) {
        return ['inserted' => 0, 'duplicates' => 0, 'errors' => ["Impossible d'ouvrir le fichier CSV."]];
    }

    // Détection auto du séparateur (',' ou ';')
    $firstLine = fgets($handle);
    $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';
    rewind($handle);

    $header = fgetcsv($handle, 0, $delimiter);
    if ($header === false) {
        fclose($handle);
        return ['inserted' => 0, 'duplicates' => 0, 'errors' => ['Fichier CSV vide.']];
    }
    $header = array_map(fn($h) => normalizeHeaderLabel((string)$h), $header);

    // Lecture complète en mémoire : nécessaire pour apprendre la
    // correspondance Équipement -> Porte (déduite des lignes "techniques"
    // sans utilisateur, comme "Porte Verrouillée") AVANT d'insérer les
    // lignes de pointage utilisateur, quel que soit l'ordre des lignes
    // dans le fichier.
    $allRows = [];
    $line = 1;
    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        $line++;
        if (count(array_filter($row, fn($v) => trim((string)$v) !== '')) === 0) {
            continue; // ligne vide
        }
        $data = @array_combine($header, $row);
        if ($data === false) {
            $errors[] = "Ligne {$line} : nombre de colonnes incohérent.";
            continue;
        }
        $allRows[] = ['line' => $line, 'data' => $data];
    }
    fclose($handle);

    $equipMap = learnEquipementPorteMap($allRows);

    // INSERT IGNORE : si une ligne strictement identique (même date, même
    // utilisateur, même équipement, même porte) existe déjà, elle est
    // silencieusement ignorée grâce à la contrainte UNIQUE sur la table
    // (voir database/schema.sql). Cela évite les doublons quand la même
    // journée apparaît dans plusieurs feuilles/fichiers importés.
    $stmt = $pdo->prepare(
        "INSERT IGNORE INTO pointages
            (date_heure, porte, equipement_id, equipement_nom, groupe_utilisateur, utilisateur_matricule, utilisateur_nom, evenement)
         VALUES (:date_heure, :porte, :equipement_id, :equipement_nom, :groupe, :matricule, :nom, :evenement)"
    );

    foreach ($allRows as $entry) {
        $line = $entry['line'];
        $data = $entry['data'];

        // Complète la Porte manquante grâce à la correspondance apprise
        if (trim((string)($data['porte'] ?? '')) === '') {
            $equipKey = trim((string)($data['equipement'] ?? ''));
            if ($equipKey !== '') {
                if (isset($equipMap[$equipKey])) {
                    $data['porte'] = $equipMap[$equipKey];
                } else {
                    $guess = guessPorteFromEquipementName($equipKey);
                    if ($guess !== null) {
                        $data['porte'] = $guess;
                    }
                }
            }
        }

        $params = buildPointageParams($data);
        if ($params === null) {
            continue; // ligne technique (ex. évènement de porte) ignorée silencieusement
        }
        if (is_string($params)) {
            $errors[] = "Ligne {$line} : {$params}.";
            continue;
        }

        try {
            $stmt->execute($params);
            if ($stmt->rowCount() > 0) {
                $inserted++;
            } else {
                $duplicates++;
            }
        } catch (PDOException $e) {
            $errors[] = "Ligne {$line} : erreur d'insertion (" . $e->getMessage() . ")";
        }
    }

    // Déduit les portes manquantes à partir de l'équipement, puis relance
    // la détection d'anomalies après import
    inferMissingPortes($pdo);
    detectAnomalies($pdo);

    return ['inserted' => $inserted, 'duplicates' => $duplicates, 'errors' => $errors];
}

/**
 * Importe un classeur Excel (.xlsx) dans la table pointages, en parcourant
 * TOUTES les feuilles du classeur (ex. un onglet par jour). N'a besoin
 * d'aucune librairie externe : lit directement le zip XLSX (sharedStrings,
 * worksheets) via ZipArchive + SimpleXML.
 */
function importXlsxPointages(PDO $pdo, string $filePath): array
{
    $inserted = 0;
    $duplicates = 0;
    $errors   = [];

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        return ['inserted' => 0, 'duplicates' => 0, 'errors' => ["Impossible d'ouvrir le fichier .xlsx (archive invalide)."]];
    }

    // 1. Chaînes partagées (sharedStrings.xml)
    $sharedStrings = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $sx = simplexml_load_string($sharedXml);
        if ($sx !== false) {
            foreach ($sx->si as $si) {
                // Le texte peut être direct (<t>) ou réparti en plusieurs <r><t>
                if (isset($si->t)) {
                    $sharedStrings[] = (string) $si->t;
                } else {
                    $text = '';
                    foreach ($si->r as $r) {
                        $text .= (string) $r->t;
                    }
                    $sharedStrings[] = $text;
                }
            }
        }
    }

    // 2. Liste des feuilles (xl/workbook.xml + relations)
    $workbookXml = $zip->getFromName('xl/workbook.xml');
    $relsXml     = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($workbookXml === false || $relsXml === false) {
        $zip->close();
        return ['inserted' => 0, 'errors' => ["Fichier .xlsx invalide (workbook.xml introuvable)."]];
    }

    $wb   = simplexml_load_string($workbookXml);
    $rels = simplexml_load_string($relsXml);

    // rId -> chemin du fichier de la feuille
    $relMap = [];
    foreach ($rels->Relationship as $rel) {
        $relMap[(string) $rel['Id']] = 'xl/' . (string) $rel['Target'];
    }

    $sheets = []; // [nom => chemin]
    $ns = $wb->getNamespaces(true);
    $rNs = $ns['r'] ?? 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    foreach ($wb->sheets->sheet as $sheet) {
        $attrs = $sheet->attributes($rNs);
        $rId = (string) ($attrs['id'] ?? '');
        $name = (string) $sheet['name'];
        if (isset($relMap[$rId])) {
            $sheets[$name] = $relMap[$rId];
        }
    }

    if (empty($sheets)) {
        $zip->close();
        return ['inserted' => 0, 'duplicates' => 0, 'errors' => ['Aucune feuille trouvée dans le classeur.']];
    }

    $stmt = $pdo->prepare(
        "INSERT IGNORE INTO pointages
            (date_heure, porte, equipement_id, equipement_nom, groupe_utilisateur, utilisateur_matricule, utilisateur_nom, evenement)
         VALUES (:date_heure, :porte, :equipement_id, :equipement_nom, :groupe, :matricule, :nom, :evenement)"
    );

    // 3. Parcourt chaque feuille (chaque onglet = potentiellement un jour)
    //    Passe 1 : on lit toutes les lignes en mémoire sans les insérer,
    //    pour pouvoir apprendre la correspondance Équipement -> Porte à
    //    partir de TOUTES les feuilles avant d'insérer quoi que ce soit.
    $allRows = []; // [ ['sheet'=>, 'rowNum'=>, 'data'=>] ]
    foreach ($sheets as $sheetName => $sheetPath) {
        $sheetXml = $zip->getFromName($sheetPath);
        if ($sheetXml === false) {
            $errors[] = "Feuille \"{$sheetName}\" : fichier introuvable dans l'archive.";
            continue;
        }
        $sx = simplexml_load_string($sheetXml);
        if ($sx === false || !isset($sx->sheetData->row)) {
            continue; // feuille vide
        }

        $header = null;
        $rowNum = 0;
        foreach ($sx->sheetData->row as $row) {
            $rowNum++;
            $cells = [];
            foreach ($row->c as $c) {
                $ref = (string) $c['r']; // ex "B3"
                preg_match('/^([A-Z]+)/', $ref, $mm);
                $colLetters = $mm[1] ?? '';
                $colIndex = xlsxColumnToIndex($colLetters);

                $type = (string) ($c['t'] ?? '');
                if ($type === 's') {
                    $idx = (int) $c->v;
                    $value = $sharedStrings[$idx] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = isset($c->is->t) ? (string) $c->is->t : '';
                } elseif ($type === 'str' || $type === 'b') {
                    $value = (string) $c->v;
                } else {
                    $value = isset($c->v) ? (string) $c->v : '';
                }
                $cells[$colIndex] = $value;
            }
            if (empty($cells)) {
                continue;
            }
            $maxCol = max(array_keys($cells));
            $ordered = [];
            for ($i = 0; $i <= $maxCol; $i++) {
                $ordered[] = $cells[$i] ?? '';
            }

            if ($header === null) {
                $header = array_map(fn($h) => normalizeHeaderLabel((string)$h), $ordered);
                continue;
            }

            if (count(array_filter($ordered, fn($v) => trim((string)$v) !== '')) === 0) {
                continue; // ligne vide
            }

            $data = [];
            foreach ($header as $i => $h) {
                if ($h !== '') {
                    $data[$h] = $ordered[$i] ?? '';
                }
            }

            $allRows[] = ['sheet' => $sheetName, 'rowNum' => $rowNum, 'data' => $data];
        }
    }

    $zip->close();

    $equipMap = learnEquipementPorteMap($allRows);

    // Passe 2 : insertion effective, avec complément de la Porte manquante
    foreach ($allRows as $entry) {
        $sheetName = $entry['sheet'];
        $rowNum    = $entry['rowNum'];
        $data      = $entry['data'];

        if (trim((string)($data['porte'] ?? '')) === '') {
            $equipKey = trim((string)($data['equipement'] ?? ''));
            if ($equipKey !== '') {
                if (isset($equipMap[$equipKey])) {
                    $data['porte'] = $equipMap[$equipKey];
                } else {
                    $guess = guessPorteFromEquipementName($equipKey);
                    if ($guess !== null) {
                        $data['porte'] = $guess;
                    }
                }
            }
        }

        $params = buildPointageParams($data);
        if ($params === null) {
            continue; // ligne technique (ex. évènement de porte) ignorée silencieusement
        }
        if (is_string($params)) {
            $errors[] = "Feuille \"{$sheetName}\", ligne {$rowNum} : {$params}.";
            continue;
        }

        try {
            $stmt->execute($params);
            if ($stmt->rowCount() > 0) {
                $inserted++;
            } else {
                $duplicates++;
            }
        } catch (PDOException $e) {
            $errors[] = "Feuille \"{$sheetName}\", ligne {$rowNum} : erreur d'insertion (" . $e->getMessage() . ")";
        }
    }

    // Déduit les portes manquantes à partir de l'équipement, puis relance
    // la détection d'anomalies après import
    inferMissingPortes($pdo);
    detectAnomalies($pdo);

    return ['inserted' => $inserted, 'duplicates' => $duplicates, 'errors' => $errors];
}

/**
 * Convertit une référence de colonne Excel ("A", "B", ... "AA") en index
 * numérique base 0 ("A" => 0, "B" => 1, ... "AA" => 26).
 */
function xlsxColumnToIndex(string $letters): int
{
    $letters = strtoupper($letters);
    $index = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $index = $index * 26 + (ord($letters[$i]) - ord('A') + 1);
    }
    return $index - 1;
}

/**
 * Détecte les anomalies dans les pointages :
 *  - Échecs d'authentification (Evenement/Check différent d'un succès)
 *  - Doublons : même utilisateur, même porte, écart < 60s
 *  - Sortie sans entrée préalable le même jour (uniquement si la colonne
 *    Porte distingue bien entrée/sortie ; sinon ce cas ne se déclenche pas)
 */
function detectAnomalies(PDO $pdo): int
{
    // On retient les pointages dont l'anomalie avait déjà été marquée
    // "résolue" manuellement, pour ne pas la faire réapparaître après un
    // nouvel import ou un recalcul.
    $resolvedPointageIds = $pdo->query(
        "SELECT DISTINCT pointage_id FROM anomalies WHERE resolved = 1"
    )->fetchAll(PDO::FETCH_COLUMN);
    $resolvedSet = array_flip($resolvedPointageIds);

    $pdo->exec("DELETE FROM anomalies"); // on recalcule à chaque passage
    $found = 0;

    $rows = $pdo->query(
        "SELECT id, date_heure, porte, evenement, utilisateur_matricule, utilisateur_nom
         FROM pointages
         WHERE utilisateur_nom IS NOT NULL AND utilisateur_nom <> ''
           AND evenement LIKE '%uthentification%'
         ORDER BY utilisateur_matricule, date_heure"
    )->fetchAll();

    $insertAnomaly = $pdo->prepare(
        "INSERT INTO anomalies (pointage_id, type, description, resolved) VALUES (:pid, :type, :desc, :resolved)"
    );

    $lastByUser = [];
    $dayState   = []; // [matricule][date] => 'in' | 'out'

    foreach ($rows as $r) {
        $key = $r['utilisateur_matricule'] ?? $r['utilisateur_nom'];
        $day = substr($r['date_heure'], 0, 10);
        $wasResolved = isset($resolvedSet[$r['id']]) ? 1 : 0;

        // Échec d'authentification (colonne Check/Evenement différente de "Succès")
        if (stripos($r['evenement'], 'succ') === false) {
            $insertAnomaly->execute([
                ':pid'  => $r['id'],
                ':type' => "Échec d'authentification",
                ':desc' => "{$r['utilisateur_nom']} : évènement \"{$r['evenement']}\" au lieu d'un succès, le {$day}.",
                ':resolved' => $wasResolved,
            ]);
            $found++;
        }

        // Doublon (< 60s d'écart, même porte, même utilisateur)
        if (isset($lastByUser[$key]) && $lastByUser[$key]['porte'] === $r['porte']) {
            $diff = strtotime($r['date_heure']) - strtotime($lastByUser[$key]['date_heure']);
            if ($diff >= 0 && $diff < 60) {
                $insertAnomaly->execute([
                    ':pid'  => $r['id'],
                    ':type' => 'Doublon',
                    ':desc' => "Pointage en double pour {$r['utilisateur_nom']} à {$r['porte']} (moins de 60s après le précédent).",
                    ':resolved' => $wasResolved,
                ]);
                $found++;
            }
        }

        // Sortie sans entrée
        $isSortie = stripos($r['porte'], 'sortie') !== false;
        $isEntree = stripos($r['porte'], 'entr') !== false;
        if ($isSortie && ($dayState[$key][$day] ?? null) !== 'in') {
            $insertAnomaly->execute([
                ':pid'  => $r['id'],
                ':type' => 'Sortie sans entrée',
                ':desc' => "{$r['utilisateur_nom']} a une sortie enregistrée le {$day} sans entrée préalable.",
                ':resolved' => $wasResolved,
            ]);
            $found++;
        }
        if ($isEntree) {
            $dayState[$key][$day] = 'in';
        } elseif ($isSortie) {
            $dayState[$key][$day] = 'out';
        }

        $lastByUser[$key] = $r;
    }

    return $found;
}

function formatDateFr(string $datetime): string
{
    $ts = strtotime($datetime);
    return $ts ? date('Y-m-d H:i:s', $ts) : $datetime;
}

/**
 * Certains exports BioEntry renseignent la colonne "Porte" uniquement sur
 * les évènements matériels (ouverture/verrouillage de la porte), mais pas
 * sur les lignes d'authentification d'un utilisateur — alors que
 * chaque équipement (borne) correspond toujours à une seule et même porte
 * (ex. "BioEntry R2 CENTRE" = toujours "PORTE SORTIE"). Cette fonction
 * apprend cette correspondance à partir des lignes où Porte est déjà
 * renseigné, puis complète les lignes où Porte est vide pour le même
 * équipement. Retourne le nombre de lignes complétées.
 */
function inferMissingPortes(PDO $pdo): int
{
    $learned = $pdo->query(
        "SELECT equipement_nom, porte, COUNT(*) AS c
         FROM pointages
         WHERE porte <> '' AND equipement_nom <> ''
         GROUP BY equipement_nom, porte"
    )->fetchAll();

    $map = []; // equipement_nom => porte la plus fréquente pour cet équipement
    foreach ($learned as $row) {
        $key = $row['equipement_nom'];
        if (!isset($map[$key]) || $row['c'] > $map[$key]['c']) {
            $map[$key] = ['porte' => $row['porte'], 'c' => $row['c']];
        }
    }

    $updated = 0;
    $stmt = $pdo->prepare(
        "UPDATE pointages SET porte = :porte
         WHERE equipement_nom = :equip AND (porte IS NULL OR porte = '')"
    );
    foreach ($map as $equip => $info) {
        $stmt->execute([':porte' => $info['porte'], ':equip' => $equip]);
        $updated += $stmt->rowCount();
    }

    return $updated;
}

/**
 * Calcule les heures travaillées par employé et par jour, en associant
 * chaque pointage "entrée" au pointage "sortie" suivant (le premier
 * trouvé). Une entrée sans sortie correspondante, ou une sortie sans
 * entrée préalable, n'est pas comptabilisée (ce sont déjà des anomalies
 * remontées ailleurs).
 *
 * Retourne un tableau de lignes : matricule, nom, jour (Y-m-d), et
 * seconds (durée cumulée en secondes ce jour-là), trié par nom puis jour.
 */
/**
 * Calcule les heures travaillées par employé, en associant chaque
 * pointage "entrée" au pointage "sortie" suivant (y compris si la
 * sortie a lieu le lendemain, pour les équipes de nuit). La durée est
 * comptabilisée sur le jour de l'ENTRÉE. Une paire dont la durée dépasse
 * 16h est ignorée (probable pointage manquant entre les deux), tout
 * comme une entrée sans sortie correspondante ou une sortie sans entrée
 * préalable (déjà remontées comme anomalies ailleurs).
 *
 * Retourne un tableau de lignes : matricule, nom, jour (Y-m-d), et
 * seconds (durée cumulée ce jour-là), trié par nom puis jour.
 */
/**
 * Règle métier constatée dans les exports Excel de référence : tout
 * pointage dont l'heure tombe dans la pause déjeuner (12h30 à 13h30) est
 * traité, pour le calcul des heures travaillées, comme s'il avait eu
 * lieu exactement à 13h30:00 — afin que la durée exacte de la pause
 * (que l'employé parte/revienne un peu avant ou après) n'influence jamais
 * le total d'heures. Sans cette règle, le total diverge de l'export
 * Excel de référence (ex. ~35 min d'écart constaté sur un cas réel).
 */
function applyLunchRounding(int $ts): int
{
    $timeOfDay = date('H:i:s', $ts);
    if ($timeOfDay >= '12:30:00' && $timeOfDay < '13:30:00') {
        return strtotime(date('Y-m-d', $ts) . ' 13:30:00');
    }
    return $ts;
}

function calculateWorkedHours(PDO $pdo, ?string $from = null, ?string $to = null, ?string $matricule = null): array
{
    $maxShiftSeconds = 16 * 3600;

    $sql = "SELECT id, date_heure, porte, utilisateur_matricule, utilisateur_nom
            FROM pointages
            WHERE utilisateur_nom IS NOT NULL AND utilisateur_nom <> ''
              AND evenement LIKE '%uthentification%'";
    $params = [];
    if ($from) { $sql .= " AND date_heure >= :from"; $params[':from'] = $from . ' 00:00:00'; }
    if ($to)   { $sql .= " AND date_heure <= :to";   $params[':to']   = $to . ' 23:59:59'; }
    if ($matricule) { $sql .= " AND utilisateur_matricule = :mat"; $params[':mat'] = $matricule; }
    $sql .= " ORDER BY utilisateur_matricule, utilisateur_nom, date_heure";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $pointages = $stmt->fetchAll();

    $openEntry = []; // [key] => ['ts' => timestamp (ajusté pause déj.), 'day' => Y-m-d du jour de l'entrée, 'id' => id du pointage entrée]
    $totals    = []; // [key][day] => ['seconds' => .., 'entry_id' => .., 'exit_id' => ..] (dernière paire du jour)
    $names     = []; // [key] => ['matricule' => ..., 'nom' => ...]

    foreach ($pointages as $p) {
        $key = $p['utilisateur_matricule'] ?? $p['utilisateur_nom'];
        $names[$key] = ['matricule' => $p['utilisateur_matricule'], 'nom' => $p['utilisateur_nom']];
        $day = substr($p['date_heure'], 0, 10);
        $ts  = applyLunchRounding(strtotime($p['date_heure']));

        $isEntree = stripos($p['porte'], 'entr') !== false;
        $isSortie = stripos($p['porte'], 'sortie') !== false;

        if ($isEntree) {
            // Une nouvelle entrée remplace une entrée en attente non "fermée"
            $openEntry[$key] = ['ts' => $ts, 'day' => $day, 'id' => $p['id']];
        } elseif ($isSortie && isset($openEntry[$key])) {
            $duration = $ts - $openEntry[$key]['ts'];
            if ($duration > 0 && $duration <= $maxShiftSeconds) {
                $entryDay = $openEntry[$key]['day'];
                if (!isset($totals[$key][$entryDay])) {
                    $totals[$key][$entryDay] = ['seconds' => 0, 'entry_id' => null, 'exit_id' => null];
                }
                $totals[$key][$entryDay]['seconds'] += $duration;
                $totals[$key][$entryDay]['entry_id'] = $openEntry[$key]['id'];
                $totals[$key][$entryDay]['exit_id']  = $p['id'];
            }
            unset($openEntry[$key]);
        }
    }

    $result = [];
    foreach ($totals as $key => $days) {
        foreach ($days as $day => $info) {
            $result[] = [
                'matricule' => $names[$key]['matricule'],
                'nom'       => $names[$key]['nom'],
                'jour'      => $day,
                'seconds'   => $info['seconds'],
                'entry_id'  => $info['entry_id'],
                'exit_id'   => $info['exit_id'],
            ];
        }
    }

    usort($result, fn($a, $b) => [$a['nom'], $a['jour']] <=> [$b['nom'], $b['jour']]);

    return $result;
}

/**
 * Formate une durée en secondes en "HHh MMmin".
 */
function formatDuration(int $seconds): string
{
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    return sprintf('%dh %02dmin', $h, $m);
}

/**
 * Ajoute automatiquement à la table employees tout matricule/nom présent
 * dans les pointages mais absent de la liste des employés (utile après
 * un import massif). Retourne le nombre d'employés ajoutés.
 */
/**
 * Resynchronise entièrement la table employees pour qu'elle corresponde
 * exactement aux employés présents dans les pointages importés :
 *  - ajoute les matricules manquants
 *  - supprime les employés qui n'apparaissent dans AUCUN pointage
 * (les employés ajoutés manuellement sans aucun pointage sont donc
 * retirés eux aussi : cette fonction rend la liste "Employés" le reflet
 * exact des données réellement importées).
 * Retourne ['added' => X, 'removed' => Y].
 */
function syncEmployeesFromPointages(PDO $pdo): array
{
    // Clé de synchronisation : le matricule s'il existe, sinon le nom lui-
    // même (COALESCE), pour que TOUT utilisateur présent dans les
    // pointages ait une ligne "Employés", même sans matricule numérique.
    $rows = $pdo->query(
        "SELECT DISTINCT
             COALESCE(NULLIF(utilisateur_matricule, ''), utilisateur_nom) AS cle,
             utilisateur_matricule AS matricule,
             utilisateur_nom AS nom
         FROM pointages
         WHERE utilisateur_nom IS NOT NULL AND utilisateur_nom <> ''
           AND COALESCE(NULLIF(utilisateur_matricule, ''), utilisateur_nom) NOT IN (SELECT matricule FROM employees)"
    )->fetchAll();

    $stmt = $pdo->prepare(
        "INSERT IGNORE INTO employees (matricule, nom, prenom, groupe) VALUES (:m, :n, '', 'All Users')"
    );
    $added = 0;
    foreach ($rows as $r) {
        // Le nom peut être "NOM Prénom" -> on le garde tel quel dans le champ nom
        $stmt->execute([':m' => $r['cle'], ':n' => $r['nom']]);
        if ($stmt->rowCount() > 0) {
            $added++;
        }
    }

    $removeStmt = $pdo->prepare(
        "DELETE FROM employees
         WHERE matricule NOT IN (
             SELECT DISTINCT COALESCE(NULLIF(utilisateur_matricule, ''), utilisateur_nom) FROM pointages
             WHERE utilisateur_nom IS NOT NULL AND utilisateur_nom <> ''
         )"
    );
    $removeStmt->execute();
    $removed = $removeStmt->rowCount();

    return ['added' => $added, 'removed' => $removed];
}
