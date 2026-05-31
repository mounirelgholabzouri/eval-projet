<?php
/**
 * Migration : import Excel AvancementProgramme2025 → eval_online
 * 19 filières  |  117 groupes  |  69 formateurs
 */

require_once __DIR__ . '/../../config/database.php';

$pdo = getDB();
echo "=== Import Excel ISTA 2025 → eval_online ===\n\n";

// ══════════════════════════════════════════════════════════════════════
// 1. Filières
// ══════════════════════════════════════════════════════════════════════
echo "── 1. Filières ──\n";

$filieres = [
    ['GC_GE_TS', 'Gestion des Entreprises', 'Gestion et Commerce', 'TS', 'Diplômante'],
    ['SAN_PD_TS', 'Prothésiste Dentaire', 'Santé', 'TS', 'Diplômante'],
    ['PS_AD_TS', 'Assistance Dentaire', 'Santé', 'TS', 'Diplômante'],
    ['DIA_DEVOWFS_TS', 'Développement Digital option Web Full Stack', 'Digital et Intelligence Artificielle', 'TS', 'Diplômante'],
    ['DIA_DEV_TS', 'Développement Digital', 'Digital et Intelligence Artificielle', 'TS', 'Diplômante'],
    ['DIA_ID_TS', 'Infrastructure Digitale', 'Digital et Intelligence Artificielle', 'TS', 'Diplômante'],
    ['GC_GEOCF_TS', 'Gestion des Entreprises option Comptabilité et Finance', 'Gestion et Commerce', 'TS', 'Diplômante'],
    ['GC_GEOCM_TS', 'Gestion des Entreprises option Commerce et Marketing', 'Gestion et Commerce', 'TS', 'Diplômante'],
    ['GC_GEORH_TS', 'Gestion des Entreprises option Ressources Humaines', 'Gestion et Commerce', 'TS', 'Diplômante'],
    ['DIA_SMW_FQ', 'Spécialiste en Microsoft Word', 'Digital et Intelligence Artificielle', 'FQ', 'Qualifiante'],
    ['SS_APILC_FQ', 'Accueil professionnel, interculturalité et leadership citoyen', 'Développement Personnel et Professionnel', 'FQ', 'Qualifiante'],
    ['DIA_IDOCC_TS', 'Infrastructure Digitale option  Cloud Computing', 'Digital et Intelligence Artificielle', 'TS', 'Diplômante'],
    ['DIA_IDOCS_TS', 'Infrastructure Digitale option Cyber sécurité', 'Digital et Intelligence Artificielle', 'TS', 'Diplômante'],
    ['DIA_IDOSR_TS', 'Infrastructure Digitale option Systèmes et Réseaux', 'Digital et Intelligence Artificielle', 'TS', 'Diplômante'],
    ['GC_GEOCF_TS_RCDS', 'Gestion des Entreprises option Comptabilité et Finance', 'Gestion et Commerce', 'TS', 'Diplômante'],
    ['DIA_SME_FQ', 'Spécialiste en Microsoft Excel', 'Digital et Intelligence Artificielle', 'FQ', 'Qualifiante'],
    ['GC_PIE_FQ', 'Programme d\'Innovation Entrepreneuriale : de l\'idée au projet viable', 'Gestion et Commerce', 'FQ', 'Qualifiante'],
    ['SS_RP_FQ', 'Résolution des problèmes ', 'Développement Personnel et Professionnel', 'FQ', 'Qualifiante'],
    ['SS_GS_FQ', 'Gestion du stress', 'Développement Personnel et Professionnel', 'FQ', 'Qualifiante'],
];

$stmtFilChk = $pdo->prepare("SELECT id FROM ref_filieres WHERE code = ?");
$stmtFilIns = $pdo->prepare("INSERT INTO ref_filieres (code, nom, secteur, niveau, type_formation) VALUES (?,?,?,?,?)");
$stmtFilUpd = $pdo->prepare("UPDATE ref_filieres SET nom=?, secteur=?, niveau=?, type_formation=? WHERE code=?");
$nFI = $nFU = 0;
foreach ($filieres as [$code, $nom, $secteur, $niveau, $type]) {
    $stmtFilChk->execute([$code]);
    if ($stmtFilChk->fetch()) {
        $stmtFilUpd->execute([$nom, $secteur, $niveau, $type, $code]);
        echo "  ↺ MAJ    : $code — $nom\n";
        $nFU++;
    } else {
        $stmtFilIns->execute([$code, $nom, $secteur, $niveau, $type]);
        echo "  ✓ Inséré : $code — $nom\n";
        $nFI++;
    }
}
echo "  → $nFI inséré(s), $nFU mis à jour\n\n";

