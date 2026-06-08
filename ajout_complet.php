<?php
// ============================================================
//  ajout_complet.php  –  Backend CRUD (PHP 5.5 compatible)
//  Modifié : validation matricule sur nom PDF + endpoint AJAX
// ============================================================

/* ── Connexion PDO ─────────────────────────────────────────── */
$host   = "localhost";
$dbname = "gestion_pdf";
$user   = "root";
$pass   = "";

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8",
        $user, $pass
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die("Connexion BD : " . $e->getMessage());
}

/* ── Helper : historique ────────────────────────────────────── */
function logAction($pdo, $utilisateur_id, $action, $nom_fichier = null, $details = null)
{
    $utilisateur_id = (int)$utilisateur_id;
    $stmt = $pdo->prepare(
        "INSERT INTO historique_actions (utilisateur_id, action, nom_fichier, details)
         VALUES (?, ?, ?, ?)"
    );
    $stmt->execute(array($utilisateur_id, $action, $nom_fichier, $details));
}

/* ── Helper : redirection avec message ─────────────────────── */
function redirect($url, $msg, $ok = true)
{
    $param = $ok ? 'success' : 'error';
    header("Location: $url&$param=" . urlencode($msg));
    exit;
}

/* ── Helper : valider une date YYYY-MM-DD ──────────────────── */
function validerDate($date)
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $parts = explode('-', $date);
        return checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0]);
    }
    return false;
}

/* ── Helper : réponse JSON (pour AJAX) ─────────────────────── */
function jsonReponse($ok, $msg)
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('ok' => $ok, 'msg' => $msg));
    exit;
}

/**
 * ── Helper : vérifier que le nom du fichier commence par le matricule
 *    Le nom doit commencer par MATRICULE_ ou MATRICULE-  (insensible à la casse)
 */
function nomFichierValide($nomFichier, $matricule)
{
    $nomUpper = strtoupper($nomFichier);
    $matUpper = strtoupper($matricule);
    return (
        strpos($nomUpper, $matUpper . '_') === 0 ||
        strpos($nomUpper, $matUpper . '-') === 0
    );
}

$action = isset($_POST['action']) ? $_POST['action'] : '';

// ============================================================
//  ACTION AJAX : Ajouter un PDF depuis l'upload groupé
//  Appelé par XMLHttpRequest depuis la page upload_groupe
//  Note : ici la correspondance matricule/fichier est déjà
//  vérifiée côté client ; on revérifie côté serveur.
// ============================================================
if ($action === 'ajouter_pdf_ajax') {

    $uid = (int)(isset($_POST['utilisateur_id']) ? $_POST['utilisateur_id'] : 0);
    if ($uid <= 0) {
        jsonReponse(false, 'Identifiant utilisateur invalide.');
    }

    // Récupérer l'utilisateur et son matricule
    $chk = $pdo->prepare("SELECT id, matricule FROM utilisateurs WHERE id = ?");
    $chk->execute(array($uid));
    $userRow = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$userRow) {
        jsonReponse(false, 'Utilisateur introuvable.');
    }
    $matricule = $userRow['matricule'];

    if (empty($_FILES['pdf']['tmp_name']) || !is_array($_FILES['pdf']['tmp_name'])) {
        jsonReponse(false, 'Aucun fichier reçu.');
    }

    try {
        $pdo->beginTransaction();

        $stmtPdf = $pdo->prepare(
            "INSERT INTO pdf_fichiers (utilisateur_id, nom_fichier, pdf_data)
             VALUES (?, ?, ?)"
        );

        $nb       = 0;
        $rejetes  = array();

        foreach ($_FILES['pdf']['tmp_name'] as $i => $tmpFile) {
            if ($_FILES['pdf']['error'][$i] === UPLOAD_ERR_OK) {
                $nom_fichier = basename($_FILES['pdf']['name'][$i]);

                // ── Vérification matricule ──
                if (!nomFichierValide($nom_fichier, $matricule)) {
                    $rejetes[] = $nom_fichier;
                    continue;
                }

                $contenu = file_get_contents($tmpFile);
                $stmtPdf->bindParam(1, $uid,         PDO::PARAM_INT);
                $stmtPdf->bindParam(2, $nom_fichier);
                $stmtPdf->bindParam(3, $contenu,     PDO::PARAM_LOB);
                $stmtPdf->execute();
                logAction($pdo, $uid, 'AJOUT_PDF', $nom_fichier, 'Upload groupé par matricule');
                $nb++;
            }
        }

        $pdo->commit();

        if ($nb === 0 && count($rejetes) > 0) {
            jsonReponse(false,
                'Fichier(s) refusé(s) : le nom doit commencer par le matricule "' . $matricule . '". '
                . implode(', ', $rejetes)
            );
        }

        $msg = $nb . ' fichier(s) ajouté(s).';
        if (count($rejetes) > 0) {
            $msg .= ' Refusé(s) (matricule incorrect) : ' . implode(', ', $rejetes);
        }
        jsonReponse(true, $msg);

    } catch (Exception $e) {
        $pdo->rollBack();
        jsonReponse(false, 'Erreur BD : ' . $e->getMessage());
    }
}

