<!DOCTYPE html>
<?php
// ============================================================
//  index.php  –  Gestion PDF (PHP 5.5 compatible)
//  Modifié : matricule utilisateur + upload groupé automatique
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
    die("Connexion impossible : " . $e->getMessage());
}
 
/* ── Paramètres URL ────────────────────────────────────────── */
$page    = isset($_GET['page'])    ? $_GET['page']    : 'liste';
$uid     = isset($_GET['uid'])     ? (int)$_GET['uid'] : 0;
$success = isset($_GET['success']) ? $_GET['success']  : '';
$error   = isset($_GET['error'])   ? $_GET['error']    : '';
 
/* ── Recherche ─────────────────────────────────────────────── */
$recherche = isset($_GET['q']) ? trim($_GET['q']) : '';
 
/* ── Filtres tri (liste) ───────────────────────────────────── */
$filtre_mois  = isset($_GET['mois'])  ? (int)$_GET['mois']  : 0;
$filtre_annee = isset($_GET['annee']) ? (int)$_GET['annee'] : 0;
 
/* ── Rapport fin de mois ───────────────────────────────────── */
$rapport_mois  = isset($_GET['rapport_mois'])  ? (int)$_GET['rapport_mois']  : (int)date('n');
$rapport_annee = isset($_GET['rapport_annee']) ? (int)$_GET['rapport_annee'] : (int)date('Y');
 
/* ── Noms des mois ─────────────────────────────────────────── */
$noms_mois = array(
    1 => 'Janvier', 2 => 'Février', 3 => 'Mars',
    4 => 'Avril',   5 => 'Mai',     6 => 'Juin',
    7 => 'Juillet', 8 => 'Août',    9 => 'Septembre',
    10 => 'Octobre',11 => 'Novembre',12 => 'Décembre'
);
 
/* ── Années et mois disponibles (pour les selects) ─────────── */
$annees_dispo = array();
$mois_dispo   = array();
$stmt = $pdo->query(
    "SELECT DISTINCT YEAR(date_ajout) AS a, MONTH(date_ajout) AS m
     FROM utilisateurs
     ORDER BY a DESC, m ASC"
);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if (!in_array($r['a'], $annees_dispo)) $annees_dispo[] = (int)$r['a'];
    if (!in_array($r['m'], $mois_dispo))   $mois_dispo[]   = (int)$r['m'];
}
 
/* ── Années disponibles pour le rapport ─────────────────────── */
$annees_rapport = array();
$stmtA = $pdo->query(
    "SELECT DISTINCT YEAR(date_action) AS a
     FROM historique_actions
     ORDER BY a DESC"
);
foreach ($stmtA->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $annees_rapport[] = (int)$r['a'];
}
if (empty($annees_rapport)) {
    $annees_rapport[] = (int)date('Y');
}
 