// ══════════════════════════════════════════════════════════════════════
// 2. Groupes
// ══════════════════════════════════════════════════════════════════════
echo "── 2. Groupes ──\n";

$groupes = [
    ['GE112', 'GC_GE_TS', '1A', 'CDJ', 1, 'GE111 GE112 GE115 ', '595542', 18, 'presentiel'],
    ['GE110', 'GC_GE_TS', '1A', 'CDJ', 1, 'GE110 GE109 ', '584987', 18, 'presentiel'],
    ['GE114', 'GC_GE_TS', '1A', 'CDJ', 1, 'GE113 GE114 ', '595648', 17, 'presentiel'],
    ['GE108', 'GC_GE_TS', '1A', 'CDJ', 1, 'GE108 GE107 ', '584580', 22, 'presentiel'],
    ['GE103', 'GC_GE_TS', '1A', 'CDJ', 1, 'GE104 GE103 ', '583420', 22, 'presentiel'],
    ['PD101', 'SAN_PD_TS', '1A', 'CDJ', 1, null, null, 25, 'presentiel'],
    ['PD102', 'SAN_PD_TS', '1A', 'CDJ', 1, null, null, 25, 'presentiel'],
    ['PD302', 'SAN_PD_TS', '3A', 'CDJ', 3, null, null, 17, 'presentiel'],
    ['AD201', 'PS_AD_TS', '2A', 'CDJ', 2, null, null, 22, 'presentiel'],
    ['DEVOWFS202', 'DIA_DEVOWFS_TS', '2A', 'CDJ', 2, 'DEVOWFS202 DEVOWFS204 ', '573869', 22, 'presentiel'],
    ['DEVOWFS203', 'DIA_DEVOWFS_TS', '2A', 'CDJ', 2, 'DEVOWFS203 ', '573909', 24, 'presentiel'],
    ['DEVOWFS205', 'DIA_DEVOWFS_TS', '2A', 'CDJ', 2, 'DEVOWFS204 DEVOWFS205 ', '573912', 16, 'presentiel'],
    ['DEV102', 'DIA_DEV_TS', '1A', 'CDJ', 1, null, null, 22, 'presentiel'],
    ['ID102', 'DIA_ID_TS', '1A', 'CDJ', 1, 'ID103 ID102 ', '680125', 19, 'presentiel'],
    ['ID104', 'DIA_ID_TS', '1A', 'CDJ', 1, 'ID104 ', '666057', 21, 'presentiel'],
    ['GEOCF206', 'GC_GEOCF_TS', '2A', 'CDJ', 2, 'GEOCF203 GEOCF205 GEOCF206 ', '615988', 14, 'presentiel'],
    ['GEOCF303', 'GC_GEOCF_TS', '3A', 'CDJ', 3, null, null, 22, 'presentiel'],
    ['GEOCM202', 'GC_GEOCM_TS', '2A', 'CDJ', 2, 'GEOCM201 GEOCM202 GEOCM203 ', '578676', 22, 'presentiel'],
    ['GEOCM301', 'GC_GEOCM_TS', '3A', 'CDJ', 3, 'GEOCM301 GEOCM302 ', '575612', 23, 'presentiel'],
    ['GEOCM203', 'GC_GEOCM_TS', '2A', 'CDJ', 2, 'GEOCM202 GEOCM203 GEOCM204 ', '681258', 24, 'presentiel'],
    ['GEORH202', 'GC_GEORH_TS', '2A', 'CDJ', 2, 'GEORH202 GEORH201 ', '583543', 15, 'presentiel'],
    ['SMW103', 'DIA_SMW_FQ', '1A', 'CDJ', 1, null, null, 29, 'presentiel'],
    ['APILC114', 'SS_APILC_FQ', '1A', 'CDJ', 1, null, null, 6, 'presentiel'],
    ['APILC130', 'SS_APILC_FQ', '1A', 'CDJ', 1, null, null, 26, 'presentiel'],
    ['GE102', 'GC_GE_TS', '1A', 'CDJ', 1, 'GE102 GE105 ', '658453', 19, 'presentiel'],
    ['GE101', 'GC_GE_TS', '1A', 'CDJ', 1, 'GE102 GE101 ', '582739', 21, 'presentiel'],
    ['GE115', 'GC_GE_TS', '1A', 'CDJ', 1, 'GE113 GE115 GE112 ', '683845', 16, 'presentiel'],
    ['GE113', 'GC_GE_TS', '1A', 'CDJ', 1, 'GE113 ', '676179', 18, 'presentiel'],
    ['GE104', 'GC_GE_TS', '1A', 'CDJ', 1, 'GE103 GE104 ', '585095', 22, 'presentiel'],
    ['GE105', 'GC_GE_TS', '1A', 'CDJ', 1, 'GE106 GE105 ', '684287', 19, 'presentiel'],
    ['GE106', 'GC_GE_TS', '1A', 'CDJ', 1, null, null, 19, 'presentiel'],
    ['PD202', 'SAN_PD_TS', '2A', 'CDJ', 2, null, null, 23, 'presentiel'],
    ['PD201', 'SAN_PD_TS', '2A', 'CDJ', 2, null, null, 25, 'presentiel'],
    ['IDOCC201', 'DIA_IDOCC_TS', '2A', 'CDJ', 2, 'IDOCC201 ', '575114', 24, 'presentiel'],
    ['IDOCS201', 'DIA_IDOCS_TS', '2A', 'CDJ', 2, 'IDOCS201 ', '578861', 25, 'presentiel'],
    ['ID106', 'DIA_ID_TS', '1A', 'CDJ', 1, null, null, 25, 'presentiel'],
    ['ID103', 'DIA_ID_TS', '1A', 'CDJ', 1, 'ID104 ID103 ', '681190', 22, 'presentiel'],
    ['IDOSR201', 'DIA_IDOSR_TS', '2A', 'CDJ', 2, 'IDOSR201 ', '575137', 18, 'presentiel'],
    ['APILC116', 'SS_APILC_FQ', '1A', 'CDJ', 1, null, null, 10, 'presentiel'],
    ['GEOCF203', 'GC_GEOCF_TS', '2A', 'CDJ', 2, 'GEOCF203 ', '683686', 20, 'presentiel'],
    ['GEOCF205', 'GC_GEOCF_TS', '2A', 'CDJ', 2, 'GEOCF204 GEOCF205 ', '651034', 16, 'presentiel'],
    ['GEOCF302', 'GC_GEOCF_TS', '3A', 'CDJ', 3, null, null, 20, 'presentiel'],
    ['GEOCM206', 'GC_GEOCM_TS', '2A', 'CDJ', 2, 'GEOCM205 GEOCM206 ', '670319', 20, 'presentiel'],
    ['GEOCM205', 'GC_GEOCM_TS', '2A', 'CDJ', 2, 'GEOCM204 GEOCM205 ', '681396', 19, 'presentiel'],
    ['GEOCM201', 'GC_GEOCM_TS', '2A', 'CDJ', 2, null, null, 22, 'presentiel'],
    ['GEORH201', 'GC_GEORH_TS', '2A', 'CDJ', 2, 'GEORH202 GEORH201 ', '578631', 20, 'presentiel'],
    ['GEORH301', 'GC_GEORH_TS', '3A', 'CDJ', 3, 'GEORH301 ', '587850', 21, 'presentiel'],
    ['GE111', 'GC_GE_TS', '1A', 'CDJ', 1, 'GE111 GE112 GE115 ', '595542', 17, 'presentiel'],
    ['GE109', 'GC_GE_TS', '1A', 'CDJ', 1, 'GE109 GE110 ', '583150', 19, 'presentiel'],
    ['DEVOWFS201', 'DIA_DEVOWFS_TS', '2A', 'CDJ', 2, 'DEVOWFS201 ', '573924', 22, 'presentiel'],
    ['DEVOWFS204', 'DIA_DEVOWFS_TS', '2A', 'CDJ', 2, 'DEVOWFS204 ', '573947', 22, 'presentiel'],
    ['DEV101', 'DIA_DEV_TS', '1A', 'CDJ', 1, 'DEV102 DEV103 DEV101 ', '573822', 21, 'presentiel'],
    ['DEV103', 'DIA_DEV_TS', '1A', 'CDJ', 1, 'DEV103 ', '573841', 22, 'presentiel'],
    ['ID105', 'DIA_ID_TS', '1A', 'CDJ', 1, 'ID105 ', '681192', 17, 'presentiel'],
    ['ID101', 'DIA_ID_TS', '1A', 'CDJ', 1, null, null, 21, 'presentiel'],
    ['APILC104', 'SS_APILC_FQ', '1A', 'CDJ', 1, null, null, 15, 'presentiel'],
    ['APILC119', 'SS_APILC_FQ', '1A', 'CDJ', 1, null, null, 18, 'presentiel'],
    ['APILC122', 'SS_APILC_FQ', '1A', 'CDJ', 1, null, null, 22, 'presentiel'],
    ['GEOCF204', 'GC_GEOCF_TS', '2A', 'CDJ', 2, 'GEOCF206 GEOCF204 ', '681675', 22, 'presentiel'],
    ['GEOCF301', 'GC_GEOCF_TS', '3A', 'CDJ', 3, 'GEOCF301 GEOCF302 ', '660671', 24, 'presentiel'],
    ['GEOCM204', 'GC_GEOCM_TS', '2A', 'CDJ', 2, null, null, 23, 'presentiel'],
    ['AD101', 'PS_AD_TS', '1A', 'CDJ', 1, null, null, 23, 'presentiel'],
    ['GEOCF202', 'GC_GEOCF_TS', '2A', 'CDJ', 2, null, null, 21, 'presentiel'],
    ['GEOCM302', 'GC_GEOCM_TS', '3A', 'CDJ', 3, 'GEOCM304 GEOCM301 GEOCM302 GEOCM303 ', '606174', 24, 'presentiel'],
    ['GEOCF101', 'GC_GEOCF_TS_RCDS', '1A', 'CDS', 1, null, null, 19, 'presentiel'],
    ['SME105', 'DIA_SME_FQ', '1A', 'CDJ', 1, null, null, 29, 'presentiel'],
    ['APILC102', 'SS_APILC_FQ', '1A', 'CDJ', 1, null, null, 14, 'presentiel'],
    ['SMW106', 'DIA_SMW_FQ', '1A', 'CDJ', 1, null, null, 27, 'presentiel'],
    ['SMW104', 'DIA_SMW_FQ', '1A', 'CDJ', 1, null, null, 29, 'presentiel'],
    ['GEOCF201', 'GC_GEOCF_TS', '2A', 'CDJ', 2, 'GEOCF206 GEOCF201 ', '651047', 20, 'presentiel'],
    ['SME103', 'DIA_SME_FQ', '1A', 'CDJ', 1, null, null, 27, 'presentiel'],
    ['SMW102', 'DIA_SMW_FQ', '1A', 'CDJ', 1, null, null, 28, 'presentiel'],
    ['GEOCM304', 'GC_GEOCM_TS', '3A', 'CDJ', 3, null, null, 23, 'presentiel'],
    ['PIE101', 'GC_PIE_FQ', '1A', 'CDJ', 1, null, null, 27, 'presentiel'],
    ['GE107', 'GC_GE_TS', '1A', 'CDJ', 1, 'GE108 GE107 ', '584579', 20, 'presentiel'],
    ['APILC108', 'SS_APILC_FQ', '1A', 'CDJ', 1, null, null, 15, 'presentiel'],
    ['PIE105', 'GC_PIE_FQ', '1A', 'CDJ', 1, null, null, 17, 'presentiel'],
    ['PIE102', 'GC_PIE_FQ', '1A', 'CDJ', 1, null, null, 20, 'presentiel'],
    ['PD301', 'SAN_PD_TS', '3A', 'CDJ', 3, null, null, 22, 'presentiel'],
    ['APILC107', 'SS_APILC_FQ', '1A', 'CDJ', 1, null, null, 10, 'presentiel'],
    ['APILC111', 'SS_APILC_FQ', '1A', 'CDJ', 1, null, null, 13, 'presentiel'],
    ['GEOCM303', 'GC_GEOCM_TS', '3A', 'CDJ', 3, 'GEOCM304 GEOCM301 GEOCM303 ', '587671', 23, 'presentiel'],
    ['RP101', 'SS_RP_FQ', '1A', 'CDJ', 1, null, null, 30, 'presentiel'],
    ['SME104', 'DIA_SME_FQ', '1A', 'CDJ', 1, null, null, 30, 'presentiel'],
    ['APILC101', 'SS_APILC_FQ', '1A', 'CDJ', 1, null, null, 14, 'presentiel'],
    ['APILC121', 'SS_APILC_FQ', '1A', 'CDJ', 1, null, null, 21, 'presentiel'],
    ['PIE103', 'GC_PIE_FQ', '1A', 'CDJ', 1, null, null, 26, 'presentiel'],
    ['PIE104', 'GC_PIE_FQ', '1A', 'CDJ', 1, null, null, 17, 'presentiel'],
    ['PIE107', 'GC_PIE_FQ', '1A', 'CDJ', 1, null, null, 20, 'presentiel'],
    ['PIE108', 'GC_PIE_FQ', '1A', 'CDJ', 1, null, null, 17, 'presentiel'],
    ['SME106', 'DIA_SME_FQ', '1A', 'CDJ', 1, null, null, 25, 'presentiel'],
    ['SMW105', 'DIA_SMW_FQ', '1A', 'CDJ', 1, null, null, 28, 'presentiel'],
    ['PIE106', 'GC_PIE_FQ', '1A', 'CDJ', 1, null, null, 24, 'presentiel'],
    ['SME101', 'DIA_SME_FQ', '1A', 'CDJ', 1, null, null, 28, 'presentiel'],
    ['SMW101', 'DIA_SMW_FQ', '1A', 'CDJ', 1, null, null, 28, 'presentiel'],
    ['SME102', 'DIA_SME_FQ', '1A', 'CDJ', 1, null, null, 28, 'presentiel'],
    ['APILC110', 'SS_APILC_FQ', '1A', 'CDJ', 1, null, null, 15, 'presentiel'],
    ['APILC126', 'SS_APILC_FQ', '1A', 'CDJ', 1, null, null, 23, 'presentiel'],
    ['APILC127', 'SS_APILC_FQ', '1A', 'CDJ', 1, null, null, 24, 'presentiel'],
    ['APILC123', 'SS_APILC_FQ', '1A', 'CDJ', 1, null, null, 20, 'presentiel'],
    ['GS101', 'SS_GS_FQ', '1A', 'CDJ', 1, null, null, 23, 'presentiel'],
    ['RP103', 'SS_RP_FQ', '1A', 'CDJ', 1, null, null, 5, 'presentiel'],
    ['RP102', 'SS_RP_FQ', '1A', 'CDJ', 1, null, null, 30, 'presentiel'],
    ['APILC106', 'SS_APILC_FQ', '1A', 'CDJ', 1, null, null, 16, 'presentiel'],
    ['APILC129', 'SS_APILC_FQ', '1A', 'CDJ', 1, null, null, 29, 'presentiel'],
    ['APILC125', 'SS_APILC_FQ', '1A', 'CDJ', 1, null, null, 20, 'presentiel'],
    ['APILC103', 'SS_APILC_FQ', '1A', 'CDJ', 1, null, null, 15, 'presentiel'],
    ['APILC117', 'SS_APILC_FQ', '1A', 'CDJ', 1, null, null, 16, 'presentiel'],
    ['APILC118', 'SS_APILC_FQ', '1A', 'CDJ', 1, null, null, 19, 'presentiel'],
    ['APILC109', 'SS_APILC_FQ', '1A', 'CDJ', 1, null, null, 13, 'presentiel'],
    ['APILC128', 'SS_APILC_FQ', '1A', 'CDJ', 1, null, null, 26, 'presentiel'],
    ['APILC124', 'SS_APILC_FQ', '1A', 'CDJ', 1, null, null, 21, 'presentiel'],
    ['APILC113', 'SS_APILC_FQ', '1A', 'CDJ', 1, null, null, 6, 'presentiel'],
    ['APILC115', 'SS_APILC_FQ', '1A', 'CDJ', 1, null, null, 7, 'presentiel'],
    ['APILC120', 'SS_APILC_FQ', '1A', 'CDJ', 1, null, null, 17, 'presentiel'],
    ['APILC105', 'SS_APILC_FQ', '1A', 'CDJ', 1, null, null, 10, 'presentiel'],
    ['APILC112', 'SS_APILC_FQ', '1A', 'CDJ', 1, null, null, 11, 'presentiel'],
];

