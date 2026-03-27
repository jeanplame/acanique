<?php
/**
 * Page de nomination des membres du jury par domaine
 * Accessible uniquement aux administrateurs
 */

// Vérification du rôle administrateur
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'administrateur') {
    header('Location: ?page=dashboard');
    exit();
}

// Créer la table si elle n'existe pas
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS t_jury_nomination (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_domaine INT NOT NULL,
            id_annee INT NOT NULL,
            nom_complet VARCHAR(200) NOT NULL,
            titre_academique VARCHAR(50) DEFAULT NULL,
            fonction VARCHAR(150) DEFAULT NULL,
            role_jury ENUM('president', 'secretaire', 'membre') NOT NULL,
            ordre_affichage INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_jury_domaine (id_domaine),
            INDEX idx_jury_annee (id_annee),
            UNIQUE KEY unique_nomination (id_domaine, id_annee, nom_complet, role_jury)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (PDOException $e) {
    error_log("Table t_jury_nomination: " . $e->getMessage());
}

// Récupérer les domaines
$domaines = [];
try {
    $stmt = $pdo->query("SELECT id_domaine, code_domaine, nom_domaine FROM t_domaine ORDER BY nom_domaine");
    $domaines = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erreur récupération domaines: " . $e->getMessage());
}

// Récupérer les années académiques
$annees = [];
try {
    $stmt = $pdo->query("SELECT id_annee, date_debut, date_fin, statut FROM t_anne_academique ORDER BY date_debut DESC");
    $annees = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erreur récupération années: " . $e->getMessage());
}

// Déterminer l'année active
$annee_active_id = null;
try {
    $stmt = $pdo->query("SELECT valeur FROM t_configuration WHERE cle = 'annee_encours'");
    if ($row = $stmt->fetch()) {
        $annee_active_id = (int)$row['valeur'];
    }
} catch (PDOException $e) {
    if (!empty($annees)) {
        $annee_active_id = $annees[0]['id_annee'];
    }
}
?>

<style>
.jury-page { padding: 20px 30px; }
.jury-card {
    border: none;
    border-radius: 12px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.08);
    margin-bottom: 20px;
}
.jury-card .card-header {
    border-radius: 12px 12px 0 0;
    font-weight: 600;
}
.jury-header-president { background: linear-gradient(135deg, #1a237e, #283593); color: #fff; }
.jury-header-secretaire { background: linear-gradient(135deg, #00695c, #00897b); color: #fff; }
.jury-header-membre { background: linear-gradient(135deg, #37474f, #546e7a); color: #fff; }
.jury-member-item {
    display: flex;
    align-items: center;
    padding: 12px 16px;
    border-bottom: 1px solid #f0f0f0;
    transition: background 0.2s;
}
.jury-member-item:hover { background: #f8f9fa; }
.jury-member-item:last-child { border-bottom: none; }
.jury-member-info { flex: 1; }
.jury-member-name { font-weight: 600; font-size: 1rem; color: #333; }
.jury-member-details { font-size: 0.85rem; color: #666; margin-top: 2px; }
.jury-member-actions { display: flex; gap: 6px; }
.jury-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
}
.badge-president { background: #e8eaf6; color: #1a237e; }
.badge-secretaire { background: #e0f2f1; color: #00695c; }
.badge-membre { background: #eceff1; color: #37474f; }
.domaine-tab {
    border: 2px solid #e0e0e0;
    border-radius: 10px;
    padding: 10px 18px;
    margin: 4px;
    cursor: pointer;
    transition: all 0.3s;
    background: #fff;
    font-weight: 500;
    font-size: 0.9rem;
}
.domaine-tab:hover { border-color: #1a237e; color: #1a237e; }
.domaine-tab.active {
    border-color: #1a237e;
    background: #1a237e;
    color: #fff;
}
.empty-jury {
    text-align: center;
    padding: 40px 20px;
    color: #999;
}
.empty-jury i { font-size: 3rem; margin-bottom: 10px; display: block; }
.filter-bar {
    background: #fff;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.08);
    margin-bottom: 24px;
}
.stat-box {
    text-align: center;
    padding: 12px;
    border-radius: 10px;
    background: #f8f9fa;
}
.stat-box .stat-number { font-size: 1.5rem; font-weight: 700; }
.stat-box .stat-label { font-size: 0.8rem; color: #666; }
</style>

<div class="jury-page">
    <!-- En-tête -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-1"><i class="bi bi-people-fill me-2"></i>Nomination des Membres du Jury</h3>
            <p class="text-muted mb-0">Gérez la composition du jury pour chaque domaine et année académique</p>
        </div>
        <button class="btn btn-primary btn-lg" onclick="openAddModal()" id="btnAddMember" disabled>
            <i class="bi bi-plus-circle me-1"></i> Ajouter un membre
        </button>
    </div>

    <!-- Filtres -->
    <div class="filter-bar">
        <div class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label fw-bold"><i class="bi bi-calendar3 me-1"></i>Année Académique</label>
                <select id="selectAnnee" class="form-select">
                    <option value="">-- Sélectionner une année --</option>
                    <?php foreach ($annees as $a): ?>
                        <option value="<?= $a['id_annee'] ?>" <?= ($a['id_annee'] == $annee_active_id) ? 'selected' : '' ?>>
                            <?= date('Y', strtotime($a['date_debut'])) ?>-<?= date('Y', strtotime($a['date_fin'])) ?>
                            <?= $a['statut'] === 'active' ? ' (Active)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-8">
                <label class="form-label fw-bold"><i class="bi bi-grid-3x3-gap me-1"></i>Domaine</label>
                <div class="d-flex flex-wrap" id="domaineTabs">
                    <?php foreach ($domaines as $d): ?>
                        <div class="domaine-tab" data-id="<?= $d['id_domaine'] ?>" onclick="selectDomaine(this)">
                            <?= htmlspecialchars($d['code_domaine']) ?>
                            <small class="d-block" style="font-size:0.7rem;font-weight:400"><?= htmlspecialchars($d['nom_domaine']) ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistiques -->
    <div class="row mb-4" id="statsRow" style="display: none;">
        <div class="col-md-3">
            <div class="stat-box">
                <div class="stat-number text-primary" id="statPresident">0</div>
                <div class="stat-label">Président</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-box">
                <div class="stat-number text-success" id="statSecretaires">0</div>
                <div class="stat-label">Secrétaire(s)</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-box">
                <div class="stat-number text-secondary" id="statMembres">0</div>
                <div class="stat-label">Membre(s)</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-box">
                <div class="stat-number text-dark" id="statTotal">0</div>
                <div class="stat-label">Total</div>
            </div>
        </div>
    </div>

    <!-- Contenu du jury -->
    <div id="juryContent">
        <div class="empty-jury">
            <i class="bi bi-arrow-up-circle"></i>
            <h5>Sélectionnez un domaine</h5>
            <p>Choisissez une année académique et un domaine pour gérer la composition du jury</p>
        </div>
    </div>
</div>

<!-- Modal Ajout/Modification -->
<div class="modal fade" id="juryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 16px; border: none;">
            <div class="modal-header" style="background: linear-gradient(135deg, #1a237e, #283593); color: #fff; border-radius: 16px 16px 0 0;">
                <h5 class="modal-title" id="modalTitle">
                    <i class="bi bi-person-plus me-2"></i>Ajouter un membre du jury
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form id="juryForm">
                    <input type="hidden" id="editId" value="">
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Rôle dans le jury <span class="text-danger">*</span></label>
                        <select id="inputRoleJury" class="form-select" required>
                            <option value="">-- Choisir un rôle --</option>
                            <option value="president">🏛️ Président du jury</option>
                            <option value="secretaire">📋 Secrétaire du jury</option>
                            <option value="membre">👤 Membre du jury</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Titre académique</label>
                        <select id="inputTitre" class="form-select">
                            <option value="">-- Aucun --</option>
                            <option value="Prof.">Prof.</option>
                            <option value="Prof. Dr.">Prof. Dr.</option>
                            <option value="Prof. Ord.">Prof. Ord.</option>
                            <option value="Dr.">Dr.</option>
                            <option value="Ass.">Ass.</option>
                            <option value="C.T.">C.T.</option>
                            <option value="Ir.">Ir.</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Nom complet <span class="text-danger">*</span></label>
                        <input type="text" id="inputNom" class="form-control" placeholder="Ex: KABONGO MWAMBA Jean" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Fonction</label>
                        <input type="text" id="inputFonction" class="form-control" placeholder="Ex: Doyen de la Faculté, Chef de département...">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn btn-primary" onclick="saveJuryMember()">
                    <i class="bi bi-check-lg me-1"></i> <span id="btnSaveText">Enregistrer</span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Confirmation suppression -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content" style="border-radius: 16px; border: none;">
            <div class="modal-header bg-danger text-white" style="border-radius: 16px 16px 0 0;">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Confirmer</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center p-4">
                <p>Voulez-vous vraiment retirer <strong id="deleteNom"></strong> du jury ?</p>
                <input type="hidden" id="deleteId">
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn btn-danger" onclick="confirmDelete()">
                    <i class="bi bi-trash me-1"></i> Supprimer
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let selectedDomaine = null;
let selectedAnnee = null;
let juryData = [];

// Initialisation
document.addEventListener('DOMContentLoaded', function() {
    selectedAnnee = document.getElementById('selectAnnee').value;
    document.getElementById('selectAnnee').addEventListener('change', function() {
        selectedAnnee = this.value;
        loadJuryIfReady();
    });
});

function selectDomaine(el) {
    document.querySelectorAll('.domaine-tab').forEach(t => t.classList.remove('active'));
    el.classList.add('active');
    selectedDomaine = el.dataset.id;
    loadJuryIfReady();
}

function loadJuryIfReady() {
    if (selectedDomaine && selectedAnnee) {
        document.getElementById('btnAddMember').disabled = false;
        loadJuryMembers();
    } else {
        document.getElementById('btnAddMember').disabled = true;
    }
}

function loadJuryMembers() {
    const content = document.getElementById('juryContent');
    content.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary"></div><p class="mt-2">Chargement...</p></div>';

    fetch(`ajax/jury_handler.php?action=list&id_domaine=${selectedDomaine}&id_annee=${selectedAnnee}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                juryData = data.data;
                renderJury(data.data);
            } else {
                content.innerHTML = `<div class="alert alert-danger">${escapeHtml(data.message)}</div>`;
            }
        })
        .catch(err => {
            content.innerHTML = '<div class="alert alert-danger">Erreur de connexion au serveur</div>';
        });
}

function renderJury(members) {
    const content = document.getElementById('juryContent');
    
    if (members.length === 0) {
        document.getElementById('statsRow').style.display = 'none';
        content.innerHTML = `
            <div class="empty-jury">
                <i class="bi bi-person-plus"></i>
                <h5>Aucun membre du jury</h5>
                <p>Cliquez sur "Ajouter un membre" pour commencer la composition du jury</p>
                <button class="btn btn-outline-primary" onclick="openAddModal()">
                    <i class="bi bi-plus-circle me-1"></i> Ajouter le premier membre
                </button>
            </div>`;
        return;
    }

    // Statistiques
    const presidents = members.filter(m => m.role_jury === 'president');
    const secretaires = members.filter(m => m.role_jury === 'secretaire');
    const membresJury = members.filter(m => m.role_jury === 'membre');

    document.getElementById('statPresident').textContent = presidents.length;
    document.getElementById('statSecretaires').textContent = secretaires.length;
    document.getElementById('statMembres').textContent = membresJury.length;
    document.getElementById('statTotal').textContent = members.length;
    document.getElementById('statsRow').style.display = 'flex';

    let html = '';

    // Président
    html += renderSection('Président du jury', 'president', presidents, 'bi-star-fill', 'jury-header-president', 'badge-president');

    // Secrétaires
    html += renderSection('Secrétaire(s) du jury', 'secretaire', secretaires, 'bi-clipboard-check', 'jury-header-secretaire', 'badge-secretaire');

    // Membres
    html += renderSection('Membre(s) du jury', 'membre', membresJury, 'bi-person', 'jury-header-membre', 'badge-membre');

    content.innerHTML = html;
}

function renderSection(title, role, members, icon, headerClass, badgeClass) {
    let html = `<div class="jury-card card">
        <div class="card-header ${headerClass} d-flex justify-content-between align-items-center">
            <span><i class="bi ${icon} me-2"></i>${title}</span>
            <span class="badge bg-white text-dark">${members.length}</span>
        </div>
        <div class="card-body p-0">`;

    if (members.length === 0) {
        html += `<div class="text-center py-3 text-muted"><i class="bi bi-dash-circle me-1"></i>Aucun ${title.toLowerCase()} nommé</div>`;
    } else {
        members.forEach((m, idx) => {
            const titre = m.titre_academique ? escapeHtml(m.titre_academique) + ' ' : '';
            const fonction = m.fonction ? `<span class="text-muted">— ${escapeHtml(m.fonction)}</span>` : '';
            html += `
            <div class="jury-member-item">
                <div class="me-3">
                    <span class="jury-badge ${badgeClass}">${idx + 1}</span>
                </div>
                <div class="jury-member-info">
                    <div class="jury-member-name">${titre}${escapeHtml(m.nom_complet)}</div>
                    <div class="jury-member-details">${fonction}</div>
                </div>
                <div class="jury-member-actions">
                    <button class="btn btn-sm btn-outline-primary" onclick="editMember(${m.id})" title="Modifier">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-danger" onclick="deleteMember(${m.id}, '${escapeHtml(m.nom_complet).replace(/'/g, "\\'")}' )" title="Supprimer">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>`;
        });
    }

    html += '</div></div>';
    return html;
}

// Ouvrir le modal d'ajout
function openAddModal() {
    document.getElementById('editId').value = '';
    document.getElementById('inputRoleJury').value = '';
    document.getElementById('inputTitre').value = '';
    document.getElementById('inputNom').value = '';
    document.getElementById('inputFonction').value = '';
    document.getElementById('modalTitle').innerHTML = '<i class="bi bi-person-plus me-2"></i>Ajouter un membre du jury';
    document.getElementById('btnSaveText').textContent = 'Enregistrer';
    new bootstrap.Modal(document.getElementById('juryModal')).show();
}

// Modifier un membre
function editMember(id) {
    fetch(`ajax/jury_handler.php?action=get&id=${id}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const m = data.data;
                document.getElementById('editId').value = m.id;
                document.getElementById('inputRoleJury').value = m.role_jury;
                document.getElementById('inputTitre').value = m.titre_academique || '';
                document.getElementById('inputNom').value = m.nom_complet;
                document.getElementById('inputFonction').value = m.fonction || '';
                document.getElementById('modalTitle').innerHTML = '<i class="bi bi-pencil me-2"></i>Modifier un membre du jury';
                document.getElementById('btnSaveText').textContent = 'Modifier';
                new bootstrap.Modal(document.getElementById('juryModal')).show();
            }
        });
}

// Sauvegarder (ajout ou modification)
function saveJuryMember() {
    const editId = document.getElementById('editId').value;
    const role = document.getElementById('inputRoleJury').value;
    const titre = document.getElementById('inputTitre').value;
    const nom = document.getElementById('inputNom').value.trim();
    const fonction = document.getElementById('inputFonction').value.trim();

    if (!role || !nom) {
        alert('Veuillez remplir les champs obligatoires (rôle et nom)');
        return;
    }

    const formData = new FormData();
    formData.append('nom_complet', nom);
    formData.append('titre_academique', titre);
    formData.append('fonction', fonction);
    formData.append('role_jury', role);

    if (editId) {
        formData.append('action', 'update');
        formData.append('id', editId);
    } else {
        formData.append('action', 'add');
        formData.append('id_domaine', selectedDomaine);
        formData.append('id_annee', selectedAnnee);
    }

    fetch('ajax/jury_handler.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                bootstrap.Modal.getInstance(document.getElementById('juryModal')).hide();
                loadJuryMembers();
                showToast(data.message, 'success');
            } else {
                alert(data.message);
            }
        })
        .catch(() => alert('Erreur de connexion'));
}

// Supprimer un membre
function deleteMember(id, nom) {
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteNom').textContent = nom;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}

function confirmDelete() {
    const id = document.getElementById('deleteId').value;
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('id', id);

    fetch('ajax/jury_handler.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            bootstrap.Modal.getInstance(document.getElementById('deleteModal')).hide();
            if (data.success) {
                loadJuryMembers();
                showToast(data.message, 'success');
            } else {
                alert(data.message);
            }
        })
        .catch(() => alert('Erreur de connexion'));
}

// Toast notification
function showToast(message, type) {
    const toast = document.createElement('div');
    toast.className = `alert alert-${type === 'success' ? 'success' : 'danger'} position-fixed`;
    toast.style.cssText = 'top:20px;right:20px;z-index:9999;min-width:300px;box-shadow:0 4px 12px rgba(0,0,0,0.15);border-radius:10px;';
    toast.innerHTML = `<i class="bi bi-${type === 'success' ? 'check-circle' : 'x-circle'} me-2"></i>${escapeHtml(message)}`;
    document.body.appendChild(toast);
    setTimeout(() => { toast.style.opacity = '0'; toast.style.transition = 'opacity 0.5s'; setTimeout(() => toast.remove(), 500); }, 3000);
}

// Échapper les caractères HTML
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>