// ============================================================
//  ACTION : Créer un utilisateur
// ============================================================
if ($action === 'creer_utilisateur') {

    $nom        = trim(isset($_POST['nom'])        ? $_POST['nom']        : '');
    $matricule  = strtoupper(trim(isset($_POST['matricule'])  ? $_POST['matricule']  : ''));
    $email      = trim(isset($_POST['email'])      ? $_POST['email']      : '');
    $date_ajout = trim(isset($_POST['date_ajout']) ? $_POST['date_ajout'] : '');

    if ($nom === '') {
        redirect('index.php?page=creer', 'Le nom est obligatoire.', false);
    }
    if ($matricule === '') {
        redirect('index.php?page=creer', 'Le matricule est obligatoire.', false);
    }
    if (!validerDate($date_ajout)) {
        redirect('index.php?page=creer', 'La date est invalide ou manquante.', false);
    }

    // Vérifier l'unicité du matricule
    $chkMat = $pdo->prepare("SELECT id FROM utilisateurs WHERE matricule = ?");
    $chkMat->execute(array($matricule));
    if ($chkMat->fetch()) {
        redirect('index.php?page=creer',
            "Le matricule « $matricule » est déjà utilisé.", false);
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "INSERT INTO utilisateurs (nom, matricule, email, date_ajout) VALUES (?, ?, ?, ?)"
        );
        $stmt->execute(array($nom, $matricule, $email, $date_ajout));
        $uid = (int)$pdo->lastInsertId();

        logAction($pdo, $uid, 'CREATION_UTILISATEUR', null,
            "Nom: $nom | Matricule: $matricule | Email: $email | Date: $date_ajout");

        // PDFs optionnels — vérification du nom
        $rejetes = array();
        if (!empty($_FILES['pdf']['tmp_name']) && is_array($_FILES['pdf']['tmp_name'])) {
            $stmtPdf = $pdo->prepare(
                "INSERT INTO pdf_fichiers (utilisateur_id, nom_fichier, pdf_data)
                 VALUES (?, ?, ?)"
            );
            foreach ($_FILES['pdf']['tmp_name'] as $i => $tmpFile) {
                if ($_FILES['pdf']['error'][$i] === UPLOAD_ERR_OK) {
                    $nom_fichier = basename($_FILES['pdf']['name'][$i]);

                    // ── Vérification matricule ──
                    if (!nomFichierValide($nom_fichier, $matricule)) {
                        $rejetes[] = $nom_fichier;
                        continue;
                    }

                    $contenu = file_get_contents($tmpFile);
                    $stmtPdf->bindParam(1, $uid,         PDO::PARAM_INT);
                    $stmtPdf->bindParam(2, $nom_fichier);
                    $stmtPdf->bindParam(3, $contenu,     PDO::PARAM_LOB);
                    $stmtPdf->execute();
                    logAction($pdo, $uid, 'AJOUT_PDF', $nom_fichier, "Upload initial");
                }
            }
        }

        $pdo->commit();

        $msgOk = "Utilisateur « $nom » créé avec succès !";
        if (count($rejetes) > 0) {
            $msgOk .= ' Fichier(s) ignoré(s) car le nom ne commence pas par le matricule : '
                . implode(', ', $rejetes);
        }
        redirect("index.php?page=gerer&uid=$uid", $msgOk);

    } catch (Exception $e) {
        $pdo->rollBack();
        redirect('index.php?page=creer', "Erreur : " . $e->getMessage(), false);
    }
}