$stmtGrpChk = $pdo->prepare("SELECT id FROM groupes_emploi WHERE code = ?");
$stmtFilId  = $pdo->prepare("SELECT id FROM ref_filieres WHERE code = ?");
$stmtGrpIns = $pdo->prepare("INSERT INTO groupes_emploi (code, filiere_id, annee, creneau, annee_formation, fusion_groupes, code_fusion, effectif, mode_formation) VALUES (?,?,?,?,?,?,?,?,?)");
$stmtGrpUpd = $pdo->prepare("UPDATE groupes_emploi SET filiere_id=?, annee=?, creneau=?, annee_formation=?, fusion_groupes=?, code_fusion=?, effectif=?, mode_formation=? WHERE code=?");
$nGI = $nGU = $nGS = 0;
foreach ($groupes as [$code, $fil_code, $annee, $creneau, $annee_f, $fusion, $code_fusion, $eff, $mode]) {
    $stmtFilId->execute([$fil_code]);
    $filRow = $stmtFilId->fetch(PDO::FETCH_ASSOC);
    if (!$filRow) {
        echo "  ⚠  Filière $fil_code introuvable pour groupe $code\n";
        $nGS++; continue;
    }
    $fil_id = $filRow['id'];
    $stmtGrpChk->execute([$code]);
    if ($stmtGrpChk->fetch()) {
        $stmtGrpUpd->execute([$fil_id, $annee, $creneau, $annee_f, $fusion, $code_fusion, $eff, $mode, $code]);
        echo "  ↺ MAJ    : $code\n";
        $nGU++;
    } else {
        $stmtGrpIns->execute([$code, $fil_id, $annee, $creneau, $annee_f, $fusion, $code_fusion, $eff, $mode]);
        echo "  ✓ Inséré : $code (eff.$eff)\n";
        $nGI++;
    }
}
echo "  → $nGI inséré(s), $nGU mis à jour, $nGS ignoré(s)\n\n";

