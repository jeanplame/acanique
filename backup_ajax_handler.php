<?php
/**
 * Gestionnaire AJAX pour les opérations de sauvegarde
 * Traite les requêtes de création, suppression et téléchargement
 */

session_start();

// Vérifier l'authentification
if (!isset($_SESSION['user_id']) || !isset($_SESSION['nom_complet'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Accès non autorisé']);
    exit();
}

// Inclure le système de sauvegarde
require_once 'backup_system_v2.php';

// Fonction pour envoyer une réponse JSON
function sendJsonResponse($success, $message, $data = []) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $data));
    exit();
}

// Fonction pour envoyer un fichier en téléchargement
function sendFileDownload($filePath, $filename) {
    if (!file_exists($filePath)) {
        http_response_code(404);
        echo "Fichier non trouvé";
        exit();
    }
    
    // Déterminer le type MIME
    $mimeType = 'application/octet-stream';
    if (pathinfo($filename, PATHINFO_EXTENSION) === 'gz') {
        $mimeType = 'application/gzip';
    } elseif (pathinfo($filename, PATHINFO_EXTENSION) === 'sql') {
        $mimeType = 'application/sql';
    }
    
    // Headers pour le téléchargement
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    
    // Envoyer le fichier
    readfile($filePath);
    exit();
}

// Récupérer l'action
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Initialiser le système de sauvegarde
try {
    $backupSystem = new BackupSystemOptimized();
} catch (Exception $e) {
    sendJsonResponse(false, 'Erreur de connexion au système de sauvegarde: ' . $e->getMessage());
}

// Traiter l'action
switch ($action) {
    case 'create':
        try {
            // Récupérer le nom personnalisé s'il existe
            $customName = !empty($_POST['backup_name']) ? trim($_POST['backup_name']) : null;
            
            // Valider le nom personnalisé
            if ($customName) {
                // Supprimer les caractères non autorisés
                $customName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $customName);
                
                // Limiter la longueur
                if (strlen($customName) > 50) {
                    $customName = substr($customName, 0, 50);
                }
                
                // Ajouter un timestamp pour éviter les doublons
                $customName = $customName . '_' . date('Y-m-d_H-i-s');
            }
            
            // Créer la sauvegarde
            $result = $backupSystem->createFullBackup($customName);
            
            if ($result['success']) {
                sendJsonResponse(true, 'Sauvegarde créée avec succès', [
                    'filename' => $result['filename'],
                    'size' => $result['size'],
                    'compressed_size' => $result['compressed_size'] ?? null,
                    'tables' => $result['tables'],
                    'views' => $result['views'],
                    'total_rows' => $result['total_rows']
                ]);
            } else {
                sendJsonResponse(false, $result['message']);
            }
            
        } catch (Exception $e) {
            error_log("Erreur création sauvegarde: " . $e->getMessage());
            sendJsonResponse(false, 'Erreur lors de la création: ' . $e->getMessage());
        }
        break;
        
    case 'delete':
        try {
            $filename = $_POST['filename'] ?? '';
            
            if (empty($filename)) {
                sendJsonResponse(false, 'Nom de fichier manquant');
            }
            
            // Sécurité: vérifier que le nom de fichier ne contient pas de caractères dangereux
            if (strpos($filename, '..') !== false || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
                sendJsonResponse(false, 'Nom de fichier invalide');
            }
            
            // Construire le chemin complet
            $backupDir = __DIR__ . '/backups/';
            $filePath = $backupDir . $filename;
            
            // Vérifier que le fichier existe et est dans le bon répertoire
            if (!file_exists($filePath) || !is_file($filePath)) {
                sendJsonResponse(false, 'Fichier non trouvé');
            }
            
            // Vérifier que le fichier est bien dans le répertoire de sauvegarde
            $realPath = realpath($filePath);
            $realBackupDir = realpath($backupDir);
            
            if (strpos($realPath, $realBackupDir) !== 0) {
                sendJsonResponse(false, 'Accès refusé au fichier');
            }
            
            // Supprimer le fichier
            if (unlink($filePath)) {
                sendJsonResponse(true, 'Sauvegarde supprimée avec succès');
            } else {
                sendJsonResponse(false, 'Erreur lors de la suppression du fichier');
            }
            
        } catch (Exception $e) {
            error_log("Erreur suppression sauvegarde: " . $e->getMessage());
            sendJsonResponse(false, 'Erreur lors de la suppression: ' . $e->getMessage());
        }
        break;
        
    case 'download':
        try {
            $filename = $_GET['file'] ?? '';
            
            if (empty($filename)) {
                http_response_code(400);
                echo "Nom de fichier manquant";
                exit();
            }
            
            // Sécurité: même vérifications que pour la suppression
            if (strpos($filename, '..') !== false || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
                http_response_code(400);
                echo "Nom de fichier invalide";
                exit();
            }
            
            // Construire le chemin complet
            $backupDir = __DIR__ . '/backups/';
            $filePath = $backupDir . $filename;
            
            // Vérifier que le fichier existe
            if (!file_exists($filePath) || !is_file($filePath)) {
                http_response_code(404);
                echo "Fichier non trouvé";
                exit();
            }
            
            // Vérifier que le fichier est bien dans le répertoire de sauvegarde
            $realPath = realpath($filePath);
            $realBackupDir = realpath($backupDir);
            
            if (strpos($realPath, $realBackupDir) !== 0) {
                http_response_code(403);
                echo "Accès refusé au fichier";
                exit();
            }
            
            // Envoyer le fichier
            sendFileDownload($filePath, $filename);
            
        } catch (Exception $e) {
            error_log("Erreur téléchargement sauvegarde: " . $e->getMessage());
            http_response_code(500);
            echo "Erreur lors du téléchargement: " . $e->getMessage();
            exit();
        }
        break;
        
    case 'list':
        try {
            $backups = $backupSystem->listBackups();
            $stats = $backupSystem->getStats();
            
            sendJsonResponse(true, 'Liste récupérée avec succès', [
                'backups' => $backups,
                'stats' => $stats
            ]);
            
        } catch (Exception $e) {
            error_log("Erreur liste sauvegardes: " . $e->getMessage());
            sendJsonResponse(false, 'Erreur lors de la récupération de la liste: ' . $e->getMessage());
        }
        break;
        
    case 'stats':
        try {
            $stats = $backupSystem->getStats();
            
            // Informations système supplémentaires
            $systemInfo = [
                'php_version' => PHP_VERSION,
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
                'disk_free_space' => disk_free_space(__DIR__),
                'backup_dir_size' => 0
            ];
            
            // Calculer la taille du répertoire de sauvegarde
            $backupDir = __DIR__ . '/backups/';
            if (is_dir($backupDir)) {
                $iterator = new DirectoryIterator($backupDir);
                foreach ($iterator as $fileinfo) {
                    if ($fileinfo->isFile()) {
                        $systemInfo['backup_dir_size'] += $fileinfo->getSize();
                    }
                }
            }
            
            sendJsonResponse(true, 'Statistiques récupérées avec succès', [
                'stats' => $stats,
                'system' => $systemInfo
            ]);
            
        } catch (Exception $e) {
            error_log("Erreur statistiques: " . $e->getMessage());
            sendJsonResponse(false, 'Erreur lors de la récupération des statistiques: ' . $e->getMessage());
        }
        break;
        
    default:
        sendJsonResponse(false, 'Action non reconnue');
        break;
}
?>