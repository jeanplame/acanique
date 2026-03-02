<?php
/**
 * Tableau de Bord Avancé des Sauvegardes
 * Interface avec graphiques et statistiques détaillées
 */

// Vérifier l'authentification
if (!isset($_SESSION['user_id']) || !isset($_SESSION['nom_complet'])) {
    header("Location: ?page=login");
    exit();
}

// Inclure les gestionnaires
require_once 'backup_dashboard_manager.php';
require_once 'advanced_restore_system.php';

$dashboard = new BackupDashboardManager();
$restore = new AdvancedRestoreSystem();

// Récupérer les données pour les graphiques
$backupTrends = $dashboard->getBackupTrends(30);
$sizeDistribution = $dashboard->getBackupSizeDistribution();
$databaseGrowth = $dashboard->getDatabaseGrowthStats(30);
$backupTypes = $dashboard->getBackupTypeStats();
$topTables = $dashboard->getTopTablesBySize(10);
$systemPerf = $dashboard->getSystemPerformance();
?>

<div class="container-fluid mt-4">
    <!-- En-tête -->
    <div class="row mb-4">
        <div class="col">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="h3 mb-1">
                        <i class="bi bi-graph-up text-primary me-2"></i>
                        Tableau de Bord Avancé
                    </h2>
                    <p class="text-muted mb-0">Statistiques et analyses des sauvegardes Acadenique</p>
                </div>
                <div>
                    <button type="button" class="btn btn-outline-primary" onclick="refreshDashboard()">
                        <i class="bi bi-arrow-clockwise me-1"></i>
                        Actualiser
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Cartes de statistiques principales -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="bg-primary bg-opacity-10 rounded-circle p-3">
                                <i class="bi bi-archive-fill text-primary fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <div class="row">
                                <div class="col">
                                    <p class="text-muted mb-1 small">Total Sauvegardes</p>
                                    <h4 class="mb-0"><?= $systemPerf['total_backups'] ?></h4>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="bg-success bg-opacity-10 rounded-circle p-3">
                                <i class="bi bi-hdd-fill text-success fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <div class="row">
                                <div class="col">
                                    <p class="text-muted mb-1 small">Espace Utilisé</p>
                                    <h4 class="mb-0"><?= $systemPerf['total_size_mb'] ?> MB</h4>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="bg-info bg-opacity-10 rounded-circle p-3">
                                <i class="bi bi-speedometer2 text-info fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <div class="row">
                                <div class="col">
                                    <p class="text-muted mb-1 small">Taille Moyenne</p>
                                    <h4 class="mb-0"><?= $systemPerf['avg_backup_size_mb'] ?> MB</h4>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="bg-warning bg-opacity-10 rounded-circle p-3">
                                <i class="bi bi-pie-chart-fill text-warning fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <div class="row">
                                <div class="col">
                                    <p class="text-muted mb-1 small">Usage Disque</p>
                                    <h4 class="mb-0"><?= $systemPerf['disk_usage_percent'] ?>%</h4>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Graphiques -->
    <div class="row mb-4">
        <!-- Tendances des sauvegardes -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-graph-up me-2"></i>
                        Tendances des Sauvegardes (30 jours)
                    </h5>
                </div>
                <div class="card-body">
                    <canvas id="backupTrendsChart" height="300"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Répartition par taille -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-pie-chart me-2"></i>
                        Répartition par Taille
                    </h5>
                </div>
                <div class="card-body">
                    <canvas id="sizeDistributionChart" height="300"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <!-- Évolution de la base de données -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-database me-2"></i>
                        Évolution de la Base de Données
                    </h5>
                </div>
                <div class="card-body">
                    <canvas id="databaseGrowthChart" height="300"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Types de sauvegardes -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-diagram-3 me-2"></i>
                        Types de Sauvegardes
                    </h5>
                </div>
                <div class="card-body">
                    <canvas id="backupTypesChart" height="300"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Top des tables -->
    <div class="row mb-4">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-bar-chart me-2"></i>
                        Top 10 des Tables par Taille
                    </h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Table</th>
                                    <th class="text-end">Taille (MB)</th>
                                    <th class="text-end">Lignes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($topTables as $table): ?>
                                <tr>
                                    <td>
                                        <code class="text-primary"><?= htmlspecialchars($table['table_name']) ?></code>
                                    </td>
                                    <td class="text-end">
                                        <span class="badge bg-info"><?= $table['size_mb'] ?></span>
                                    </td>
                                    <td class="text-end">
                                        <?= number_format($table['estimated_rows']) ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Informations système -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-gear me-2"></i>
                        Informations Système
                    </h5>
                </div>
                <div class="card-body">
                    <ul class="list-unstyled mb-0">
                        <li class="d-flex justify-content-between py-2 border-bottom">
                            <span class="text-muted">Version PHP</span>
                            <code><?= $systemPerf['php_version'] ?></code>
                        </li>
                        <li class="d-flex justify-content-between py-2 border-bottom">
                            <span class="text-muted">Version MySQL</span>
                            <code><?= $systemPerf['mysql_version'] ?></code>
                        </li>
                        <li class="d-flex justify-content-between py-2 border-bottom">
                            <span class="text-muted">Limite Mémoire PHP</span>
                            <code><?= $systemPerf['php_memory_limit'] ?></code>
                        </li>
                        <li class="d-flex justify-content-between py-2 border-bottom">
                            <span class="text-muted">Utilisation Disque</span>
                            <div>
                                <span class="badge bg-<?= $systemPerf['disk_usage_percent'] > 80 ? 'danger' : ($systemPerf['disk_usage_percent'] > 60 ? 'warning' : 'success') ?>">
                                    <?= $systemPerf['disk_usage_percent'] ?>%
                                </span>
                            </div>
                        </li>
                        <li class="d-flex justify-content-between py-2">
                            <span class="text-muted">Dernière Mise à Jour</span>
                            <small class="text-muted"><?= date('d/m/Y H:i:s') ?></small>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Inclure Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