// ══════════════════════════════════════════════════════════════════════
// 3. Formateurs
// ══════════════════════════════════════════════════════════════════════
echo "── 3. Formateurs ──\n";

$formateurs = [
    ['13955', 'Mouhtat', 'Fatima'],
    ['20201', 'Maazi', 'Khalid'],
    ['12274', 'Ritel', 'Abdelouahed'],
    ['11737', 'Chlarhmi', 'Driss'],
    ['11922', 'Ahdidou', 'Khalifa'],
    ['13962', 'Haroui', 'Yassine'],
    ['12611', 'Tahiri', 'Meryem'],
    ['16443', 'El Kheyyat', 'Chaimae'],
    ['AE85919', 'Lazrak', 'Siham'],
    ['A353749', 'Essaadi', 'Houda'],
    ['A149609', 'Bisbis', 'Nadia'],
    ['A15404', 'Chana', 'Azzedine'],
    ['IB161988', 'Tahiri', 'Tarik'],
    ['HA58441', 'Achour', 'Ahmed'],
    ['9626', 'Abdallah', 'Naoual'],
    ['10952', 'Bourous', 'Imane'],
    ['18298', 'Alouah', 'Aicha'],
    ['9568', 'Imami', 'Hniya'],
    ['K120603', 'Jaafar', 'Fatima'],
    ['10063', 'Zeriate', 'Mohammed'],
    ['16093', 'Dinar', 'Brahim'],
    ['15625', 'Aboulfadl', 'Aicha'],
    ['13412', 'Ouhdidou', 'Ismail'],
    ['9996', 'Jbilou', 'Samira'],
    ['13825', 'Idrissi Hossaini', 'Ichrak'],
    ['13952', 'Mouline', 'Icam'],
    ['18945', 'Birbir', 'Amine'],
    ['19411', 'El Meliani', 'Hajar'],
    ['13411', 'Ouazzine', 'Mounir'],
    ['11957', 'Aabioui', 'Halima'],
    ['11734', 'El Marhani', 'Hafida'],
    ['13864', 'Amal Nacer El Oualji', 'Mohamed'],
    ['16457', 'Khejjou', 'Ismail'],
    ['A278953', 'Reda Guedira', 'Mohammed'],
    ['A198883', 'En-Naciri', 'Housni'],
    ['10021', 'Elgholabzouri', 'Mounir'],
    ['13313', 'Bensajjay', 'Fatiha'],
    ['11212', 'N\'Dour', 'Majabel'],
    ['10066', 'Bekkaoui', 'Karim'],
    ['18488', 'Garmaji', 'Hanane'],
    ['18643', 'El Atifi', 'Ikbal'],
    ['13725', 'Chbani', 'Bouchra'],
    ['10094', 'Karima Bencherif', 'Lalla'],
    ['A148839', 'Lamadel', 'Mohamed'],
    ['10941', 'Fourka', 'Kaouthar'],
    ['18195', 'Erraji', 'Nadia'],
    ['11735', 'Zakani', 'Nadia'],
    ['A333289', 'Kabaili', 'Kaoutar'],
    ['9540', 'Naciri', 'Karima'],
    ['12392', 'Ezzahra Ghoudouou', 'Fatima'],
    ['19693', 'Alkoum', 'Meryem'],
    ['A319799', 'Fekri', 'Hicham'],
    ['11736', 'Radi', 'Aalya'],
    ['13398', 'El Koubra', 'Nizar'],
    ['GK15397', 'El Malouki', 'Mokhtar'],
    ['A434495', 'Mohib', 'Amal'],
    ['11309', 'Moussaif', 'Randa'],
    ['C235627', 'Zahra Mohsine', 'Fatima'],
    ['12406', 'Barhmia', 'Yassine'],
    ['A797080', 'Fouadi', 'Salaheddine'],
    ['11859', 'Aknin', 'Azzeddine'],
    ['A739354', 'Manouri', 'Jawad'],
    ['BJ249', 'Ikaoui', 'Lahcen'],
    ['A701917', 'Ourahou', 'Salima'],
    ['16476', 'Mouhajra', 'Hicham'],
    ['AD180871', 'El Marbouh', 'Mohamed'],
    ['A657427', 'Azrioul', 'Elmahdi'],
    ['BE544532', 'El Moujahid', 'Abdessamad'],
    ['18741', 'Laarbi Barkati', 'Mohamed'],
];

