<?php
/**
 * Export Excel (.xlsx) — Moyenne des contrôles continus par module.
 *   ?module_id=X  → une feuille pour ce module
 *   (sans module) → une feuille par module ayant des notes de CC
 *   &groupe_id=Y  → optionnel, limite à un groupe
 *
 * Colonnes : une par CC (numéro × mode). Les variantes théoriques d'un même numéro
 * et les CC pratiques des différents groupes sont fusionnées dans la même colonne.
 * Note théorique = dernière session terminée du stagiaire, ramenée sur 20.
 * Note pratique = total de la grille (cc_pratique_notes), ramené sur 20 ; absent = 0.
 * Absent à un CC = 0 : un CC compte pour une promo (groupe + année scolaire) quand au moins
 * SEUIL_CC_ORGANISE (30 %) de ses stagiaires ont une note ; ceux sans note y ont 0 (en rouge).
 * Un CC non organisé pour la promo reste vide et n'entre pas dans la moyenne.
 * Regroupement : les CC de la promo (dans l'ordre CC1 T, CC1 P, CC2 T…) sont découpés en 3 parties
 * consécutives (4 → 2+1+1, 5 → 2+2+1) ; chaque partie = moyenne de ses CC → colonnes CC1/CC2/CC3 (regroupé).
 * Moyenne CC = moyenne de CC1, CC2, CC3 regroupés.
 * Chaque feuille module a une ligne « Moyenne du groupe X » par groupe (si plusieurs groupes).
 * Première feuille « Synthèse par groupe » : moyenne CC par groupe × module.
 */
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/spreadsheet.php';

const SEUIL_CC_ORGANISE = 0.30; // part minimale de la promo ayant passé un CC pour qu'il compte

$pdo      = getDB();
$moduleId = (int)($_GET['module_id'] ?? 0);
$groupeId = (int)($_GET['groupe_id'] ?? 0);

$modules = $moduleId > 0
    ? array_filter([getModule($moduleId)])
    : getAllModules();
if (!$modules) { http_response_code(404); exit('Module introuvable.'); }

$groupesNoms = [];
foreach (getGroupes() as $g) $groupesNoms[(int)$g['id']] = $g['nom'];