// Données pour les graphiques
const backupTrendsData = <?= json_encode($backupTrends) ?>;
const sizeDistributionData = <?= json_encode($sizeDistribution) ?>;
const databaseGrowthData = <?= json_encode($databaseGrowth) ?>;
const backupTypesData = <?= json_encode($backupTypes) ?>;

// Configuration des couleurs
const colors = {
    primary: '#0d6efd',
    success: '#198754',
    danger: '#dc3545',
    warning: '#ffc107',
    info: '#0dcaf0',
    purple: '#6f42c1',
    orange: '#fd7e14'
};

// Graphique des tendances des sauvegardes
const trendsCtx = document.getElementById('backupTrendsChart').getContext('2d');
new Chart(trendsCtx, {
    type: 'line',
    data: {
        labels: backupTrendsData.map(item => new Date(item.date).toLocaleDateString('fr-FR', {
            month: 'short',
            day: 'numeric'
        })),
        datasets: [{
            label: 'Sauvegardes Réussies',
            data: backupTrendsData.map(item => item.successful),
            borderColor: colors.success,
            backgroundColor: colors.success + '20',
            fill: true,
            tension: 0.4
        }, {
            label: 'Sauvegardes Échouées',
            data: backupTrendsData.map(item => item.failed),
            borderColor: colors.danger,
            backgroundColor: colors.danger + '20',
            fill: true,
            tension: 0.4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'top',
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    stepSize: 1
                }
            }
        }
    }
});

// Graphique de répartition des tailles
const sizeCtx = document.getElementById('sizeDistributionChart').getContext('2d');
new Chart(sizeCtx, {
    type: 'doughnut',
    data: {
        labels: ['< 1 MB', '1-10 MB', '10-100 MB', '> 100 MB'],
        datasets: [{
            data: [
                sizeDistributionData.less_than_1mb,
                sizeDistributionData['1mb_to_10mb'],
                sizeDistributionData['10mb_to_100mb'],
                sizeDistributionData.more_than_100mb
            ],
            backgroundColor: [
                colors.success,
                colors.info,
                colors.warning,
                colors.danger
            ],
            borderWidth: 2,
            borderColor: '#fff'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom'
            }
        }
    }
});

// Graphique de croissance de la base de données
const growthCtx = document.getElementById('databaseGrowthChart').getContext('2d');
new Chart(growthCtx, {
    type: 'line',
    data: {
        labels: databaseGrowthData.map(item => new Date(item.date).toLocaleDateString('fr-FR', {
            month: 'short',
            day: 'numeric'
        })),
        datasets: [{
            label: 'Taille DB (MB)',
            data: databaseGrowthData.map(item => item.size_mb),
            borderColor: colors.primary,
            backgroundColor: colors.primary + '20',
            fill: true,
            tension: 0.4,
            yAxisID: 'y'
        }, {
            label: 'Nombre de Lignes (milliers)',
            data: databaseGrowthData.map(item => Math.round(item.total_rows / 1000)),
            borderColor: colors.purple,
            backgroundColor: colors.purple + '20',
            fill: false,
            tension: 0.4,
            yAxisID: 'y1'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'top'
            }
        },
        scales: {
            y: {
                type: 'linear',
                display: true,
                position: 'left',
                title: {
                    display: true,
                    text: 'Taille (MB)'
                }
            },
            y1: {
                type: 'linear',
                display: true,
                position: 'right',
                title: {
                    display: true,
                    text: 'Lignes (milliers)'
                },
                grid: {
                    drawOnChartArea: false,
                }
            }
        }
    }
});

// Graphique des types de sauvegardes
const typesCtx = document.getElementById('backupTypesChart').getContext('2d');
new Chart(typesCtx, {
    type: 'pie',
    data: {
        labels: ['Manuelles', 'Programmées', 'Automatiques'],
        datasets: [{
            data: [
                backupTypesData.manual,
                backupTypesData.scheduled,
                backupTypesData.automatic
            ],
            backgroundColor: [
                colors.primary,
                colors.success,
                colors.info
            ],
            borderWidth: 2,
            borderColor: '#fff'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom'
            }
        }
    }
});

// Fonction pour actualiser le tableau de bord
function refreshDashboard() {
    location.reload();
}

// Auto-refresh toutes les 5 minutes
setInterval(refreshDashboard, 300000);
</script>

<style>
.card {
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.card:hover {
    transform: translateY(-2px);
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15) !important;
}

.table code {
    font-size: 0.85rem;
}

canvas {
    max-height: 300px !important;
}
</style>