$stmtFrmChk = $pdo->prepare("SELECT id FROM formateurs WHERE matricule = ?");
$stmtFrmIns = $pdo->prepare("INSERT INTO formateurs (matricule, nom, prenom, actif) VALUES (?,?,?,1)");
$stmtFrmUpd = $pdo->prepare("UPDATE formateurs SET nom=?, prenom=? WHERE matricule=?");
$nFrmI = $nFrmU = 0;
foreach ($formateurs as [$mle, $nom, $prenom]) {
    $stmtFrmChk->execute([$mle]);
    if ($stmtFrmChk->fetch()) {
        $stmtFrmUpd->execute([$nom, $prenom, $mle]);
        echo "  ↺ MAJ    : $prenom $nom ($mle)\n";
        $nFrmU++;
    } else {
        $stmtFrmIns->execute([$mle, $nom, $prenom]);
        echo "  ✓ Inséré : $prenom $nom ($mle)\n";
        $nFrmI++;
    }
}
echo "  → $nFrmI inséré(s), $nFrmU mis à jour\n\n";

echo "=== Résumé final ===\n";
$nbFil = $pdo->query('SELECT COUNT(*) FROM ref_filieres')->fetchColumn();
$nbGrp = $pdo->query('SELECT COUNT(*) FROM groupes_emploi')->fetchColumn();
$nbFrm = $pdo->query('SELECT COUNT(*) FROM formateurs')->fetchColumn();
echo "  ref_filieres   : $nbFil ligne(s)\n";
echo "  groupes_emploi : $nbGrp ligne(s)\n";
echo "  formateurs     : $nbFrm ligne(s)\n";