// ============================================================
//  ACTION : Modifier nom / matricule / email / date
// ============================================================
if ($action === 'modifier_utilisateur') {

    $uid        = (int)(isset($_POST['utilisateur_id']) ? $_POST['utilisateur_id'] : 0);
    $nom        = trim(isset($_POST['nom'])        ? $_POST['nom']        : '');
    $matricule  = strtoupper(trim(isset($_POST['matricule'])  ? $_POST['matricule']  : ''));
    $email      = trim(isset($_POST['email'])      ? $_POST['email']      : '');
    $date_ajout = trim(isset($_POST['date_ajout']) ? $_POST['date_ajout'] : '');

    if ($uid <= 0 || $nom === '') {
        redirect("index.php?page=gerer&uid=$uid", 'Données invalides.', false);
    }
    if ($matricule === '') {
        redirect("index.php?page=gerer&uid=$uid", 'Le matricule est obligatoire.', false);
    }
    if (!validerDate($date_ajout)) {
        redirect("index.php?page=gerer&uid=$uid", 'Date invalide.', false);
    }

    // Vérifier l'unicité du matricule (sauf pour cet utilisateur lui-même)
    $chkMat = $pdo->prepare(
        "SELECT id FROM utilisateurs WHERE matricule = ? AND id != ?"
    );
    $chkMat->execute(array($matricule, $uid));
    if ($chkMat->fetch()) {
        redirect("index.php?page=gerer&uid=$uid",
            "Le matricule « $matricule » est déjà utilisé par un autre utilisateur.", false);
    }

    try {
        $stmt = $pdo->prepare(
            "UPDATE utilisateurs SET nom = ?, matricule = ?, email = ?, date_ajout = ? WHERE id = ?"
        );
        $stmt->execute(array($nom, $matricule, $email, $date_ajout, $uid));

        logAction($pdo, $uid, 'MODIFICATION_UTILISATEUR', null,
            "Nom: $nom | Matricule: $matricule | Email: $email | Date: $date_ajout");

        redirect("index.php?page=gerer&uid=$uid", "Informations mises à jour !");

    } catch (Exception $e) {
        redirect("index.php?page=gerer&uid=$uid", "Erreur : " . $e->getMessage(), false);
    }
}

// ============================================================
//  ACTION : Supprimer un utilisateur (+ ses PDFs en cascade)
// ============================================================
if ($action === 'supprimer_utilisateur') {

    $uid = (int)(isset($_POST['utilisateur_id']) ? $_POST['utilisateur_id'] : 0);

    if ($uid <= 0) {
        redirect('index.php?page=liste', 'Utilisateur invalide.', false);
    }

    try {
        $row = $pdo->prepare("SELECT nom FROM utilisateurs WHERE id = ?");
        $row->execute(array($uid));
        $nom = $row->fetchColumn();

        if (!$nom) {
            redirect('index.php?page=liste', 'Utilisateur introuvable.', false);
        }

        $del = $pdo->prepare("DELETE FROM utilisateurs WHERE id = ?");
        $del->execute(array($uid));

        redirect('index.php?page=liste&success=' . urlencode("Utilisateur « $nom » supprimé."), '', true);

    } catch (Exception $e) {
        redirect('index.php?page=liste', "Erreur : " . $e->getMessage(), false);
    }
}