/** Calcule le tableau des moyennes CC d'un module : ['slots' => [key => label], 'lignes' => [...]] */
function moyennesCCModule(PDO $pdo, int $moduleId, int $groupeId): array {
    $evals = $pdo->prepare("SELECT id, mode, numero, note_max, meta_json FROM evaluations
                            WHERE module_id = ? AND type = 'cc' ORDER BY numero, mode DESC, id");
    $evals->execute([$moduleId]);

    $slots  = [];   // key => label
    $notes  = [];   // stagiaire_id => key => note/20
    $dates  = [];   // stagiaire_id => key => date (pour garder la dernière session théorique)
    $groupesIds = [];
    $pratVues   = [];   // "numero|groupe" déjà pris

    foreach ($evals->fetchAll() as $e) {
        // Notes injectées par « Regrouper → CC1/CC2/CC3 » (Notes par groupe) : déjà issues des autres contrôles
        if (!empty((json_decode($e['meta_json'] ?? '{}', true) ?: [])['regroupe'])) continue;
        $num     = (int)$e['numero'];
        $prat    = $e['mode'] === 'pratique';
        $key     = ($prat ? 'P' : 'T') . str_pad((string)$num, 3, '0', STR_PAD_LEFT);
        $label   = 'CC' . $num . ($prat ? ' Pratique' : ' Théorique');
        $noteMax = (float)($e['note_max'] ?: 20);
        $trouve  = false;

        if ($prat) {
            $meta = json_decode($e['meta_json'] ?? '{}', true) ?: [];
            $gid  = (int)($meta['groupe_id'] ?? 0);
            if ($groupeId > 0 && $gid !== $groupeId) continue;
            // Doublon (même numéro, même groupe) : on garde la première grille, comme gestion_impression.php
            if (isset($pratVues["$num|$gid"])) continue;
            $pratVues["$num|$gid"] = true;
            $q = $pdo->prepare("SELECT stagiaire_id, total, absent FROM cc_pratique_notes WHERE evaluation_id = ?");
            $q->execute([(int)$e['id']]);
            foreach ($q->fetchAll() as $r) {
                if (!(int)$r['absent'] && $r['total'] === null) continue;
                $note = (int)$r['absent'] ? 0.0 : (float)$r['total'] / $noteMax * 20;
                $notes[(int)$r['stagiaire_id']][$key] = round($note, 2);
                $trouve = true;
            }
            if ($trouve && $gid) $groupesIds[$gid] = true;
        } else {
            $sql = "SELECT se.stagiaire_id, se.pourcentage, se.date_fin, st.groupe_id
                    FROM sessions_eval se JOIN stagiaires st ON st.id = se.stagiaire_id
                    WHERE se.evaluation_id = ? AND se.statut = 'termine'";
            $params = [(int)$e['id']];
            if ($groupeId > 0) { $sql .= " AND st.groupe_id = ?"; $params[] = $groupeId; }
            $q = $pdo->prepare($sql);
            $q->execute($params);
            foreach ($q->fetchAll() as $r) {
                $sid = (int)$r['stagiaire_id'];
                $d   = (string)$r['date_fin'];
                if (isset($dates[$sid][$key]) && $dates[$sid][$key] >= $d) continue;
                $dates[$sid][$key] = $d;
                $notes[$sid][$key] = round((float)$r['pourcentage'] / 5, 2);
                $groupesIds[(int)$r['groupe_id']] = true;
                $trouve = true;
            }
        }
        if ($trouve) $slots[$key] = $label;
    }

    // Ordre des colonnes : CC1 Théorique, CC1 Pratique, CC2 Théorique…
    uksort($slots, fn($a, $b) => strcmp(substr($a, 1), substr($b, 1)) ?: strcmp($b[0], $a[0]));

    // Stagiaires des groupes concernés ; promo = groupe + année scolaire
    $lignes = [];
    if ($slots && $groupesIds) {
        $ids = array_keys($groupesIds);
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $st  = $pdo->prepare("SELECT id, nom, prenom, groupe_id, annee_scolaire FROM stagiaires
                              WHERE groupe_id IN ($in) ORDER BY groupe_id, nom, prenom");
        $st->execute($ids);
        $stagiaires = $st->fetchAll();

        // CC organisés par promo : au moins SEUIL_CC_ORGANISE des stagiaires de la promo ont une note
        // (évite de mettre 0 à toute une promo pour un stagiaire isolé)
        $effectif = []; $passes = []; $organise = [];
        foreach ($stagiaires as $s) {
            $promo = $s['groupe_id'] . '|' . $s['annee_scolaire'];
            $effectif[$promo] = ($effectif[$promo] ?? 0) + 1;
            foreach ($notes[(int)$s['id']] ?? [] as $k => $_) $passes[$promo][$k] = ($passes[$promo][$k] ?? 0) + 1;
        }
        foreach ($passes as $promo => $parCC) {
            foreach ($parCC as $k => $n) {
                if ($n / $effectif[$promo] >= SEUIL_CC_ORGANISE) $organise[$promo][$k] = true;
            }
        }

        $lignesBrutes = []; $slotsPromo = [];
        foreach ($stagiaires as $s) {
            $sid   = (int)$s['id'];
            $promo = $s['groupe_id'] . '|' . $s['annee_scolaire'];
            if (empty($organise[$promo]) && empty($notes[$sid])) continue; // rien à afficher
            $vals = []; $abs = [];
            foreach ($slots as $k => $_) {
                if (isset($notes[$sid][$k]))      $vals[$k] = $notes[$sid][$k];
                elseif (isset($organise[$promo][$k])) { $vals[$k] = 0.0; $abs[$k] = true; }
                else                              $vals[$k] = null;
                if ($vals[$k] !== null) $slotsPromo[$promo][$k] = true;
            }
            $lignesBrutes[] = [$s, $promo, $vals, $abs];
        }

        // Regroupement des contrôles de chaque promo en 3 parties consécutives → CC1, CC2, CC3
        $partiesPromo = [];
        foreach ($slotsPromo as $promo => $ks) {
            $ks = array_values(array_filter(array_keys($slots), fn($k) => isset($ks[$k])));
            $partiesPromo[$promo] = decouperEn3($ks);
        }

        foreach ($lignesBrutes as [$s, $promo, $vals, $abs]) {
            $parts = [];
            foreach ($partiesPromo[$promo] ?? [] as $i => $ks) {
                $v = array_filter(array_map(fn($k) => $vals[$k], $ks), fn($x) => $x !== null);
                $parts[$i] = $v ? round(array_sum($v) / count($v), 2) : null;
            }
            $parts   += array_fill(0, 3, null);
            ksort($parts);
            $comptes = array_filter($parts, fn($v) => $v !== null);
            $lignes[] = [
                'groupe_id' => (int)$s['groupe_id'],
                'promo'     => $promo,
                'nom'       => $s['nom'],
                'prenom'    => $s['prenom'],
                'notes'     => $vals,
                'absents'   => $abs,
                'parties'   => $parts,
                'moyenne'   => $comptes ? round(array_sum($comptes) / count($comptes), 2) : null,
            ];
        }
    }
    return ['slots' => $slots, 'lignes' => $lignes, 'parties' => $partiesPromo ?? []];
}

/** Découpe une liste ordonnée en 3 parties consécutives (le reste va aux premières) : 4 → 2+1+1, 5 → 2+2+1. */
function decouperEn3(array $ks): array {
    $n = count($ks); $res = []; $pos = 0;
    for ($i = 0; $i < 3 && $pos < $n; $i++) {
        $taille = intdiv($n, 3) + ($i < $n % 3 ? 1 : 0);
        if ($taille === 0) break;
        $res[$i] = array_slice($ks, $pos, $taille);
        $pos += $taille;
    }
    return $res;
}

$sheets = [];
$synthese = [];        // groupe_id => module_id => ['moy' => x, 'n' => nb stagiaires]
$modulesSynthese = []; // module_id => module (ordre des colonnes)
foreach ($modules as $m) {
    $res = moyennesCCModule($pdo, (int)$m['id'], $groupeId);
    if (!$res['slots'] && $moduleId === 0) continue; // module sans CC noté : pas de feuille

    $slots  = $res['slots'];
    // Colonnes : détail de chaque contrôle, puis CC1/CC2/CC3 regroupés (R0..R2)
    $colsCC = $slots + ['R0' => 'CC1 (regroupé)', 'R1' => 'CC2 (regroupé)', 'R2' => 'CC3 (regroupé)'];
    $nbCols = 4 + count($colsCC) + 2;
    $rows   = []; $styles = [];

    $rows[] = ['Moyenne des contrôles continus — ' . moduleLibelle($m)];                       $styles[0] = 1;
    $rows[] = ['Année de formation ' . getAnneeFormation(false) . ' — généré le ' . date('d/m/Y à H:i')
              . ($groupeId > 0 ? ' — groupe ' . ($groupesNoms[$groupeId] ?? $groupeId) : '')]; $styles[1] = 6;
    $rows[] = [];
    $rows[] = array_merge(['N°', 'Groupe', 'Nom', 'Prénom'], array_values($colsCC), ['Absences', 'Moyenne CC /20']);
    $styles[3] = 2;

    if (!$res['lignes']) {
        $rows[] = ['Aucune note de contrôle continu pour ce module.'];
        $styles[4] = 6;
    }
    // Ligne de moyenne (groupe ou générale) : moyenne par CC + moyenne des « Moyenne CC »
    $ligneMoyenne = function (string $libelle, array $sommes, array $moys) {
        $row = [['v' => $libelle, 's' => 7], ['v' => '', 's' => 7], ['v' => '', 's' => 7], ['v' => '', 's' => 7]];
        foreach ($sommes as $k => $vals) {
            $v = $vals ? round(array_sum($vals) / count($vals), 2) : null;
            $row[] = ['v' => $v, 's' => ($k[0] === 'R' && $v !== null) ? ($v >= 10 ? 4 : 5) : 3];
        }
        $row[] = ['v' => count($moys) . ' stag.', 's' => 7];
        $gm = $moys ? round(array_sum($moys) / count($moys), 2) : null;
        $row[] = ['v' => $gm, 's' => $gm === null ? 3 : ($gm >= 10 ? 4 : 5)];
        return [$row, $gm];
    };

    $sommes = array_fill_keys(array_keys($colsCC), []);
    $moys   = [];
    $nbGroupes = count(array_unique(array_column($res['lignes'], 'groupe_id')));
    $gSommes = $sommes; $gMoys = []; $gCourant = null;
    $fermerGroupe = function () use (&$rows, &$gSommes, &$gMoys, &$gCourant, $sommes, $ligneMoyenne, $groupesNoms, $nbGroupes, $m, &$synthese) {
        if ($gCourant === null) return;
        [$row, $gm] = $ligneMoyenne('Moyenne du groupe ' . ($groupesNoms[$gCourant] ?? $gCourant), $gSommes, $gMoys);
        if ($nbGroupes > 1) $rows[] = $row;
        if ($gm !== null) $synthese[$gCourant][(int)$m['id']] = ['moy' => $gm, 'n' => count($gMoys)];
        $gSommes = $sommes; $gMoys = [];
    };
    foreach ($res['lignes'] as $i => $l) {
        if ($l['groupe_id'] !== $gCourant) { $fermerGroupe(); $gCourant = $l['groupe_id']; }
        $row = [$i + 1, $groupesNoms[$l['groupe_id']] ?? '', $l['nom'], $l['prenom']];
        $cells = $l['notes'] + ['R0' => $l['parties'][0], 'R1' => $l['parties'][1], 'R2' => $l['parties'][2]];
        foreach ($cells as $k => $v) {
            $st = $k[0] === 'R' ? ($v === null ? 3 : ($v >= 10 ? 4 : 5)) : (isset($l['absents'][$k]) ? 5 : 3);
            $row[] = ['v' => $v, 's' => $st];
            if ($v !== null) { $sommes[$k][] = $v; $gSommes[$k][] = $v; }
        }
        $row[] = ['v' => count($l['absents']) ?: '', 's' => 3];
        $row[] = ['v' => $l['moyenne'], 's' => $l['moyenne'] === null ? 3 : ($l['moyenne'] >= 10 ? 4 : 5)];
        if ($l['moyenne'] !== null) { $moys[] = $l['moyenne']; $gMoys[] = $l['moyenne']; }
        $rows[] = $row;
    }
    $fermerGroupe();
    if ($res['lignes']) {
        [$row] = $ligneMoyenne($nbGroupes > 1 ? 'Moyenne générale (tous groupes)' : 'Moyenne du groupe', $sommes, $moys);
        $rows[] = $row;
    }
    $modulesSynthese[(int)$m['id']] = $m;

    // Légende du regroupement, par groupe
    if ($res['parties']) {
        $rows[] = [];
        $rows[] = ["Regroupement des contrôles (dans l'ordre, en 3 parties) : note de la partie = moyenne de ses contrôles ; Moyenne CC = moyenne de CC1, CC2, CC3"];
        $styles[count($rows) - 1] = 6;
        $vus = [];
        foreach ($res['lignes'] as $l) {
            if (isset($vus[$l['promo']]) || empty($res['parties'][$l['promo']])) continue;
            $vus[$l['promo']] = true;
            $txt = [];
            foreach ($res['parties'][$l['promo']] as $i => $ks) {
                $txt[] = 'CC' . ($i + 1) . ' = ' . implode(' + ', array_map(fn($k) => $slots[$k], $ks));
            }
            $annee  = explode('|', $l['promo'], 2)[1] ?? '';
            $rows[] = [($groupesNoms[$l['groupe_id']] ?? '') . ($annee !== '' ? " ($annee)" : '') . ' : ' . implode('   ·   ', $txt)];
            $styles[count($rows) - 1] = 6;
        }
    }

    $lastCol = _colLetter($nbCols - 1);
    $widths  = [0 => 5, 1 => 14, 2 => 22, 3 => 22];
    for ($c = 4; $c < 4 + count($colsCC); $c++) $widths[$c] = 14;
    $widths[4 + count($colsCC)] = 10;
    $widths[5 + count($colsCC)] = 16;

    $sheets[] = [
        'name'       => trim(($m['code'] ?? '') ?: $m['nom']),
        'rows'       => $rows,
        'styles'     => $styles,
        'widths'     => $widths,
        'merges'     => ["A1:{$lastCol}1", "A2:{$lastCol}2"],
        'freeze_row' => 4,
    ];
}

// ── Feuille de synthèse : moyenne CC par groupe (lignes) × module (colonnes) ──
$modulesSynthese = array_filter($modulesSynthese, function ($m) use ($synthese) {
    foreach ($synthese as $parModule) if (isset($parModule[(int)$m['id']])) return true;
    return false;
});
if ($synthese && $modulesSynthese) {
    uksort($synthese, fn($a, $b) => strnatcasecmp($groupesNoms[$a] ?? (string)$a, $groupesNoms[$b] ?? (string)$b));
    $nbCols = 1 + count($modulesSynthese) + 1;
    $rows = []; $styles = [];
    $rows[] = ['Moyenne des contrôles continus par module et par groupe']; $styles[0] = 1;
    $rows[] = ['Année de formation ' . getAnneeFormation(false) . ' — généré le ' . date('d/m/Y à H:i')
              . ' — moyenne des « Moyenne CC /20 » des stagiaires du groupe']; $styles[1] = 6;
    $rows[] = [];
    $entete = ['Groupe'];
    foreach ($modulesSynthese as $m) $entete[] = moduleLibelle($m);
    $entete[] = 'Moyenne générale';
    $rows[] = $entete; $styles[3] = 2;

    $colVals = [];
    foreach ($synthese as $gid => $parModule) {
        $row = [['v' => $groupesNoms[$gid] ?? (string)$gid, 's' => 7]];
        $vals = [];
        foreach ($modulesSynthese as $mid => $_) {
            $v = $parModule[$mid]['moy'] ?? null;
            $row[] = ['v' => $v, 's' => $v === null ? 3 : ($v >= 10 ? 4 : 5)];
            if ($v !== null) { $vals[] = $v; $colVals[$mid][] = $v; }
        }
        $g = $vals ? round(array_sum($vals) / count($vals), 2) : null;
        $row[] = ['v' => $g, 's' => $g === null ? 3 : ($g >= 10 ? 4 : 5)];
        $rows[] = $row;
    }
    if (count($synthese) > 1) {
        $row = [['v' => 'Moyenne des groupes', 's' => 7]];
        foreach ($modulesSynthese as $mid => $_) {
            $v = !empty($colVals[$mid]) ? round(array_sum($colVals[$mid]) / count($colVals[$mid]), 2) : null;
            $row[] = ['v' => $v, 's' => 3];
        }
        $row[] = ['v' => '', 's' => 3];
        $rows[] = $row;
    }
    $widths = [0 => 18];
    for ($c = 1; $c < $nbCols; $c++) $widths[$c] = 22;
    $lastCol = _colLetter($nbCols - 1);
    array_unshift($sheets, [
        'name'       => 'Synthèse par groupe',
        'rows'       => $rows,
        'styles'     => $styles,
        'widths'     => $widths,
        'merges'     => ["A1:{$lastCol}1", "A2:{$lastCol}2"],
        'freeze_row' => 4,
    ]);
}

if (!$sheets) {
    $sheets[] = ['name' => 'Moyennes CC', 'rows' => [['Aucune note de contrôle continu enregistrée.']], 'styles' => [0 => 6]];
}

$suffix = $moduleId > 0
    ? preg_replace('/[^A-Za-z0-9]+/', '_', implode('-', array_filter([reset($modules)['filiere'] ?? '', reset($modules)['code'] ?? ''])) ?: 'module' . $moduleId)
    : 'tous_modules';
if ($groupeId > 0) $suffix .= '_' . preg_replace('/[^A-Za-z0-9]+/', '_', $groupesNoms[$groupeId] ?? (string)$groupeId);
$fname = 'moyennes_CC_' . $suffix . '_' . date('Y-m-d') . '.xlsx';

$bin = buildXlsxMulti($sheets);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Content-Length: ' . strlen($bin));
header('Cache-Control: no-cache, must-revalidate');
echo $bin;