/* ── Requête liste utilisateurs avec filtres + recherche ───── */
$utilisateurs_liste = array();
if ($page === 'liste') {
    $where  = array();
    $params = array();
 
    if ($filtre_annee > 0) {
        $where[]  = "YEAR(u.date_ajout) = ?";
        $params[] = $filtre_annee;
    }
    if ($filtre_mois > 0) {
        $where[]  = "MONTH(u.date_ajout) = ?";
        $params[] = $filtre_mois;
    }
    if ($recherche !== '') {
        $where[]  = "(u.nom LIKE ? OR u.email LIKE ? OR u.matricule LIKE ?)";
        $params[] = '%' . $recherche . '%';
        $params[] = '%' . $recherche . '%';
        $params[] = '%' . $recherche . '%';
    }
 
    $sql = "
        SELECT
            u.id,
            u.nom,
            u.matricule,
            u.email,
            u.date_ajout,
            COUNT(p.id) AS nb_pdf
        FROM utilisateurs u
        LEFT JOIN pdf_fichiers p ON p.utilisateur_id = u.id
    ";
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " GROUP BY u.id ORDER BY u.date_ajout DESC, u.nom ASC";
 
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $utilisateurs_liste = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
 
/* ── Rapport fin de mois : utilisateurs avec PDF modifiés ───── */
$rapport_utilisateurs = array();
$rapport_stats        = array('modifies' => 0, 'ajoutes' => 0, 'supprimes' => 0, 'remplaces' => 0);
 
if ($page === 'rapport') {
    $sqlRapport = "
        SELECT
            u.id,
            u.nom,
            u.matricule,
            u.email,
            u.date_ajout,
            COUNT(DISTINCT p.id) AS nb_pdf,
            SUM(CASE WHEN h.action = 'AJOUT_PDF'        THEN 1 ELSE 0 END) AS nb_ajoutes,
            SUM(CASE WHEN h.action = 'REMPLACEMENT_PDF'  THEN 1 ELSE 0 END) AS nb_remplaces,
            SUM(CASE WHEN h.action = 'SUPPRESSION_PDF'   THEN 1 ELSE 0 END) AS nb_supprimes,
            MAX(h.date_action) AS derniere_action
        FROM utilisateurs u
        INNER JOIN historique_actions h ON h.utilisateur_id = u.id
        LEFT JOIN pdf_fichiers p ON p.utilisateur_id = u.id
        WHERE h.action IN ('AJOUT_PDF','REMPLACEMENT_PDF','SUPPRESSION_PDF')
          AND MONTH(h.date_action) = ?
          AND YEAR(h.date_action)  = ?
        GROUP BY u.id
        ORDER BY derniere_action DESC
    ";
    $stmtR = $pdo->prepare($sqlRapport);
    $stmtR->execute(array($rapport_mois, $rapport_annee));
    $rapport_utilisateurs = $stmtR->fetchAll(PDO::FETCH_ASSOC);
 
    $stmtS = $pdo->prepare(
        "SELECT action, COUNT(*) AS nb
         FROM historique_actions
         WHERE MONTH(date_action) = ? AND YEAR(date_action) = ?
           AND action IN ('AJOUT_PDF','REMPLACEMENT_PDF','SUPPRESSION_PDF')
         GROUP BY action"
    );
    $stmtS->execute(array($rapport_mois, $rapport_annee));
    foreach ($stmtS->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($row['action'] === 'AJOUT_PDF')        $rapport_stats['ajoutes']   = (int)$row['nb'];
        if ($row['action'] === 'REMPLACEMENT_PDF') $rapport_stats['remplaces'] = (int)$row['nb'];
        if ($row['action'] === 'SUPPRESSION_PDF')  $rapport_stats['supprimes'] = (int)$row['nb'];
    }
    $rapport_stats['modifies'] = count($rapport_utilisateurs);
}
 
/* ── Données utilisateur (page gérer) ─────────────────────── */
$utilisateur = null;
$pdfs        = array();
$historique  = array();
 
if ($page === 'gerer' && $uid > 0) {
    $stmt = $pdo->prepare("SELECT * FROM utilisateurs WHERE id = ?");
    $stmt->execute(array($uid));
    $utilisateur = $stmt->fetch(PDO::FETCH_ASSOC);
 
    if ($utilisateur) {
        $stmt = $pdo->prepare(
            "SELECT id, nom_fichier, date_upload
             FROM pdf_fichiers
             WHERE utilisateur_id = ?
             ORDER BY date_upload DESC"
        );
        $stmt->execute(array($uid));
        $pdfs = $stmt->fetchAll(PDO::FETCH_ASSOC);
 
        $stmt = $pdo->prepare(
            "SELECT action, nom_fichier, details, date_action
             FROM historique_actions
             WHERE utilisateur_id = ?
             ORDER BY date_action DESC
             LIMIT 50"
        );
        $stmt->execute(array($uid));
        $historique = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
 
/* ── Données pour la page upload groupé ───────────────────── */
$tous_utilisateurs = array();
if ($page === 'upload_groupe') {
    $stmtU = $pdo->query(
        "SELECT id, nom, matricule, email FROM utilisateurs ORDER BY matricule ASC, nom ASC"
    );
    $tous_utilisateurs = $stmtU->fetchAll(PDO::FETCH_ASSOC);
}
 
/* ── Labels historique ─────────────────────────────────────── */
$action_labels = array(
    'CREATION_UTILISATEUR'   => array('Création utilisateur',   '#6366f1'),
    'MODIFICATION_UTILISATEUR'=> array('Modification utilisateur','#3b82f6'),
    'SUPPRESSION_UTILISATEUR' => array('Suppression utilisateur', '#ef4444'),
    'AJOUT_PDF'              => array('Ajout PDF',              '#22c55e'),
    'REMPLACEMENT_PDF'       => array('Remplacement PDF',       '#f59e0b'),
    'SUPPRESSION_PDF'        => array('Suppression PDF',        '#ef4444'),
);
 
/* ── Helper : construire querystring en gardant les filtres ─── */
function qs($extra = array())
{
    $base = array();
    if (isset($_GET['mois'])  && (int)$_GET['mois']  > 0) $base['mois']  = (int)$_GET['mois'];
    if (isset($_GET['annee']) && (int)$_GET['annee'] > 0) $base['annee'] = (int)$_GET['annee'];
    if (isset($_GET['q'])     && $_GET['q'] !== '')       $base['q']     = $_GET['q'];
    $merged = array_merge($base, $extra);
    return http_build_query($merged);
}
?>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Gestion PDF – WAMP</title>
<style>
/* ── Reset ── */
*, *:before, *:after { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Segoe UI', Arial, sans-serif;
    background: #f0f4f8;
    min-height: 100vh;
}
 
/* ── Sidebar ── */
.sidebar {
    position: fixed; top: 0; left: 0;
    width: 240px; height: 100vh;
    background: #1e293b;
    display: flex; flex-direction: column;
    padding: 0;
    z-index: 100;
    overflow-y: auto;
}
.sidebar-logo {
    padding: 22px 20px 14px;
    font-size: 15px; font-weight: 700;
    color: #fff;
    border-bottom: 1px solid #334155;
    letter-spacing: .3px;
}
.sidebar-logo span { color: #818cf8; }

/* ── Sidebar section headers — plus visibles ── */
.sidebar-section {
    padding: 14px 16px 6px;
    font-size: 11px; font-weight: 800;
    color: #e2e8f0;
    text-transform: uppercase;
    letter-spacing: 1.8px;
    background: rgba(99,102,241,0.15);
    border-left: 3px solid #6366f1;
    margin: 8px 0 2px;
    display: flex;
    align-items: center;
    gap: 7px;
}

.sidebar a {
    color: #cbd5e1; text-decoration: none;
    padding: 10px 20px;
    display: flex; align-items: center; gap: 10px;
    font-size: 14px;
    transition: background .12s;
    border-left: 3px solid transparent;
}
.sidebar a:hover  { background: #334155; color: #fff; }
.sidebar a.active { background: #334155; color: #fff; border-left-color: #818cf8; }
.sidebar .icon { font-size: 17px; width: 22px; text-align: center; }
 
/* ── Recherche sidebar ── */
.sidebar-search {
    padding: 10px 16px 4px;
    border-top: 1px solid #334155;
}
.sidebar-search-title {
    font-size: 11px; font-weight: 800;
    color: #e2e8f0;
    text-transform: uppercase;
    letter-spacing: 1.8px;
    margin-bottom: 9px;
    display: flex;
    align-items: center;
    gap: 7px;
    padding: 6px 0 4px;
    border-bottom: 1px solid rgba(99,102,241,0.4);
}
.sidebar-search-title svg {
    flex-shrink: 0;
}
.search-input-wrap {
    position: relative;
}
.search-input-wrap input {
    width: 100%; padding: 8px 32px 8px 10px;
    border-radius: 6px; border: 1px solid #334155;
    background: #0f172a; color: #e2e8f0;
    font-size: 13px;
}
.search-input-wrap input:focus {
    outline: none; border-color: #818cf8;
}
.search-input-wrap input::placeholder { color: #64748b; }
.search-input-wrap button {
    position: absolute; right: 6px; top: 50%; transform: translateY(-50%);
    background: none; border: none; color: #818cf8;
    cursor: pointer; font-size: 15px; line-height: 1;
    padding: 0;
}
.search-clear {
    display: block; margin-top: 4px;
    text-align: right; font-size: 11px;
    color: #94a3b8; text-decoration: none;
}
.search-clear:hover { color: #e2e8f0; }
 
/* ── Filtres sidebar ── */
.sidebar-filters {
    padding: 10px 16px 16px;
    border-top: 1px solid #334155;
}
.sidebar-filters-title {
    font-size: 11px; font-weight: 800;
    color: #e2e8f0;
    text-transform: uppercase;
    letter-spacing: 1.8px;
    margin-bottom: 9px;
    display: flex;
    align-items: center;
    gap: 7px;
    padding: 6px 0 4px;
    border-bottom: 1px solid rgba(99,102,241,0.4);
}
.sidebar-filters select {
    width: 100%; padding: 7px 10px;
    border-radius: 6px; border: 1px solid #334155;
    background: #0f172a; color: #e2e8f0;
    font-size: 13px; margin-bottom: 7px;
    cursor: pointer;
}
.sidebar-filters select:focus { outline: none; border-color: #818cf8; }
.sidebar-filters .btn-filtre {
    width: 100%; padding: 8px;
    background: #6366f1; color: #fff;
    border: none; border-radius: 6px;
    font-size: 13px; font-weight: 600;
    cursor: pointer; margin-bottom: 4px;
}
.sidebar-filters .btn-filtre:hover { background: #4f46e5; }
.sidebar-filters .btn-reset {
    width: 100%; padding: 6px;
    background: transparent; color: #94a3b8;
    border: 1px solid #334155; border-radius: 6px;
    font-size: 12px; cursor: pointer;
    text-align: center; text-decoration: none;
    display: block;
}
.sidebar-filters .btn-reset:hover { color: #e2e8f0; }
 
/* ── Main ── */
.main {
    margin-left: 240px;
    padding: 28px 32px;
    max-width: 1100px;
}
 
/* ── Topbar ── */
.topbar {
    display: flex; align-items: center;
    justify-content: space-between;
    margin-bottom: 24px;
    flex-wrap: wrap; gap: 12px;
}
.topbar h1 { font-size: 20px; color: #1e293b; font-weight: 700; }
 
/* ── Alerts ── */
.alert {
    padding: 11px 16px; border-radius: 8px;
    margin-bottom: 18px; font-size: 13px;
    display: flex; align-items: center; gap: 8px;
}
.alert.ok  { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
.alert.err { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
 
/* ── Card ── */
.card {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,.07);
    padding: 22px 24px;
    margin-bottom: 22px;
}
.card h3 {
    font-size: 15px; color: #1e293b; font-weight: 700;
    margin-bottom: 16px; padding-bottom: 12px;
    border-bottom: 1px solid #e2e8f0;
    display: flex; align-items: center; gap: 8px;
}
 
/* ── Formulaire ── */
.form-row { display: flex; gap: 14px; flex-wrap: wrap; }
.form-group { flex: 1; min-width: 160px; }
label {
    font-size: 12px; font-weight: 600; color: #475569;
    display: block; margin-bottom: 5px;
}
input[type=text],
input[type=email],
input[type=date],
input[type=file],
input[type=search] {
    width: 100%; padding: 8px 11px;
    border: 1px solid #cbd5e1; border-radius: 7px;
    font-size: 13px; color: #1e293b;
    background: #f8fafc;
    transition: border .15s;
}
input:focus { outline: none; border-color: #6366f1; background: #fff; }
 
/* ── Boutons ── */
.btn {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 8px 16px; border-radius: 7px;
    border: none; cursor: pointer;
    font-size: 13px; font-weight: 600;
    text-decoration: none;
    transition: filter .12s, transform .1s;
}
.btn:hover { filter: brightness(.92); transform: translateY(-1px); }
.btn-primary { background: #6366f1; color: #fff; }
.btn-success { background: #22c55e; color: #fff; }
.btn-warning { background: #f59e0b; color: #fff; }
.btn-danger  { background: #ef4444; color: #fff; }
.btn-blue    { background: #3b82f6; color: #fff; }
.btn-ghost   { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }
.btn-sm      { padding: 5px 10px; font-size: 12px; border-radius: 5px; }

/* ── Bouton Remplacer spécifique ── */
.btn-replace {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 12px; border-radius: 6px;
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: #fff; border: none; cursor: pointer;
    font-size: 12px; font-weight: 700;
    text-decoration: none;
    box-shadow: 0 2px 6px rgba(245,158,11,0.35);
    transition: filter .12s, transform .1s, box-shadow .12s;
}
.btn-replace:hover {
    filter: brightness(.93);
    transform: translateY(-1px);
    box-shadow: 0 4px 10px rgba(245,158,11,0.45);
}
.btn-replace svg {
    flex-shrink: 0;
}
 
/* ── Bandeau filtre/recherche actif ── */
.filter-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 12px; background: #ede9fe;
    color: #5b21b6; border-radius: 20px;
    font-size: 12px; font-weight: 600;
}
.search-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 12px; background: #dbeafe;
    color: #1d4ed8; border-radius: 20px;
    font-size: 12px; font-weight: 600;
}
 
/* ── Surbrillance résultat recherche ── */
mark {
    background: #fef08a;
    color: #713f12;
    border-radius: 2px;
    padding: 0 2px;
}
 
/* ── Groupes par mois/année ── */
.group-header {
    display: flex; align-items: center; gap: 10px;
    padding: 8px 0 8px;
    margin-bottom: 6px;
}
.group-header .badge-period {
    background: #6366f1; color: #fff;
    padding: 3px 14px; border-radius: 20px;
    font-size: 12px; font-weight: 700;
    letter-spacing: .3px;
}
.group-header .badge-count {
    background: #f1f5f9; color: #64748b;
    padding: 3px 10px; border-radius: 20px;
    font-size: 11px; font-weight: 600;
}
.group-divider {
    height: 1px; background: #e2e8f0;
    margin: 16px 0 12px;
}
 
/* ── Table utilisateurs ── */
.user-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.user-table th {
    text-align: left; padding: 9px 12px;
    background: #f8fafc; color: #475569;
    font-size: 11px; text-transform: uppercase; letter-spacing: .5px;
    border-bottom: 1px solid #e2e8f0;
}
.user-table td {
    padding: 10px 12px; border-bottom: 1px solid #f1f5f9;
    vertical-align: middle; color: #1e293b;
}
.user-table tr:last-child td { border-bottom: none; }
.user-table tr:hover td { background: #fafafa; }
.user-avatar-sm {
    width: 32px; height: 32px; border-radius: 50%;
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 13px; color: #fff; font-weight: 700;
    flex-shrink: 0; vertical-align: middle; margin-right: 8px;
}
.user-name { font-weight: 600; }
.user-email { font-size: 12px; color: #94a3b8; }
.pdf-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 2px 9px;
    background: #eff6ff; color: #2563eb;
    border-radius: 12px; font-size: 11px; font-weight: 700;
}
.matricule-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 2px 9px;
    background: #f0fdf4; color: #166534;
    border-radius: 12px; font-size: 11px; font-weight: 700;
    font-family: monospace;
}
.actions-cell { display: flex; gap: 5px; align-items: center; }
 
/* ── Table PDFs ── */
.pdf-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.pdf-table th {
    text-align: left; padding: 9px 12px;
    background: #f8fafc; color: #475569;
    font-size: 11px; text-transform: uppercase; letter-spacing: .5px;
}
.pdf-table td {
    padding: 10px 12px; border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
}
.pdf-table tr:last-child td { border-bottom: none; }
.pdf-name { font-weight: 600; color: #1e293b; }
.pdf-date { font-size: 12px; color: #94a3b8; }
 
/* ── Upload groupé ── */
.upload-zone {
    border: 2px dashed #cbd5e1;
    border-radius: 10px;
    padding: 28px;
    text-align: center;
    background: #f8fafc;
    cursor: pointer;
    transition: border-color .2s, background .2s;
    margin-bottom: 18px;
}
.upload-zone:hover, .upload-zone.drag-over {
    border-color: #6366f1;
    background: #ede9fe;
}
.upload-zone .uz-icon { font-size: 36px; display: block; margin-bottom: 8px; }
.upload-zone p { color: #64748b; font-size: 13px; }
.upload-zone strong { color: #6366f1; }
 
/* Table de prévisualisation des fichiers sélectionnés */
.preview-table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 10px; }
.preview-table th {
    text-align: left; padding: 8px 10px;
    background: #f8fafc; color: #475569;
    font-size: 11px; text-transform: uppercase; letter-spacing: .5px;
    border-bottom: 1px solid #e2e8f0;
}
.preview-table td {
    padding: 9px 10px; border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
}
.preview-table tr:last-child td { border-bottom: none; }
.match-ok   { color: #166534; font-weight: 700; }
.match-warn { color: #92400e; font-weight: 700; }
.match-none { color: #991b1b; font-weight: 700; }
 
/* Barre de progression upload */
.progress-wrap {
    display: none;
    background: #f1f5f9; border-radius: 8px;
    overflow: hidden; height: 22px; margin-top: 14px;
}
.progress-bar {
    height: 100%; background: linear-gradient(90deg, #6366f1, #8b5cf6);
    width: 0%; transition: width .3s;
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 11px; font-weight: 700;
}
.upload-log {
    display: none;
    margin-top: 12px;
    max-height: 200px; overflow-y: auto;
    background: #0f172a; border-radius: 8px;
    padding: 12px 14px;
    font-family: monospace; font-size: 12px;
}
.log-ok   { color: #4ade80; }
.log-warn { color: #fbbf24; }
.log-err  { color: #f87171; }
 
/* ── Rapport fin de mois ── */
.rapport-header {
    background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
    border-radius: 12px;
    padding: 22px 24px;
    margin-bottom: 22px;
    color: #fff;
}
.rapport-header h2 {
    font-size: 18px; font-weight: 700; margin-bottom: 4px;
}
.rapport-header p { font-size: 13px; color: #94a3b8; }
.rapport-stats {
    display: flex; gap: 14px; flex-wrap: wrap;
    margin-bottom: 22px;
}
.rapport-stat {
    flex: 1; min-width: 110px;
    background: #fff; border-radius: 10px;
    box-shadow: 0 1px 3px rgba(0,0,0,.07);
    padding: 14px 18px;
    border-left: 4px solid #6366f1;
}
.rapport-stat.green  { border-left-color: #22c55e; }
.rapport-stat.orange { border-left-color: #f59e0b; }
.rapport-stat.red    { border-left-color: #ef4444; }
.rapport-stat .val { font-size: 24px; font-weight: 800; color: #1e293b; }
.rapport-stat .lbl { font-size: 11px; color: #94a3b8; text-transform: uppercase; }
.rapport-form {
    display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap;
    margin-bottom: 22px;
}
.rapport-form .form-group { min-width: 130px; flex: unset; }
.rapport-form select {
    width: 100%; padding: 8px 11px;
    border: 1px solid #cbd5e1; border-radius: 7px;
    font-size: 13px; color: #1e293b; background: #f8fafc;
}
.rapport-form select:focus { outline: none; border-color: #6366f1; }
 
/* ── Badges action rapport ── */
.mini-badge {
    display: inline-flex; align-items: center; gap: 3px;
    padding: 2px 7px; border-radius: 10px;
    font-size: 10px; font-weight: 700; color: #fff;
    margin: 1px;
}
.mb-green  { background: #22c55e; }
.mb-orange { background: #f59e0b; }
.mb-red    { background: #ef4444; }
 
/* ── Fiche utilisateur (page gérer) ── */
.user-card-header {
    display: flex; align-items: center; gap: 16px;
    flex-wrap: wrap;
}
.user-avatar-lg {
    width: 54px; height: 54px; border-radius: 50%;
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; color: #fff; font-weight: 700;
    flex-shrink: 0;
}
.user-meta strong { font-size: 17px; color: #1e293b; display: block; }
.user-meta span   { font-size: 12px; color: #94a3b8; }
.user-meta .date-badge {
    display: inline-flex; align-items: center; gap: 4px;
    margin-top: 4px;
    background: #f1f5f9; color: #475569;
    padding: 2px 10px; border-radius: 12px; font-size: 12px;
}
.user-meta .mat-badge {
    display: inline-flex; align-items: center; gap: 4px;
    margin-top: 4px; margin-left: 6px;
    background: #f0fdf4; color: #166534;
    padding: 2px 10px; border-radius: 12px; font-size: 12px;
    font-family: monospace; font-weight: 700;
}
 
/* ── Formulaire édition inline ── */
.edit-form { display: none; }
.edit-form.open { display: block; }
 
/* ── Historique ── */
.hist-list { max-height: 320px; overflow-y: auto; }
.hist-item {
    display: flex; align-items: flex-start; gap: 10px;
    padding: 9px 0; border-bottom: 1px solid #f1f5f9;
    font-size: 13px;
}
.hist-item:last-child { border-bottom: none; }
.hist-badge {
    padding: 2px 9px; border-radius: 20px;
    font-size: 10px; font-weight: 700;
    color: #fff; white-space: nowrap; flex-shrink: 0;
}
.hist-detail { flex: 1; }
.hist-detail .fichier { font-weight: 600; color: #1e293b; }
.hist-detail .extra   { font-size: 11px; color: #94a3b8; }
.hist-date { font-size: 11px; color: #cbd5e1; white-space: nowrap; }
 
/* ── Modale ── */
.modal-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.45); z-index: 200;
    align-items: center; justify-content: center;
}
.modal-overlay.open { display: flex; }
.modal {
    background: #fff; border-radius: 14px;
    padding: 26px; width: 460px; max-width: 94vw;
    box-shadow: 0 8px 32px rgba(0,0,0,.18);
}
.modal h3 { margin-bottom: 6px; color: #1e293b; font-size: 16px; display:flex; align-items:center; gap:8px; }
.modal .modal-subtitle { font-size:12px; color:#94a3b8; margin-bottom:14px; }
.modal .btn-row {
    display: flex; gap: 10px; justify-content: flex-end; margin-top: 16px;
}

/* ── Info matricule dans modale remplacement ── */
.modal-matricule-info {
    background: #fef9ec;
    border: 1px solid #fcd34d;
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 12px;
    color: #92400e;
    margin-bottom: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
}
 
/* ── Empty state ── */
.empty {
    text-align: center; padding: 36px;
    color: #94a3b8; font-size: 14px;
}
.empty .eicon { font-size: 40px; display: block; margin-bottom: 10px; }
 
/* ── Stats mini ── */
.stats-row {
    display: flex; gap: 14px; margin-bottom: 22px; flex-wrap: wrap;
}
.stat-card {
    flex: 1; min-width: 120px;
    background: #fff; border-radius: 10px;
    box-shadow: 0 1px 3px rgba(0,0,0,.07);
    padding: 16px 20px;
    display: flex; flex-direction: column; gap: 2px;
}
.stat-card .val { font-size: 26px; font-weight: 800; color: #1e293b; }
.stat-card .lbl { font-size: 11px; color: #94a3b8; text-transform: uppercase; letter-spacing: .5px; }
.stat-card .accent-v { color: #6366f1; }
.stat-card .accent-g { color: #22c55e; }
.stat-card .accent-b { color: #3b82f6; }
 
/* ── Info box ── */
.info-box {
    background: #eff6ff; border: 1px solid #bfdbfe;
    border-radius: 8px; padding: 12px 16px;
    font-size: 13px; color: #1e40af;
    margin-bottom: 16px;
    display: flex; gap: 8px; align-items: flex-start;
}
.info-box .info-icon { font-size: 16px; flex-shrink: 0; }

/* ── Alerte matricule obligatoire ── */
.matricule-rule-box {
    background: #fff7ed; border: 1px solid #fed7aa;
    border-radius: 8px; padding: 11px 16px;
    font-size: 13px; color: #9a3412;
    margin-bottom: 16px;
    display: flex; gap: 8px; align-items: flex-start;
}
 
/* ── Impression rapport ── */
@media print {
    .sidebar, .rapport-form, .btn, .topbar .btn { display: none !important; }
    .main { margin-left: 0; padding: 10px; }
    .rapport-header { background: #1e293b !important; -webkit-print-color-adjust: exact; }
}
</style>
</head>
<body>
 
<!-- ══════════════════════  SIDEBAR  ═════════════════════════ -->
<nav class="sidebar">
    <div class="sidebar-logo" style="text-align:center; padding:18px 16px 14px;">
        <img src="logo.png" alt="Appariteur"
             style="width:130px; filter:invert(1) brightness(2); display:block; margin:0 auto 8px;">
        <span style="font-size:10px; color:#94a3b8; letter-spacing:1.5px; text-transform:uppercase; font-weight:600;">
            Gestion PDF
        </span>
    </div>
 
    <div class="sidebar-section">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#818cf8" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M3 12h3m12 0h3M12 3v3m0 12v3"/></svg>
        Navigation
    </div>
    <a href="index.php?page=liste" class="<?php echo ($page === 'liste') ? 'active' : ''; ?>">
        <span class="icon">👥</span> Liste des utilisateurs
    </a>
    <a href="index.php?page=creer" class="<?php echo ($page === 'creer') ? 'active' : ''; ?>">
        <span class="icon">➕</span> Nouvel utilisateur
    </a>
    <a href="index.php?page=upload_groupe" class="<?php echo ($page === 'upload_groupe') ? 'active' : ''; ?>">
        <span class="icon">📤</span> Upload groupé
    </a>
    <a href="index.php?page=rapport" class="<?php echo ($page === 'rapport') ? 'active' : ''; ?>">
        <span class="icon">📊</span> Rapport fin de mois
    </a>
 
    <!-- Recherche rapide -->
    <div class="sidebar-search">
        <div class="sidebar-search-title">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#818cf8" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
            Rechercher un utilisateur
        </div>
        <form method="GET" action="index.php">
            <input type="hidden" name="page" value="liste">
            <div class="search-input-wrap">
                <input type="text" name="q"
                       placeholder="Nom, email ou matricule…"
                       value="<?php echo htmlspecialchars($recherche); ?>">
                <button type="submit" title="Rechercher">🔍</button>
            </div>
            <?php if ($recherche !== ''): ?>
            <a href="index.php?page=liste" class="search-clear">✕ Effacer</a>
            <?php endif; ?>
        </form>
    </div>
 
    <!-- Filtres par mois / année -->
    <div class="sidebar-filters">
        <div class="sidebar-filters-title">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#818cf8" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
            Filtrer par période
        </div>
        <form method="GET" action="index.php">
            <input type="hidden" name="page" value="liste">
            <?php if ($recherche !== ''): ?>
            <input type="hidden" name="q" value="<?php echo htmlspecialchars($recherche); ?>">
            <?php endif; ?>
            <select name="annee">
                <option value="0">— Toutes les années</option>
                <?php foreach ($annees_dispo as $a): ?>
                <option value="<?php echo $a; ?>" <?php echo ($filtre_annee == $a) ? 'selected' : ''; ?>>
                    <?php echo $a; ?>
                </option>
                <?php endforeach; ?>
            </select>
            <select name="mois">
                <option value="0">— Tous les mois</option>
                <?php foreach ($noms_mois as $num => $nom): ?>
                <option value="<?php echo $num; ?>" <?php echo ($filtre_mois == $num) ? 'selected' : ''; ?>>
                    <?php echo $nom; ?>
                </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn-filtre">Appliquer</button>
            <a href="index.php?page=liste" class="btn-reset">Réinitialiser</a>
        </form>
    </div>
</nav>
 
<!-- ══════════════════════  MAIN  ════════════════════════════ -->
<main class="main">
 
<?php if ($success): ?>
    <div class="alert ok">✅ <?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert err">❌ <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
 
 
<!-- ═══════════════════  PAGE : LISTE  ══════════════════════ -->
<?php if ($page === 'liste'): ?>
 
<?php
// ── Statistiques rapides ──
$total_u = $pdo->query("SELECT COUNT(*) FROM utilisateurs")->fetchColumn();
$total_p = $pdo->query("SELECT COUNT(*) FROM pdf_fichiers")->fetchColumn();
$mois_actuel = (int)date('n');
$annee_actuelle = (int)date('Y');
$stmtThisMois = $pdo->prepare(
    "SELECT COUNT(*) FROM utilisateurs
     WHERE MONTH(date_ajout) = ? AND YEAR(date_ajout) = ?"
);
$stmtThisMois->execute(array($mois_actuel, $annee_actuelle));
$total_ce_mois = $stmtThisMois->fetchColumn();
?>
 
<div class="topbar">
    <h1>👥 Utilisateurs &amp; PDF</h1>
    <div style="display:flex;gap:8px;">
        <a href="index.php?page=upload_groupe" class="btn btn-primary">📤 Upload groupé</a>
        <a href="index.php?page=creer" class="btn btn-success">➕ Nouvel utilisateur</a>
    </div>
</div>
 
<!-- Stats -->
<div class="stats-row">
    <div class="stat-card">
        <span class="val accent-v"><?php echo $total_u; ?></span>
        <span class="lbl">Utilisateurs</span>
    </div>
    <div class="stat-card">
        <span class="val accent-b"><?php echo $total_p; ?></span>
        <span class="lbl">Fichiers PDF</span>
    </div>
    <div class="stat-card">
        <span class="val accent-g"><?php echo $total_ce_mois; ?></span>
        <span class="lbl">Ajouts ce mois</span>
    </div>
</div>
 
<!-- Recherche active -->
<?php if ($recherche !== ''): ?>
<div style="margin-bottom:14px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
    <span class="search-badge">
        🔍 Résultats pour &laquo; <?php echo htmlspecialchars($recherche); ?> &raquo;
        &nbsp;—&nbsp; <?php echo count($utilisateurs_liste); ?> trouvé(s)
        <a href="index.php?page=liste" style="color:#1d4ed8;margin-left:6px;font-weight:900;">✕</a>
    </span>
</div>
<?php endif; ?>
 
<!-- Filtre actif -->
<?php if ($filtre_annee > 0 || $filtre_mois > 0): ?>
<div style="margin-bottom:14px;">
    <span class="filter-badge">
        🗓
        <?php
        $parts = array();
        if ($filtre_mois  > 0) $parts[] = $noms_mois[$filtre_mois];
        if ($filtre_annee > 0) $parts[] = $filtre_annee;
        echo implode(' ', $parts);
        ?>
        &nbsp;—&nbsp; <?php echo count($utilisateurs_liste); ?> utilisateur(s)
        <a href="index.php?page=liste<?php echo ($recherche !== '') ? '&q='.urlencode($recherche) : ''; ?>"
           style="color:#7c3aed;margin-left:6px;font-weight:900;">✕</a>
    </span>
</div>
<?php endif; ?>
 
<?php if (count($utilisateurs_liste) === 0): ?>
    <div class="card">
        <div class="empty">
            <span class="eicon"><?php echo ($recherche !== '') ? '🔍' : '😶'; ?></span>
            <?php if ($recherche !== ''): ?>
                Aucun utilisateur trouvé pour &laquo; <?php echo htmlspecialchars($recherche); ?> &raquo;.
                <br><br>
                <a href="index.php?page=liste" class="btn btn-ghost btn-sm">Voir tous les utilisateurs</a>
            <?php else: ?>
                Aucun utilisateur pour cette période.
            <?php endif; ?>
        </div>
    </div>
<?php else:
    if ($recherche !== ''):
?>
    <div class="card" style="padding:0;overflow:hidden;">
        <table class="user-table">
            <thead>
                <tr>
                    <th>Utilisateur</th>
                    <th>Matricule</th>
                    <th>Email</th>
                    <th>Date d'ajout</th>
                    <th>Fichiers PDF</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($utilisateurs_liste as $u):
                $nomHL   = str_ireplace(
                    htmlspecialchars($recherche),
                    '<mark>' . htmlspecialchars($recherche) . '</mark>',
                    htmlspecialchars($u['nom'])
                );
                $emailHL = str_ireplace(
                    htmlspecialchars($recherche),
                    '<mark>' . htmlspecialchars($recherche) . '</mark>',
                    htmlspecialchars($u['email'] ?: '—')
                );
                $matHL = str_ireplace(
                    htmlspecialchars($recherche),
                    '<mark>' . htmlspecialchars($recherche) . '</mark>',
                    htmlspecialchars($u['matricule'] ?: '—')
                );
            ?>
            <tr>
                <td>
                    <span class="user-avatar-sm">
                        <?php echo mb_strtoupper(mb_substr($u['nom'], 0, 1, 'UTF-8')); ?>
                    </span>
                    <span class="user-name"><?php echo $nomHL; ?></span>
                </td>
                <td><span class="matricule-badge"><?php echo $matHL; ?></span></td>
                <td class="user-email"><?php echo $emailHL; ?></td>
                <td><?php echo date('d/m/Y', strtotime($u['date_ajout'])); ?></td>
                <td>
                    <span class="pdf-badge">📄 <?php echo $u['nb_pdf']; ?></span>
                </td>
                <td>
                    <div class="actions-cell">
                        <a href="index.php?page=gerer&uid=<?php echo $u['id']; ?>"
                           class="btn btn-primary btn-sm">Gérer</a>
                        <form action="ajout_complet.php" method="POST"
                              onsubmit="return confirm('Supprimer cet utilisateur et tous ses PDF ?');"
                              style="display:inline;">
                            <input type="hidden" name="action" value="supprimer_utilisateur">
                            <input type="hidden" name="utilisateur_id" value="<?php echo $u['id']; ?>">
                            <button type="submit" class="btn btn-danger btn-sm">🗑️</button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php else:
    $groupes = array();
    foreach ($utilisateurs_liste as $u) {
        $ts  = strtotime($u['date_ajout']);
        $key = date('Y-m', $ts);
        $groupes[$key][] = $u;
    }
    krsort($groupes);
 
    $premier = true;
    foreach ($groupes as $key => $membres):
        $ts    = strtotime($key . '-01');
        $label = $noms_mois[(int)date('n', $ts)] . ' ' . date('Y', $ts);
        if (!$premier): ?>
        <div class="group-divider"></div>
        <?php endif; $premier = false; ?>
 
    <div class="group-header">
        <span class="badge-period">📅 <?php echo $label; ?></span>
        <span class="badge-count"><?php echo count($membres); ?> utilisateur(s)</span>
    </div>
 
    <div class="card" style="padding:0;overflow:hidden;">
        <table class="user-table">
            <thead>
                <tr>
                    <th>Utilisateur</th>
                    <th>Matricule</th>
                    <th>Email</th>
                    <th>Date d'ajout</th>
                    <th>Fichiers PDF</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($membres as $u): ?>
            <tr>
                <td>
                    <span class="user-avatar-sm">
                        <?php echo mb_strtoupper(mb_substr($u['nom'], 0, 1, 'UTF-8')); ?>
                    </span>
                    <span class="user-name"><?php echo htmlspecialchars($u['nom']); ?></span>
                </td>
                <td><span class="matricule-badge"><?php echo htmlspecialchars($u['matricule'] ?: '—'); ?></span></td>
                <td class="user-email"><?php echo htmlspecialchars($u['email'] ?: '—'); ?></td>
                <td><?php echo date('d/m/Y', strtotime($u['date_ajout'])); ?></td>
                <td>
                    <span class="pdf-badge">📄 <?php echo $u['nb_pdf']; ?></span>
                </td>
                <td>
                    <div class="actions-cell">
                        <a href="index.php?page=gerer&uid=<?php echo $u['id']; ?>"
                           class="btn btn-primary btn-sm">Gérer</a>
                        <form action="ajout_complet.php" method="POST"
                              onsubmit="return confirm('Supprimer cet utilisateur et tous ses PDF ?');"
                              style="display:inline;">
                            <input type="hidden" name="action" value="supprimer_utilisateur">
                            <input type="hidden" name="utilisateur_id" value="<?php echo $u['id']; ?>">
                            <button type="submit" class="btn btn-danger btn-sm">🗑️</button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endforeach;
endif;
endif;
?>
 
 
<!-- ═══════════════════  PAGE : CRÉER  ══════════════════════ -->
<?php elseif ($page === 'creer'): ?>
 
<div class="topbar">
    <h1>➕ Nouvel utilisateur</h1>
    <a href="index.php?page=liste" class="btn btn-ghost">← Retour</a>
</div>
 
<div class="card">
    <h3>📋 Informations de l'utilisateur</h3>

    <div class="matricule-rule-box">
        <span>⚠️</span>
        <div>
            <strong>Règle de nommage des fichiers PDF :</strong>
            Le nom de chaque fichier PDF doit commencer par le matricule de l'utilisateur,
            suivi d'un underscore <code>_</code> ou d'un tiret <code>-</code>.
            Exemple : <code>EMP-001_bulletin.pdf</code> ou <code>EMP001-contrat.pdf</code>.
        </div>
    </div>

    <form action="ajout_complet.php" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="creer_utilisateur">
 
        <div class="form-row">
            <div class="form-group">
                <label>Nom *</label>
                <input type="text" name="nom" required placeholder="Jean Dupont">
            </div>
            <div class="form-group" style="max-width:180px;">
                <label>Matricule *</label>
                <input type="text" name="matricule" required placeholder="EMP-001"
                       style="font-family:monospace;">
            </div>
        </div>
 
        <div class="form-row" style="margin-top:10px;">
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" placeholder="jean@exemple.fr">
            </div>
            <div class="form-group" style="max-width:220px;">
                <label>Date d'ajout *</label>
                <input type="date" name="date_ajout" required value="<?php echo date('Y-m-d'); ?>">
            </div>
        </div>
 
        <div class="form-group" style="margin-top:10px;">
            <label>Fichiers PDF (optionnel — le nom doit commencer par le matricule)</label>
            <input type="file" name="pdf[]" accept="application/pdf" multiple>
        </div>
 
        <div style="margin-top:16px;">
            <button type="submit" class="btn btn-success">✅ Créer l'utilisateur</button>
        </div>
    </form>
</div>
 
 
<!-- ═══════════════  PAGE : UPLOAD GROUPÉ  ══════════════════ -->
<?php elseif ($page === 'upload_groupe'): ?>
 
<div class="topbar">
    <h1>📤 Upload groupé par matricule</h1>
    <a href="index.php?page=liste" class="btn btn-ghost">← Liste</a>
</div>
 
<div class="info-box">
    <span class="info-icon">ℹ️</span>
    <div>
        <strong>Comment ça fonctionne :</strong> nommez vos fichiers PDF avec le matricule de l'utilisateur
        au début du nom, séparé par un underscore ou un tiret.
        Exemple : <code style="background:#dbeafe;padding:1px 5px;border-radius:3px;">EMP-001_fiche_paie.pdf</code>
        ou <code style="background:#dbeafe;padding:1px 5px;border-radius:3px;">EMP001_document.pdf</code>.
        L'application détecte automatiquement le destinataire et envoie chaque fichier au bon utilisateur.
    </div>
</div>
 
<!-- Liste des matricules disponibles -->
<div class="card">
    <h3>📋 Matricules enregistrés</h3>
    <?php if (count($tous_utilisateurs) === 0): ?>
        <div class="empty">
            <span class="eicon">😶</span>
            Aucun utilisateur enregistré.
        </div>
    <?php else: ?>
    <div style="display:flex;flex-wrap:wrap;gap:8px;">
        <?php foreach ($tous_utilisateurs as $u): ?>
        <span style="background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;
                     padding:4px 12px;border-radius:20px;font-size:12px;font-family:monospace;font-weight:700;">
            <?php echo htmlspecialchars($u['matricule']); ?>
            <span style="font-family:sans-serif;font-weight:400;color:#6b7280;">
                – <?php echo htmlspecialchars($u['nom']); ?>
            </span>
        </span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
 
<!-- Zone d'upload -->
<div class="card">
    <h3>📂 Sélectionner les fichiers PDF</h3>
 
    <div class="upload-zone" id="uploadZone" onclick="document.getElementById('fileInput').click()">
        <span class="uz-icon">📁</span>
        <p><strong>Cliquez ici</strong> ou glissez-déposez vos fichiers PDF</p>
        <p style="margin-top:4px;font-size:12px;color:#94a3b8;">
            Plusieurs fichiers acceptés — le matricule doit apparaître en début de nom de fichier
        </p>
    </div>
 
    <input type="file" id="fileInput" accept="application/pdf" multiple
           style="display:none;">
 
    <!-- Tableau de prévisualisation -->
    <div id="previewSection" style="display:none;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
            <strong style="font-size:14px;color:#1e293b;">
                Fichiers sélectionnés (<span id="fileCount">0</span>)
            </strong>
            <button type="button" class="btn btn-ghost btn-sm" onclick="clearFiles()">✕ Tout effacer</button>
        </div>
        <table class="preview-table">
            <thead>
                <tr>
                    <th>Fichier</th>
                    <th>Matricule détecté</th>
                    <th>Destinataire</th>
                    <th>Statut</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="previewBody"></tbody>
        </table>
 
        <div style="margin-top:16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <button type="button" class="btn btn-success" id="btnUpload" onclick="lancerUpload()">
                📤 Uploader tous les fichiers correspondants
            </button>
            <span id="uploadSummary" style="font-size:12px;color:#64748b;"></span>
        </div>
 
        <div class="progress-wrap" id="progressWrap">
            <div class="progress-bar" id="progressBar">0%</div>
        </div>
 
        <div class="upload-log" id="uploadLog"></div>
    </div>
</div>
 
<!-- Données utilisateurs en JSON pour JS -->
<script>
var utilisateurs = <?php
    $out = array();
    foreach ($tous_utilisateurs as $u) {
        $out[] = array(
            'id'        => (int)$u['id'],
            'nom'       => $u['nom'],
            'matricule' => $u['matricule'],
            'email'     => $u['email']
        );
    }
    echo json_encode($out);
?>;
 
var selectedFiles = [];
 
/* ─── Détection du matricule depuis le nom de fichier ─────── */
function detecterMatricule(nomFichier) {
    var base = nomFichier.replace(/\.pdf$/i, '');
    var match = base.match(/^([A-Za-z0-9\-]+)[_\-]/);
    if (match) return match[1].toUpperCase();
    return base.toUpperCase();
}
 
/* ─── Trouver l'utilisateur par matricule ─────────────────── */
function trouverUtilisateur(matricule) {
    for (var i = 0; i < utilisateurs.length; i++) {
        if (utilisateurs[i].matricule.toUpperCase() === matricule) {
            return utilisateurs[i];
        }
    }
    return null;
}
 
/* ─── Mise à jour de la prévisualisation ──────────────────── */
function mettreAJourPreview() {
    var tbody = document.getElementById('previewBody');
    tbody.innerHTML = '';
    document.getElementById('fileCount').textContent = selectedFiles.length;
 
    var nbOk = 0, nbWarn = 0;
 
    for (var i = 0; i < selectedFiles.length; i++) {
        var f   = selectedFiles[i];
        var mat = detecterMatricule(f.name);
        var u   = trouverUtilisateur(mat);
 
        var tr = document.createElement('tr');
 
        var tdF = document.createElement('td');
        tdF.innerHTML = '<span class="pdf-name">📄 ' + escHtml(f.name) + '</span>'
            + '<br><span style="font-size:11px;color:#94a3b8;">'
            + (f.size / 1024).toFixed(1) + ' Ko</span>';
        tr.appendChild(tdF);
 
        var tdM = document.createElement('td');
        tdM.innerHTML = '<code style="background:#f1f5f9;padding:2px 6px;border-radius:4px;">'
            + escHtml(mat) + '</code>';
        tr.appendChild(tdM);
 
        var tdU = document.createElement('td');
        if (u) {
            tdU.innerHTML = '<strong>' + escHtml(u.nom) + '</strong>'
                + '<br><span style="font-size:11px;color:#94a3b8;">'
                + escHtml(u.email || '') + '</span>';
        } else {
            tdU.innerHTML = '<span style="color:#94a3b8;font-style:italic;">Aucun utilisateur</span>';
        }
        tr.appendChild(tdU);
 
        var tdS = document.createElement('td');
        if (u) {
            tdS.innerHTML = '<span class="match-ok">✅ Correspondance trouvée</span>';
            nbOk++;
            tr.setAttribute('data-uid', u.id);
            tr.setAttribute('data-mat', mat);
        } else {
            tdS.innerHTML = '<span class="match-none">❌ Matricule introuvable</span>';
            nbWarn++;
        }
        tr.setAttribute('data-idx', i);
        tr.setAttribute('id', 'row-' + i);
        tr.appendChild(tdS);
 
        var tdX = document.createElement('td');
        tdX.innerHTML = '<button type="button" class="btn btn-ghost btn-sm" '
            + 'onclick="retirerFichier(' + i + ')" title="Retirer">✕</button>';
        tr.appendChild(tdX);
 
        tbody.appendChild(tr);
    }
 
    document.getElementById('uploadSummary').textContent =
        nbOk + ' fichier(s) prêt(s) à envoyer' +
        (nbWarn > 0 ? ', ' + nbWarn + ' sans correspondance (ignoré(s))' : '');
 
    document.getElementById('previewSection').style.display =
        selectedFiles.length > 0 ? 'block' : 'none';
}
 
function retirerFichier(idx) {
    selectedFiles.splice(idx, 1);
    mettreAJourPreview();
}
 
function clearFiles() {
    selectedFiles = [];
    document.getElementById('fileInput').value = '';
    document.getElementById('previewSection').style.display = 'none';
    document.getElementById('uploadLog').style.display = 'none';
    document.getElementById('uploadLog').innerHTML = '';
    document.getElementById('progressWrap').style.display = 'none';
}
 
document.getElementById('fileInput').addEventListener('change', function() {
    for (var i = 0; i < this.files.length; i++) {
        selectedFiles.push(this.files[i]);
    }
    mettreAJourPreview();
});
 
var zone = document.getElementById('uploadZone');
zone.addEventListener('dragover', function(e) {
    e.preventDefault();
    zone.className = 'upload-zone drag-over';
});
zone.addEventListener('dragleave', function() {
    zone.className = 'upload-zone';
});
zone.addEventListener('drop', function(e) {
    e.preventDefault();
    zone.className = 'upload-zone';
    var files = e.dataTransfer.files;
    for (var i = 0; i < files.length; i++) {
        if (files[i].type === 'application/pdf' || files[i].name.match(/\.pdf$/i)) {
            selectedFiles.push(files[i]);
        }
    }
    mettreAJourPreview();
});
 
function lancerUpload() {
    var aEnvoyer = [];
    for (var i = 0; i < selectedFiles.length; i++) {
        var mat = detecterMatricule(selectedFiles[i].name);
        var u   = trouverUtilisateur(mat);
        if (u) {
            aEnvoyer.push({ file: selectedFiles[i], uid: u.id, nom: u.nom });
        }
    }
 
    if (aEnvoyer.length === 0) {
        alert('Aucun fichier avec un matricule correspondant à envoyer.');
        return;
    }
 
    document.getElementById('btnUpload').disabled = true;
    document.getElementById('progressWrap').style.display = 'block';
    document.getElementById('uploadLog').style.display    = 'block';
    document.getElementById('uploadLog').innerHTML = '';
 
    var total    = aEnvoyer.length;
    var courant  = 0;
    var succes   = 0;
    var echecs   = 0;
 
    function envoyerSuivant() {
        if (courant >= total) {
            var pct = 100;
            document.getElementById('progressBar').style.width = pct + '%';
            document.getElementById('progressBar').textContent = '100%';
            ajouterLog('✅ Upload terminé : ' + succes + ' envoyé(s), ' + echecs + ' échec(s).', 'ok');
            document.getElementById('btnUpload').disabled = false;
            setTimeout(function() {
                window.location.href = 'index.php?page=upload_groupe&success='
                    + encodeURIComponent(succes + ' fichier(s) envoyé(s) avec succès.');
            }, 2000);
            return;
        }
 
        var item = aEnvoyer[courant];
        ajouterLog('⏳ Envoi de « ' + item.file.name + ' » → ' + item.nom + '…', 'warn');
 
        var formData = new FormData();
        formData.append('action',         'ajouter_pdf_ajax');
        formData.append('utilisateur_id', item.uid);
        formData.append('pdf[]',          item.file, item.file.name);
 
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'ajout_complet.php', true);
        xhr.onload = function() {
            courant++;
            var pct = Math.round((courant / total) * 100);
            document.getElementById('progressBar').style.width = pct + '%';
            document.getElementById('progressBar').textContent = pct + '%';
 
            try {
                var res = JSON.parse(xhr.responseText);
                if (res.ok) {
                    succes++;
                    ajouterLog('✅ ' + item.file.name + ' → ' + item.nom, 'ok');
                } else {
                    echecs++;
                    ajouterLog('❌ ' + item.file.name + ' : ' + res.msg, 'err');
                }
            } catch(e) {
                echecs++;
                ajouterLog('❌ ' + item.file.name + ' : réponse invalide', 'err');
            }
            envoyerSuivant();
        };
        xhr.onerror = function() {
            courant++;
            echecs++;
            ajouterLog('❌ ' + item.file.name + ' : erreur réseau', 'err');
            envoyerSuivant();
        };
        xhr.send(formData);
    }
 
    envoyerSuivant();
}
 
function ajouterLog(msg, type) {
    var log  = document.getElementById('uploadLog');
    var line = document.createElement('div');
    line.className = 'log-' + type;
    line.textContent = msg;
    log.appendChild(line);
    log.scrollTop = log.scrollHeight;
}
 
function escHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}
</script>
 
 
<!-- ═══════════════════  PAGE : RAPPORT  ════════════════════ -->
<?php elseif ($page === 'rapport'): ?>
 
<div class="topbar">
    <h1>📊 Rapport fin de mois</h1>
    <button onclick="window.print()" class="btn btn-ghost">🖨️ Imprimer</button>
</div>
 
<div class="card">
    <h3>🗓 Choisir la période</h3>
    <form method="GET" action="index.php" class="rapport-form">
        <input type="hidden" name="page" value="rapport">
        <div class="form-group">
            <label>Mois</label>
            <select name="rapport_mois">
                <?php foreach ($noms_mois as $num => $nom): ?>
                <option value="<?php echo $num; ?>"
                    <?php echo ($rapport_mois == $num) ? 'selected' : ''; ?>>
                    <?php echo $nom; ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Année</label>
            <select name="rapport_annee">
                <?php
                $annees_r = $annees_rapport;
                if (!in_array((int)date('Y'), $annees_r)) {
                    array_unshift($annees_r, (int)date('Y'));
                }
                foreach ($annees_r as $a): ?>
                <option value="<?php echo $a; ?>"
                    <?php echo ($rapport_annee == $a) ? 'selected' : ''; ?>>
                    <?php echo $a; ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="align-self:flex-end;">
            <button type="submit" class="btn btn-primary">Afficher le rapport</button>
        </div>
    </form>
</div>
 
<div class="rapport-header">
    <h2>📋 Rapport — <?php echo $noms_mois[$rapport_mois] . ' ' . $rapport_annee; ?></h2>
    <p>Utilisateurs dont les fichiers PDF ont été modifiés (ajout, remplacement ou suppression)</p>
</div>
 
<div class="rapport-stats">
    <div class="rapport-stat">
        <div class="val"><?php echo $rapport_stats['modifies']; ?></div>
        <div class="lbl">Utilisateurs concernés</div>
    </div>
    <div class="rapport-stat green">
        <div class="val"><?php echo $rapport_stats['ajoutes']; ?></div>
        <div class="lbl">PDF ajoutés</div>
    </div>
    <div class="rapport-stat orange">
        <div class="val"><?php echo $rapport_stats['remplaces']; ?></div>
        <div class="lbl">PDF remplacés</div>
    </div>
    <div class="rapport-stat red">
        <div class="val"><?php echo $rapport_stats['supprimes']; ?></div>
        <div class="lbl">PDF supprimés</div>
    </div>
</div>
 
<?php if (count($rapport_utilisateurs) === 0): ?>
<div class="card">
    <div class="empty">
        <span class="eicon">📭</span>
        Aucune modification de fichier PDF en
        <?php echo $noms_mois[$rapport_mois] . ' ' . $rapport_annee; ?>.
    </div>
</div>
<?php else: ?>
<div class="card" style="padding:0;overflow:hidden;">
    <table class="user-table">
        <thead>
            <tr>
                <th>Utilisateur</th>
                <th>Matricule</th>
                <th>Email</th>
                <th>PDF actuels</th>
                <th>Actions du mois</th>
                <th>Dernière action</th>
                <th>Accès</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rapport_utilisateurs as $u): ?>
        <tr>
            <td>
                <span class="user-avatar-sm">
                    <?php echo mb_strtoupper(mb_substr($u['nom'], 0, 1, 'UTF-8')); ?>
                </span>
                <span class="user-name"><?php echo htmlspecialchars($u['nom']); ?></span>
            </td>
            <td><span class="matricule-badge"><?php echo htmlspecialchars($u['matricule'] ?: '—'); ?></span></td>
            <td class="user-email"><?php echo htmlspecialchars($u['email'] ?: '—'); ?></td>
            <td>
                <span class="pdf-badge">📄 <?php echo $u['nb_pdf']; ?></span>
            </td>
            <td>
                <?php if ($u['nb_ajoutes'] > 0): ?>
                    <span class="mini-badge mb-green">+<?php echo $u['nb_ajoutes']; ?> ajouté(s)</span>
                <?php endif; ?>
                <?php if ($u['nb_remplaces'] > 0): ?>
                    <span class="mini-badge mb-orange">🔄 <?php echo $u['nb_remplaces']; ?> remplacé(s)</span>
                <?php endif; ?>
                <?php if ($u['nb_supprimes'] > 0): ?>
                    <span class="mini-badge mb-red">✕ <?php echo $u['nb_supprimes']; ?> supprimé(s)</span>
                <?php endif; ?>
            </td>
            <td class="pdf-date">
                <?php echo date('d/m/Y H:i', strtotime($u['derniere_action'])); ?>
            </td>
            <td>
                <a href="index.php?page=gerer&uid=<?php echo $u['id']; ?>"
                   class="btn btn-primary btn-sm">Gérer</a>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
 
 
<!-- ═══════════════════  PAGE : GÉRER  ══════════════════════ -->
<?php elseif ($page === 'gerer' && $utilisateur): ?>
 
<div class="topbar">
    <h1>⚙️ Gestion des fichiers</h1>
    <a href="index.php?page=liste" class="btn btn-ghost">← Liste</a>
</div>
 
<!-- ── Fiche utilisateur + formulaire de modification ── -->
<div class="card">
    <h3>🧑 Informations de l'utilisateur</h3>
 
    <div class="user-card-header" id="user-display">
        <div class="user-avatar-lg">
            <?php echo mb_strtoupper(mb_substr($utilisateur['nom'], 0, 1, 'UTF-8')); ?>
        </div>
        <div class="user-meta">
            <strong><?php echo htmlspecialchars($utilisateur['nom']); ?></strong>
            <span><?php echo htmlspecialchars($utilisateur['email'] ?: 'Pas d\'email'); ?></span>
            <span class="date-badge">
                📅 <?php
                    $ts = strtotime($utilisateur['date_ajout']);
                    echo $noms_mois[(int)date('n', $ts)] . ' ' . date('Y', $ts)
                       . ' (' . date('d/m/Y', $ts) . ')';
                ?>
            </span>
            <span class="mat-badge">
                🪪 <?php echo htmlspecialchars($utilisateur['matricule'] ?: 'N/A'); ?>
            </span>
        </div>
        <div style="margin-left:auto;display:flex;gap:8px;">
            <button class="btn btn-blue btn-sm" onclick="toggleEdit()">✏️ Modifier</button>
            <form action="ajout_complet.php" method="POST"
                  onsubmit="return confirm('Supprimer cet utilisateur ET tous ses PDF ?');">
                <input type="hidden" name="action" value="supprimer_utilisateur">
                <input type="hidden" name="utilisateur_id" value="<?php echo $uid; ?>">
                <button type="submit" class="btn btn-danger btn-sm">🗑️ Supprimer</button>
            </form>
        </div>
    </div>
 
    <div class="edit-form" id="edit-form" style="margin-top:20px;border-top:1px solid #e2e8f0;padding-top:18px;">
        <form action="ajout_complet.php" method="POST">
            <input type="hidden" name="action" value="modifier_utilisateur">
            <input type="hidden" name="utilisateur_id" value="<?php echo $uid; ?>">
 
            <div class="form-row">
                <div class="form-group">
                    <label>Nom *</label>
                    <input type="text" name="nom" required
                           value="<?php echo htmlspecialchars($utilisateur['nom']); ?>">
                </div>
                <div class="form-group" style="max-width:160px;">
                    <label>Matricule *</label>
                    <input type="text" name="matricule" required
                           value="<?php echo htmlspecialchars($utilisateur['matricule']); ?>"
                           style="font-family:monospace;">
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email"
                           value="<?php echo htmlspecialchars($utilisateur['email']); ?>">
                </div>
                <div class="form-group" style="max-width:180px;">
                    <label>Date d'ajout *</label>
                    <input type="date" name="date_ajout" required
                           value="<?php echo $utilisateur['date_ajout']; ?>">
                </div>
            </div>
            <div style="display:flex;gap:8px;margin-top:4px;">
                <button type="submit" class="btn btn-success btn-sm">💾 Enregistrer</button>
                <button type="button" class="btn btn-ghost btn-sm" onclick="toggleEdit()">Annuler</button>
            </div>
        </form>
    </div>
</div>
 
<!-- ── Liste des PDFs ── -->
<div class="card">
    <h3>📄 Fichiers PDF
        <span style="font-size:13px;font-weight:400;color:#94a3b8;">
            (<?php echo count($pdfs); ?> fichier<?php echo count($pdfs) > 1 ? 's' : ''; ?>)
        </span>
    </h3>

    <div class="matricule-rule-box" style="margin-bottom:14px;">
        <span>⚠️</span>
        <div>
            Le nom du fichier PDF doit obligatoirement commencer par le matricule
            <strong><?php echo htmlspecialchars($utilisateur['matricule']); ?></strong>,
            suivi d'un underscore <code>_</code> ou d'un tiret <code>-</code>.
            Exemple attendu : <code><?php echo htmlspecialchars($utilisateur['matricule']); ?>_document.pdf</code>
        </div>
    </div>
 
    <?php if (count($pdfs) === 0): ?>
        <div class="empty">
            <span class="eicon">📭</span>
            Aucun PDF pour cet utilisateur.
        </div>
    <?php else: ?>
    <table class="pdf-table">
        <thead>
            <tr>
                <th>#</th>
                <th>Nom du fichier</th>
                <th>Uploadé le</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($pdfs as $i => $pdf): ?>
        <tr>
            <td style="color:#94a3b8;"><?php echo $i + 1; ?></td>
            <td>
                <span class="pdf-name">📄 <?php echo htmlspecialchars($pdf['nom_fichier']); ?></span>
            </td>
            <td class="pdf-date"><?php echo date('d/m/Y H:i', strtotime($pdf['date_upload'])); ?></td>
            <td>
                <div style="display:flex;gap:6px;align-items:center;">
                    <!-- Visualiser : lien direct via GET -->
                    <a href="ajout_complet.php?action=telecharger_pdf&uid=<?php echo $uid; ?>&pdf_id=<?php echo $pdf['id']; ?>"
                       target="_blank"
                       class="btn btn-ghost btn-sm"
                       title="Ouvrir / Visualiser le PDF">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                             style="vertical-align:middle;">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                        Voir
                    </a>

                    <!-- Remplacer : bouton mis en valeur -->
                    <button type="button"
                            class="btn-replace"
                            onclick="ouvrirModal(<?php echo $pdf['id']; ?>, '<?php echo addslashes(htmlspecialchars($pdf['nom_fichier'])); ?>')"
                            title="Remplacer ce fichier PDF">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="1 4 1 10 7 10"/>
                            <polyline points="23 20 23 14 17 14"/>
                            <path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4-4.64 4.36A9 9 0 0 1 3.51 15"/>
                        </svg>
                        Remplacer
                    </button>

                    <!-- Supprimer -->
                    <form action="ajout_complet.php" method="POST"
                          onsubmit="return confirm('Supprimer ce fichier ?');">
                        <input type="hidden" name="action"         value="supprimer_pdf">
                        <input type="hidden" name="utilisateur_id" value="<?php echo $uid; ?>">
                        <input type="hidden" name="pdf_id"         value="<?php echo $pdf['id']; ?>">
                        <button type="submit" class="btn btn-danger btn-sm" title="Supprimer">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                 style="vertical-align:middle;">
                                <polyline points="3 6 5 6 21 6"/>
                                <path d="M19 6l-1 14H6L5 6"/>
                                <path d="M10 11v6M14 11v6"/>
                                <path d="M9 6V4h6v2"/>
                            </svg>
                            Supprimer
                        </button>
                    </form>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
 
<!-- ── Ajouter des PDF ── -->
<div class="card">
    <h3>➕ Ajouter des PDF</h3>

    <div class="matricule-rule-box" style="margin-bottom:14px;">
        <span>⚠️</span>
        <div>
            Le nom du fichier doit obligatoirement commencer par
            <strong><?php echo htmlspecialchars($utilisateur['matricule']); ?></strong>
            suivi d'un <code>_</code> ou d'un <code>-</code>.
            Exemple : <code><?php echo htmlspecialchars($utilisateur['matricule']); ?>_bulletin_mai.pdf</code>
        </div>
    </div>

    <form action="ajout_complet.php" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action"         value="ajouter_pdf">
        <input type="hidden" name="utilisateur_id" value="<?php echo $uid; ?>">
        <input type="hidden" name="matricule_requis" value="<?php echo htmlspecialchars($utilisateur['matricule']); ?>">
        <label>Sélectionner un ou plusieurs fichiers PDF</label>
        <input type="file" name="pdf[]" accept="application/pdf" multiple required
               id="pdfInputGerer">
        <div id="pdfNomErreur" style="display:none;color:#991b1b;font-size:12px;margin-top:6px;
             background:#fee2e2;border:1px solid #fca5a5;border-radius:6px;padding:8px 12px;"></div>
        <div style="margin-top:12px;">
            <button type="submit" class="btn btn-success" id="btnAjouterPdf">📤 Uploader</button>
        </div>
    </form>
</div>

<!-- Validation côté client du matricule pour le formulaire "Ajouter PDF" -->
<script>
(function() {
    var matricule = <?php echo json_encode(strtoupper($utilisateur['matricule'])); ?>;

    function validerNomsPdf(files) {
        var invalides = [];
        for (var i = 0; i < files.length; i++) {
            var nom = files[i].name.toUpperCase();
            // Le nom doit commencer par MATRICULE_ ou MATRICULE-
            var ok = (nom.indexOf(matricule + '_') === 0 || nom.indexOf(matricule + '-') === 0);
            if (!ok) invalides.push(files[i].name);
        }
        return invalides;
    }

    var input = document.getElementById('pdfInputGerer');
    var errDiv = document.getElementById('pdfNomErreur');
    var btnAjouter = document.getElementById('btnAjouterPdf');

    if (input) {
        input.addEventListener('change', function() {
            var invalides = validerNomsPdf(this.files);
            if (invalides.length > 0) {
                errDiv.style.display = 'block';
                errDiv.innerHTML = '❌ <strong>Fichier(s) refusé(s) :</strong> le nom doit commencer par <code>'
                    + escHtmlJs(matricule) + '_</code> ou <code>'
                    + escHtmlJs(matricule) + '-</code>.<br>'
                    + invalides.map(function(n){ return '• ' + escHtmlJs(n); }).join('<br>');
                btnAjouter.disabled = true;
            } else {
                errDiv.style.display = 'none';
                btnAjouter.disabled = false;
            }
        });
    }

    function escHtmlJs(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
})();
</script>
 
<!-- ── Historique ── -->
<div class="card">
    <h3>📋 Historique des interventions</h3>
    <?php if (count($historique) === 0): ?>
        <div class="empty">
            <span class="eicon">📜</span> Aucune action enregistrée.
        </div>
    <?php else: ?>
    <div class="hist-list">
        <?php foreach ($historique as $h):
            if (isset($action_labels[$h['action']])) {
                list($label, $color) = $action_labels[$h['action']];
            } else {
                $label = $h['action']; $color = '#888';
            }
        ?>
        <div class="hist-item">
            <span class="hist-badge" style="background:<?php echo $color; ?>">
                <?php echo $label; ?>
            </span>
            <div class="hist-detail">
                <?php if ($h['nom_fichier']): ?>
                    <div class="fichier"><?php echo htmlspecialchars($h['nom_fichier']); ?></div>
                <?php endif; ?>
                <?php if ($h['details']): ?>
                    <div class="extra"><?php echo htmlspecialchars($h['details']); ?></div>
                <?php endif; ?>
            </div>
            <div class="hist-date">
                <?php echo date('d/m/Y', strtotime($h['date_action'])); ?><br>
                <?php echo date('H:i',   strtotime($h['date_action'])); ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
 
 
<!-- ═══════════════  PAGE : UTILISATEUR INTROUVABLE  ════════ -->
<?php elseif ($page === 'gerer'): ?>
<div class="card">
    <div class="empty">
        <span class="eicon">⚠️</span>
        Utilisateur introuvable.
        <br><br>
        <a href="index.php?page=liste" class="btn btn-primary">← Retour à la liste</a>
    </div>
</div>
 
<?php endif; ?>
</main>
 
<!-- ══════════════  MODALE : REMPLACER PDF  ═════════════════ -->
<div class="modal-overlay" id="modalOverlay">
    <div class="modal">
        <h3>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                 stroke="#f59e0b" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="1 4 1 10 7 10"/>
                <polyline points="23 20 23 14 17 14"/>
                <path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4-4.64 4.36A9 9 0 0 1 3.51 15"/>
            </svg>
            Remplacer le fichier
        </h3>
        <p class="modal-subtitle" id="modalFileName"></p>

        <?php if ($utilisateur): ?>
        <div class="modal-matricule-info">
            ⚠️ Le nouveau fichier doit obligatoirement commencer par
            <strong><?php echo htmlspecialchars($utilisateur['matricule']); ?></strong>
            suivi d'un <code>_</code> ou d'un <code>-</code>.
        </div>
        <?php endif; ?>

        <form action="ajout_complet.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action"         value="remplacer_pdf">
            <input type="hidden" name="utilisateur_id" value="<?php echo $uid; ?>">
            <input type="hidden" name="pdf_id"         id="modalPdfId">
            <label>Nouveau fichier PDF</label>
            <input type="file" name="pdf_remplace" accept="application/pdf" required
                   id="modalFileInput">
            <div id="modalErreur" style="display:none;color:#991b1b;font-size:12px;margin-top:6px;
                 background:#fee2e2;border:1px solid #fca5a5;border-radius:6px;padding:8px 12px;"></div>
            <div class="btn-row">
                <button type="button" class="btn btn-ghost" onclick="fermerModal()">Annuler</button>
                <button type="submit" class="btn-replace" id="modalBtnRemplacer">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="1 4 1 10 7 10"/>
                        <polyline points="23 20 23 14 17 14"/>
                        <path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4-4.64 4.36A9 9 0 0 1 3.51 15"/>
                    </svg>
                    Remplacer
                </button>
            </div>
        </form>
    </div>
</div>
 
<script>
/* Modale remplacement */
function ouvrirModal(pdfId, nom) {
    document.getElementById('modalPdfId').value = pdfId;
    document.getElementById('modalFileName').textContent = 'Fichier actuel : ' + nom;
    document.getElementById('modalErreur').style.display = 'none';
    document.getElementById('modalBtnRemplacer').disabled = false;
    document.getElementById('modalFileInput').value = '';
    document.getElementById('modalOverlay').className = 'modal-overlay open';
}
function fermerModal() {
    document.getElementById('modalOverlay').className = 'modal-overlay';
}
document.getElementById('modalOverlay').onclick = function(e) {
    if (e.target === this) fermerModal();
};

/* Validation du nom de fichier dans la modale */
<?php if ($utilisateur): ?>
(function() {
    var matricule = <?php echo json_encode(strtoupper($utilisateur['matricule'])); ?>;
    var input   = document.getElementById('modalFileInput');
    var errDiv  = document.getElementById('modalErreur');
    var btnRem  = document.getElementById('modalBtnRemplacer');

    if (input) {
        input.addEventListener('change', function() {
            if (this.files.length === 0) return;
            var nom = this.files[0].name.toUpperCase();
            var ok  = (nom.indexOf(matricule + '_') === 0 || nom.indexOf(matricule + '-') === 0);
            if (!ok) {
                errDiv.style.display = 'block';
                errDiv.innerHTML = '❌ Le nom doit commencer par <code>' + matricule + '_</code> ou <code>'
                    + matricule + '-</code>. Fichier sélectionné : <em>' + escHtmlModal(this.files[0].name) + '</em>';
                btnRem.disabled = true;
            } else {
                errDiv.style.display = 'none';
                btnRem.disabled = false;
            }
        });
    }

    function escHtmlModal(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
})();
<?php endif; ?>
 
/* Formulaire édition utilisateur */
function toggleEdit() {
    var f = document.getElementById('edit-form');
    if (f.className.indexOf('open') === -1) {
        f.className = 'edit-form open';
    } else {
        f.className = 'edit-form';
    }
}
</script>
 
</body>
</html>