// ============================================================
//  ACTION : Ajouter des PDFs à un utilisateur existant
// ============================================================
if ($action === 'ajouter_pdf') {

    $uid = (int)(isset($_POST['utilisateur_id']) ? $_POST['utilisateur_id'] : 0);
    if ($uid <= 0) {
        redirect('index.php?page=liste', 'Utilisateur invalide.', false);
    }

    // Récupérer le matricule de l'utilisateur
    $stmtUser = $pdo->prepare("SELECT matricule FROM utilisateurs WHERE id = ?");
    $stmtUser->execute(array($uid));
    $userRow = $stmtUser->fetch(PDO::FETCH_ASSOC);
    if (!$userRow) {
        redirect('index.php?page=liste', 'Utilisateur introuvable.', false);
    }
    $matricule = $userRow['matricule'];

    try {
        $pdo->beginTransaction();

        $stmtPdf = $pdo->prepare(
            "INSERT INTO pdf_fichiers (utilisateur_id, nom_fichier, pdf_data)
             VALUES (?, ?, ?)"
        );

        $nb      = 0;
        $rejetes = array();

        foreach ($_FILES['pdf']['tmp_name'] as $i => $tmpFile) {
            if ($_FILES['pdf']['error'][$i] === UPLOAD_ERR_OK) {
                $nom_fichier = basename($_FILES['pdf']['name'][$i]);

                // ── Vérification : le nom doit commencer par le matricule ──
                if (!nomFichierValide($nom_fichier, $matricule)) {
                    $rejetes[] = $nom_fichier;
                    continue;
                }

                $contenu = file_get_contents($tmpFile);
                $stmtPdf->bindParam(1, $uid,         PDO::PARAM_INT);
                $stmtPdf->bindParam(2, $nom_fichier);
                $stmtPdf->bindParam(3, $contenu,     PDO::PARAM_LOB);
                $stmtPdf->execute();
                logAction($pdo, $uid, 'AJOUT_PDF', $nom_fichier);
                $nb++;
            }
        }

        $pdo->commit();

        if ($nb === 0 && count($rejetes) > 0) {
            redirect("index.php?page=gerer&uid=$uid",
                'Aucun fichier ajouté. Le nom doit commencer par le matricule « ' . $matricule . ' ». '
                . 'Fichier(s) refusé(s) : ' . implode(', ', $rejetes),
                false
            );
        }

        $msg = "$nb fichier(s) ajouté(s) avec succès !";
        if (count($rejetes) > 0) {
            $msg .= ' Ignoré(s) (nom invalide) : ' . implode(', ', $rejetes);
        }
        redirect("index.php?page=gerer&uid=$uid", $msg);

    } catch (Exception $e) {
        $pdo->rollBack();
        redirect("index.php?page=gerer&uid=$uid", "Erreur : " . $e->getMessage(), false);
    }
}

// ============================================================
//  ACTION : Remplacer un PDF
// ============================================================
if ($action === 'remplacer_pdf') {

    $uid    = (int)(isset($_POST['utilisateur_id']) ? $_POST['utilisateur_id'] : 0);
    $pdf_id = (int)(isset($_POST['pdf_id'])         ? $_POST['pdf_id']         : 0);

    if ($uid <= 0 || $pdf_id <= 0) {
        redirect('index.php?page=liste', 'Paramètres invalides.', false);
    }

    if (empty($_FILES['pdf_remplace']['tmp_name'])
        || $_FILES['pdf_remplace']['error'] !== UPLOAD_ERR_OK) {
        redirect("index.php?page=gerer&uid=$uid", 'Aucun fichier valide reçu.', false);
    }

    // Récupérer le matricule de l'utilisateur
    $stmtUser = $pdo->prepare("SELECT matricule FROM utilisateurs WHERE id = ?");
    $stmtUser->execute(array($uid));
    $userRow = $stmtUser->fetch(PDO::FETCH_ASSOC);
    if (!$userRow) {
        redirect('index.php?page=liste', 'Utilisateur introuvable.', false);
    }
    $matricule = $userRow['matricule'];

    $nom_fichier = basename($_FILES['pdf_remplace']['name']);

    // ── Vérification matricule ──
    if (!nomFichierValide($nom_fichier, $matricule)) {
        redirect("index.php?page=gerer&uid=$uid",
            'Fichier refusé : le nom « ' . $nom_fichier . ' » doit commencer par le matricule « '
            . $matricule . ' » suivi d\'un underscore ou d\'un tiret.',
            false
        );
    }

    try {
        $pdo->beginTransaction();

        $old = $pdo->prepare(
            "SELECT nom_fichier FROM pdf_fichiers WHERE id = ? AND utilisateur_id = ?"
        );
        $old->execute(array($pdf_id, $uid));
        $ancien = $old->fetchColumn() ?: 'inconnu';

        $contenu = file_get_contents($_FILES['pdf_remplace']['tmp_name']);

        $stmt = $pdo->prepare(
            "UPDATE pdf_fichiers
             SET nom_fichier = ?, pdf_data = ?, date_upload = CURRENT_TIMESTAMP
             WHERE id = ? AND utilisateur_id = ?"
        );
        $stmt->bindParam(1, $nom_fichier);
        $stmt->bindParam(2, $contenu,     PDO::PARAM_LOB);
        $stmt->bindParam(3, $pdf_id,      PDO::PARAM_INT);
        $stmt->bindParam(4, $uid,         PDO::PARAM_INT);
        $stmt->execute();

        logAction($pdo, $uid, 'REMPLACEMENT_PDF', $nom_fichier,
            "Ancien: $ancien -> Nouveau: $nom_fichier");

        $pdo->commit();
        redirect("index.php?page=gerer&uid=$uid", "Fichier remplacé avec succès !");

    } catch (Exception $e) {
        $pdo->rollBack();
        redirect("index.php?page=gerer&uid=$uid", "Erreur : " . $e->getMessage(), false);
    }
}

