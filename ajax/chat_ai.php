<?php
/**
 * Endpoint AJAX pour le chatbot IA - Acadenique
 * Supporte Groq (en ligne, gratuit) et OpenAI (payant)
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db_config.php';
require_once __DIR__ . '/../includes/ai_config.php';

// Vérifier l'authentification
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Non authentifié. Veuillez vous connecter.']);
    exit;
}

// Accepter uniquement POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Méthode non autorisée']);
    exit;
}

// Lire le corps de la requête
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Requête invalide']);
    exit;
}

// Vérifier le token CSRF
$csrfToken = $input['csrf_token'] ?? '';
if (empty($csrfToken) || $csrfToken !== ($_SESSION['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error' => 'Token de sécurité invalide. Rechargez la page.']);
    exit;
}

// Valider le message
$message = trim($input['message'] ?? '');
if (empty($message)) {
    http_response_code(400);
    echo json_encode(['error' => 'Le message ne peut pas être vide']);
    exit;
}

if (mb_strlen($message) > AI_MAX_INPUT_LENGTH) {
    http_response_code(400);
    echo json_encode(['error' => 'Message trop long (max ' . AI_MAX_INPUT_LENGTH . ' caractères)']);
    exit;
}

// Action spéciale : réinitialiser l'historique
if (($input['action'] ?? '') === 'clear') {
    $_SESSION['ai_chat_history'] = [];
    echo json_encode(['response' => 'Conversation réinitialisée.']);
    exit;
}

// Récupérer le contexte utilisateur
$userContext = getUserContext($pdo);

// Chercher des données pertinentes dans la BDD selon la question
$dbContext = getRelevantData($pdo, $message);

// Gérer l'historique de conversation en session
if (!isset($_SESSION['ai_chat_history'])) {
    $_SESSION['ai_chat_history'] = [];
}

// Ajouter le message utilisateur à l'historique
$_SESSION['ai_chat_history'][] = ['role' => 'user', 'content' => $message];

// Limiter l'historique
if (count($_SESSION['ai_chat_history']) > AI_MAX_HISTORY) {
    $_SESSION['ai_chat_history'] = array_slice($_SESSION['ai_chat_history'], -AI_MAX_HISTORY);
}

// Construire les messages pour l'API
$systemPrompt = AI_SYSTEM_PROMPT;
if ($userContext) {
    $systemPrompt .= "\n\n=== CONTEXTE UTILISATEUR ACTUEL ===\n" . $userContext;
}
if ($dbContext) {
    $systemPrompt .= "\n\n=== DONNEES REELLES DE LA BASE (résultats de requêtes) ===\n" . $dbContext;
    $systemPrompt .= "\n\nIMPORTANT : Les données ci-dessus sont extraites directement de la base de données. Utilise-les pour répondre avec précision. Ne modifie pas les chiffres.";
}

$messages = [
    ['role' => 'system', 'content' => $systemPrompt]
];
$messages = array_merge($messages, $_SESSION['ai_chat_history']);

// Appeler le fournisseur IA
try {
    if (AI_PROVIDER === 'groq') {
        $response = callGroq($messages);
    } elseif (AI_PROVIDER === 'openai') {
        $response = callOpenAI($messages);
    } else {
        throw new Exception('Fournisseur IA non configuré');
    }

    if ($response !== null) {
        // Ajouter la réponse à l'historique
        $_SESSION['ai_chat_history'][] = ['role' => 'assistant', 'content' => $response];
        echo json_encode(['response' => $response]);
    } else {
        throw new Exception('Pas de réponse du modèle IA');
    }
} catch (Exception $e) {
    error_log('Erreur IA Acadenique: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode([
        'error' => getErrorMessage($e->getMessage())
    ]);
}

// ============================
// Fonctions
// ============================

/**
 * Extraire des données pertinentes de la BDD selon la question de l'utilisateur
 * Requêtes en lecture seule (SELECT), jamais de modification
 */