// ============================================================
//  ACTION : Supprimer un PDF
// ============================================================
if ($action === 'supprimer_pdf') {

    $uid    = (int)(isset($_POST['utilisateur_id']) ? $_POST['utilisateur_id'] : 0);
    $pdf_id = (int)(isset($_POST['pdf_id'])         ? $_POST['pdf_id']         : 0);

    if ($uid <= 0 || $pdf_id <= 0) {
        redirect('index.php?page=liste', 'Paramètres invalides.', false);
    }

    try {
        $pdo->beginTransaction();

        $row = $pdo->prepare(
            "SELECT nom_fichier FROM pdf_fichiers WHERE id = ? AND utilisateur_id = ?"
        );
        $row->execute(array($pdf_id, $uid));
        $nom_fichier = $row->fetchColumn();

        if (!$nom_fichier) {
            $pdo->rollBack();
            redirect("index.php?page=gerer&uid=$uid", 'Fichier introuvable.', false);
        }

        $del = $pdo->prepare(
            "DELETE FROM pdf_fichiers WHERE id = ? AND utilisateur_id = ?"
        );
        $del->execute(array($pdf_id, $uid));

        logAction($pdo, $uid, 'SUPPRESSION_PDF', $nom_fichier);

        $pdo->commit();
        redirect("index.php?page=gerer&uid=$uid",
            "Fichier « $nom_fichier » supprimé.");

    } catch (Exception $e) {
        $pdo->rollBack();
        redirect("index.php?page=gerer&uid=$uid", "Erreur : " . $e->getMessage(), false);
    }
}

// ============================================================
//  ACTION : Visualiser / télécharger un PDF  (via GET ou POST)
// ============================================================
if (isset($_GET['action']) && $_GET['action'] === 'telecharger_pdf') {
    $action = 'telecharger_pdf';
}

if ($action === 'telecharger_pdf') {

    $uid    = 0;
    if (isset($_GET['uid']))               $uid = (int)$_GET['uid'];
    elseif (isset($_POST['utilisateur_id'])) $uid = (int)$_POST['utilisateur_id'];

    $pdf_id = 0;
    if (isset($_GET['pdf_id']))   $pdf_id = (int)$_GET['pdf_id'];
    elseif (isset($_POST['pdf_id'])) $pdf_id = (int)$_POST['pdf_id'];

    $stmt = $pdo->prepare(
        "SELECT nom_fichier, pdf_data
         FROM pdf_fichiers
         WHERE id = ? AND utilisateur_id = ?"
    );
    $stmt->execute(array($pdf_id, $uid));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo "Fichier introuvable.";
        exit;
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . addslashes($row['nom_fichier']) . '"');
    header('Content-Length: ' . strlen($row['pdf_data']));
    // Désactiver le buffering pour les gros fichiers
    if (ob_get_level()) ob_end_clean();
    echo $row['pdf_data'];
    exit;
}

// Action inconnue
header('Location: index.php?page=liste');
exit;