function getRelevantData(PDO $pdo, string $message): string
{
    $msg = mb_strtolower($message);
    $results = [];
    $anneeId = $_SESSION['annee_academique'] ?? $pdo->query("SELECT valeur FROM t_configuration WHERE cle='annee_encours'")->fetchColumn() ?: 1;

    try {
        // Détection d'un matricule dans la question
        if (preg_match('/\b([A-Z0-9]{5,25})\b/i', $message, $m)) {
            $mat = strtoupper($m[1]);
            $stmt = $pdo->prepare("
                SELECT e.matricule, e.nom_etu, e.postnom_etu, e.prenom_etu, e.sexe, e.date_naiss, e.lieu_naiss,
                       e.nationalite, e.telephone, e.email
                FROM t_etudiant e WHERE e.matricule = ?
            ");
            $stmt->execute([$mat]);
            $etu = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($etu) {
                $results[] = "Étudiant trouvé: " . json_encode($etu, JSON_UNESCAPED_UNICODE);

                // Ses inscriptions
                $stmt2 = $pdo->prepare("
                    SELECT m.libelle as mention, p.nom_promotion as promotion,
                           CONCAT(YEAR(a.date_debut),'-',YEAR(a.date_fin)) as annee, i.statut
                    FROM t_inscription i
                    JOIN t_mention m ON m.id_mention = i.id_mention
                    JOIN t_promotion p ON p.code_promotion = i.code_promotion
                    JOIN t_anne_academique a ON a.id_annee = i.id_annee
                    WHERE i.matricule = ?
                    ORDER BY a.date_debut DESC
                ");
                $stmt2->execute([$mat]);
                $inscriptions = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                if ($inscriptions) {
                    $results[] = "Inscriptions: " . json_encode($inscriptions, JSON_UNESCAPED_UNICODE);
                }

                // Ses notes (résumé par UE)
                $stmt3 = $pdo->prepare("
                    SELECT ue.code_ue, ue.libelle as ue_libelle, ue.credits,
                           ec.code_ec, ec.libelle as ec_libelle, ec.coefficient,
                           c.cote_s1, c.cote_s2, c.cote_rattrapage_s1, c.cote_rattrapage_s2
                    FROM t_cote c
                    JOIN t_element_constitutif ec ON ec.id_ec = c.id_ec
                    JOIN t_unite_enseignement ue ON ue.id_ue = c.id_ue
                    WHERE c.matricule = ? AND c.id_annee = ?
                    ORDER BY ue.code_ue, ec.code_ec
                    LIMIT 50
                ");
                $stmt3->execute([$mat, $anneeId]);
                $notes = $stmt3->fetchAll(PDO::FETCH_ASSOC);
                if ($notes) {
                    $results[] = "Notes (année en cours): " . json_encode($notes, JSON_UNESCAPED_UNICODE);
                }
            }
        }

        // Questions sur les étudiants / inscriptions / effectifs
        if (preg_match('/(?:combien|nombre|effectif|total|liste|étudiant|inscri|inscrip)/u', $msg)) {
            // Par mention et promotion
            $stmt = $pdo->prepare("
                SELECT m.code_mention, m.libelle as mention, i.code_promotion,
                       COUNT(*) as effectif
                FROM t_inscription i
                JOIN t_mention m ON m.id_mention = i.id_mention
                WHERE i.id_annee = ?
                GROUP BY i.id_mention, i.code_promotion
                ORDER BY m.libelle, i.code_promotion
            ");
            $stmt->execute([$anneeId]);
            $effectifs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($effectifs) {
                $results[] = "Effectifs par mention/promotion (année en cours): " . json_encode($effectifs, JSON_UNESCAPED_UNICODE);
            }
        }

        // Questions sur les notes / moyennes / résultats / délibération
        if (preg_match('/(?:note|cote|moyenne|résultat|délibér|palmares|réussi|échoué|ajourné|admis)/u', $msg)) {
            // Chercher une mention/promotion spécifique dans la question
            $mentionId = null;
            $promoCode = null;

            // Détecter la promotion
            if (preg_match('/\b(L1|L2|L3|M1|M2)\b/i', $msg, $pm)) {
                $promoCode = strtoupper($pm[1]);
            }

            // Détecter la mention par nom
            $mentions = $pdo->query("SELECT id_mention, code_mention, libelle FROM t_mention")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($mentions as $ment) {
                if (stripos($msg, strtolower($ment['libelle'])) !== false || stripos($msg, strtolower($ment['code_mention'])) !== false) {
                    $mentionId = $ment['id_mention'];
                    break;
                }
            }

            if ($mentionId || $promoCode) {
                $where = ["c.id_annee = ?"];
                $params = [$anneeId];
                if ($mentionId) {
                    $where[] = "c.id_mention = ?";
                    $params[] = $mentionId;
                }
                if ($promoCode) {
                    $where[] = "c.id_promotion = ?";
                    $params[] = $promoCode;
                }
                $whereStr = implode(' AND ', $where);

                $stmt = $pdo->prepare("
                    SELECT COUNT(DISTINCT c.matricule) as nb_etudiants,
                           ROUND(AVG(c.cote_s1), 2) as moy_s1,
                           ROUND(AVG(c.cote_s2), 2) as moy_s2,
                           COUNT(CASE WHEN c.cote_s1 IS NOT NULL THEN 1 END) as nb_cotes_s1,
                           COUNT(CASE WHEN c.cote_s2 IS NOT NULL THEN 1 END) as nb_cotes_s2
                    FROM t_cote c
                    WHERE $whereStr
                ");
                $stmt->execute($params);
                $noteStats = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($noteStats) {
                    $results[] = "Stats notes: " . json_encode($noteStats, JSON_UNESCAPED_UNICODE);
                }
            }
        }

        // Questions sur les UEs / ECs / matières / cours / programme
        if (preg_match('/(?:ue |ec |matière|cours|programme|unité|crédit|coefficient|module)/u', $msg)) {
            $mentionId = null;
            $mentions = $pdo->query("SELECT id_mention, code_mention, libelle FROM t_mention")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($mentions as $ment) {
                if (stripos($msg, strtolower($ment['libelle'])) !== false || stripos($msg, strtolower($ment['code_mention'])) !== false) {
                    $mentionId = $ment['id_mention'];
                    break;
                }
            }
            if ($mentionId) {
                $stmt = $pdo->prepare("
                    SELECT ue.code_ue, ue.libelle as ue_libelle, mu.credits, mu.semestre,
                           ec.code_ec, ec.libelle as ec_libelle, ec.coefficient
                    FROM t_mention_ue mu
                    JOIN t_unite_enseignement ue ON ue.id_ue = mu.id_ue
                    LEFT JOIN t_mention_ue_ec mue ON mue.id_mention_ue = mu.id_mention_ue
                    LEFT JOIN t_element_constitutif ec ON ec.id_ec = mue.id_ec
                    WHERE mu.id_mention = ?
                    ORDER BY mu.semestre, ue.code_ue, ec.code_ec
                    LIMIT 80
                ");
                $stmt->execute([$mentionId]);
                $programme = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if ($programme) {
                    $results[] = "Programme de la mention: " . json_encode($programme, JSON_UNESCAPED_UNICODE);
                }
            }
        }

        // Questions sur les domaines / filières
        if (preg_match('/(?:domaine|filière|mention|département|faculté)/u', $msg)) {
            $stmt = $pdo->query("
                SELECT d.nom_domaine, f.nomFiliere as filiere, m.libelle as mention, m.code_mention
                FROM t_domaine d
                JOIN t_filiere f ON f.id_domaine = d.id_domaine
                JOIN t_mention m ON m.idFiliere = f.idFiliere
                ORDER BY d.nom_domaine, f.nomFiliere, m.libelle
            ");
            $structure = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($structure) {
                $results[] = "Structure académique: " . json_encode($structure, JSON_UNESCAPED_UNICODE);
            }
        }

        // Questions sur le jury
        if (preg_match('/(?:jury|président|secrétaire|membre|nomination)/u', $msg)) {
            $stmt = $pdo->prepare("
                SELECT jn.nom_complet, jn.titre_academique, jn.fonction, jn.role_jury,
                       d.nom_domaine
                FROM t_jury_nomination jn
                JOIN t_domaine d ON d.id_domaine = jn.id_domaine
                WHERE jn.id_annee = ?
                ORDER BY jn.ordre_affichage
            ");
            $stmt->execute([$anneeId]);
            $jury = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($jury) {
                $results[] = "Jury de l'année: " . json_encode($jury, JSON_UNESCAPED_UNICODE);
            }
        }

        // Questions sur la configuration / paramètres
        if (preg_match('/(?:config|paramètr|réglage|rattrapage.*max|note.*max)/u', $msg)) {
            $configs = $pdo->query("SELECT cle, valeur FROM t_configuration")->fetchAll(PDO::FETCH_ASSOC);
            if ($configs) {
                $results[] = "Configuration système: " . json_encode($configs, JSON_UNESCAPED_UNICODE);
            }
        }

    } catch (PDOException $e) {
        error_log('Erreur consultation BDD IA: ' . $e->getMessage());
    }

    return implode("\n\n", $results);
}

/**
 * Récupérer le contexte de l'utilisateur connecté
 */
function getUserContext(PDO $pdo): string
{
    try {
        $stmt = $pdo->prepare("SELECT nom_complet, role FROM t_utilisateur WHERE username = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();

        if (!$user) {
            return '';
        }

        $parts = [];
        $parts[] = "Utilisateur: {$user['nom_complet']} ({$user['role']})";
        $parts[] = "Page actuelle: " . ($_SESSION['current_page'] ?? 'non définie');

        // Année académique en cours
        $anneeId = $_SESSION['annee_academique'] ?? null;
        if (!$anneeId) {
            $anneeId = $pdo->query("SELECT valeur FROM t_configuration WHERE cle='annee_encours'")->fetchColumn();
        }
        if ($anneeId) {
            $a = $pdo->prepare("SELECT date_debut, date_fin FROM t_anne_academique WHERE id_annee = ?");
            $a->execute([$anneeId]);
            $annee = $a->fetch();
            if ($annee) {
                $parts[] = "Année académique active: " . substr($annee['date_debut'], 0, 4) . '-' . substr($annee['date_fin'], 0, 4) . " (id=$anneeId)";
            }
        }

        // Statistiques globales
        $stats = [];
        $stats['etudiants'] = $pdo->query("SELECT COUNT(*) FROM t_etudiant")->fetchColumn();
        $stats['inscriptions'] = $pdo->query("SELECT COUNT(*) FROM t_inscription" . ($anneeId ? " WHERE id_annee=$anneeId" : ""))->fetchColumn();
        $stats['ues'] = $pdo->query("SELECT COUNT(*) FROM t_unite_enseignement")->fetchColumn();
        $stats['ecs'] = $pdo->query("SELECT COUNT(*) FROM t_element_constitutif")->fetchColumn();
        $stats['cotes'] = $pdo->query("SELECT COUNT(*) FROM t_cote" . ($anneeId ? " WHERE id_annee=$anneeId" : ""))->fetchColumn();
        $parts[] = "Stats: {$stats['etudiants']} étudiants, {$stats['inscriptions']} inscriptions, {$stats['ues']} UEs, {$stats['ecs']} ECs, {$stats['cotes']} cotes saisies";

        // Inscriptions par promotion (année en cours)
        if ($anneeId) {
            $promoStats = $pdo->prepare("
                SELECT p.nom_promotion, COUNT(i.id_inscription) as nb
                FROM t_inscription i
                JOIN t_promotion p ON p.code_promotion = i.code_promotion
                WHERE i.id_annee = ?
                GROUP BY i.code_promotion
                ORDER BY p.code_promotion
            ");
            $promoStats->execute([$anneeId]);
            $promos = [];
            foreach ($promoStats->fetchAll() as $ps) {
                $promos[] = "{$ps['nom_promotion']}: {$ps['nb']}";
            }
            if ($promos) {
                $parts[] = "Répartition inscriptions: " . implode(', ', $promos);
            }
        }

        // Domaines actifs
        $domaines = $pdo->query("
            SELECT d.nom_domaine, COUNT(DISTINCT f.idFiliere) as nb_fil, COUNT(DISTINCT m.id_mention) as nb_ment
            FROM t_domaine d
            LEFT JOIN t_filiere f ON f.id_domaine = d.id_domaine
            LEFT JOIN t_mention m ON m.idFiliere = f.idFiliere
            GROUP BY d.id_domaine
        ")->fetchAll();
        $domParts = [];
        foreach ($domaines as $d) {
            $domParts[] = "{$d['nom_domaine']} ({$d['nb_fil']} filières, {$d['nb_ment']} mentions)";
        }
        if ($domParts) {
            $parts[] = "Domaines: " . implode(' | ', $domParts);
        }

        return implode("\n", $parts);
    } catch (PDOException $e) {
        error_log('Erreur contexte utilisateur IA: ' . $e->getMessage());
    }
    return '';
}

/**
 * Appeler Groq (en ligne, gratuit, rapide)
 * API compatible OpenAI - modèles open-source hébergés
 */
function callGroq(array $messages): ?string
{
    if (empty(GROQ_API_KEY)) {
        throw new Exception('GROQ_KEY_MISSING');
    }

    $payload = json_encode([
        'model'       => GROQ_MODEL,
        'messages'    => $messages,
        'max_tokens'  => AI_MAX_TOKENS,
        'temperature' => AI_TEMPERATURE,
    ]);

    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    $curlOpts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . GROQ_API_KEY,
        ],
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ];

    // Certificat CA pour WAMP (SSL)
    $caPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'cacert.pem';
    if (!$caPath || !file_exists($caPath)) {
        $caPath = 'C:\\wamp64\\bin\\php\\cacert.pem';
    }
    if (file_exists($caPath)) {
        $curlOpts[CURLOPT_CAINFO] = $caPath;
    }

    curl_setopt_array($ch, $curlOpts);

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new Exception('GROQ_CONNECTION: ' . $curlError);
    }

    if ($httpCode !== 200) {
        $errorData = json_decode($result, true);
        $errorMsg = $errorData['error']['message'] ?? substr($result, 0, 200);
        throw new Exception('GROQ_HTTP_' . $httpCode . ': ' . $errorMsg);
    }

    $data = json_decode($result, true);
    return $data['choices'][0]['message']['content'] ?? null;
}

/**
 * Appeler OpenAI (payant, optionnel)
 */
function callOpenAI(array $messages): ?string
{
    if (empty(OPENAI_API_KEY)) {
        throw new Exception('OPENAI_KEY_MISSING');
    }

    $payload = json_encode([
        'model'       => OPENAI_MODEL,
        'messages'    => $messages,
        'max_tokens'  => AI_MAX_TOKENS,
        'temperature' => AI_TEMPERATURE,
    ]);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OPENAI_API_KEY,
        ],
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new Exception('OPENAI_CONNECTION: ' . $curlError);
    }

    if ($httpCode !== 200) {
        throw new Exception('OPENAI_HTTP_' . $httpCode);
    }

    $data = json_decode($result, true);
    return $data['choices'][0]['message']['content'] ?? null;
}

/**
 * Traduire les erreurs techniques en messages utilisateur
 */
function getErrorMessage(string $error): string
{
    if (strpos($error, 'GROQ_KEY_MISSING') !== false) {
        return "⚠️ Clé API Groq non configurée.\n\n1. Allez sur https://console.groq.com\n2. Créez un compte gratuit\n3. Copiez votre clé API\n4. Collez-la dans includes/ai_config.php (GROQ_API_KEY)";
    }
    if (strpos($error, 'GROQ_CONNECTION') !== false) {
        return "⚠️ Impossible de contacter le serveur Groq. Vérifiez votre connexion internet.";
    }
    if (strpos($error, 'GROQ_HTTP_401') !== false) {
        return "⚠️ Clé API Groq invalide. Vérifiez votre clé sur https://console.groq.com";
    }
    if (strpos($error, 'GROQ_HTTP_429') !== false) {
        return "⚠️ Limite de requêtes Groq atteinte. Attendez quelques secondes puis réessayez.";
    }
    if (strpos($error, 'GROQ_HTTP_') !== false) {
        return "⚠️ Erreur du service Groq. Réessayez dans quelques instants.";
    }
    if (strpos($error, 'OPENAI_KEY_MISSING') !== false) {
        return "⚠️ Clé API OpenAI non configurée dans includes/ai_config.php";
    }
    if (strpos($error, 'OPENAI_HTTP_401') !== false) {
        return "⚠️ Clé API OpenAI invalide.";
    }
    return "⚠️ Service IA temporairement indisponible. Réessayez dans quelques instants.";
}
