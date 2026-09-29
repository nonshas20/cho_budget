<?php
require 'includes/db_connect.php';

// ---------------------------------------------------------
// 1. SECURITY & PERMISSIONS
// ---------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) { session_start(); }
// Restrict strictly to Admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') { 
    header("Location: index.php"); 
    exit; 
}

$page_title = 'Manage Facilities';
require 'includes/header.php';

$success_msg = "";
$error_msg   = "";

$reopen_fac_id = null;

// ---------------------------------------------------------
// 2. HANDLE ACTIONS
// ---------------------------------------------------------

// A. Add New Facility
if (isset($_POST['add_facility'])) {
    try {
        $name = trim($_POST['name']);
        $status = $_POST['status'];
        
        $stmt = $pdo->prepare("INSERT INTO facilities (name, status) VALUES (?, ?)");
        $stmt->execute([$name, $status]);
        $success_msg = "Facility successfully added.";
    } catch (Exception $e) { $error_msg = "Error: " . $e->getMessage(); }
}

// B. Edit Facility
if (isset($_POST['edit_facility'])) {
    try {
        $id = $_POST['edit_id'];
        $name = trim($_POST['edit_name']);
        $status = $_POST['edit_status'];
        
        $stmt = $pdo->prepare("UPDATE facilities SET name = ?, status = ? WHERE id = ?");
        $stmt->execute([$name, $status, $id]);
        $success_msg = "Facility updated.";
    } catch (Exception $e) { $error_msg = "Error: " . $e->getMessage(); }
}

// C. Delete Facility
if (isset($_POST['delete_facility'])) {
    try {
        $id = $_POST['del_id'];
        $pdo->prepare("DELETE FROM facilities WHERE id = ?")->execute([$id]);
        $success_msg = "Facility deleted.";
    } catch (Exception $e) { 
        $error_msg = "Cannot delete: This facility is likely linked to existing cash inflows. Set status to 'Inactive' instead."; 
    }
}

// D. Add Accreditation to Facility
if (isset($_POST['add_accreditation'])) {
    try {
        $fac_id = $_POST['acc_facility_id'];
        $type = trim($_POST['package_type']);
        $acc_no = trim($_POST['accreditation_no']);
        
        $stmt = $pdo->prepare("INSERT INTO facility_accreditations (facility_id, package_type, accreditation_no) VALUES (?, ?, ?)");
        $stmt->execute([$fac_id, $type, $acc_no]);
        $success_msg = "Accreditation added to facility.";
        $reopen_fac_id = $fac_id;
    } catch (Exception $e) { $error_msg = "Error: " . $e->getMessage(); }
}

// E. Delete Accreditation
if (isset($_POST['delete_accreditation'])) {
    try {
        $id = $_POST['del_acc_id'];
        $pdo->prepare("DELETE FROM facility_accreditations WHERE id = ?")->execute([$id]);
        $success_msg = "Accreditation removed.";
        $reopen_fac_id = $_POST['reopen_fac_id'] ?? null;
    } catch (Exception $e) { $error_msg = "Cannot delete: This accreditation is linked to existing cash inflows."; }
}

// F. Add HC Professional
if (isset($_POST['add_hcp'])) {
    try {
        $acc_id = $_POST['hcp_acc_id'];
        $name = trim($_POST['hcp_name']);
        $stmt = $pdo->prepare("INSERT INTO accreditation_hcps (accreditation_id, hcp_name) VALUES (?, ?)");
        $stmt->execute([$acc_id, $name]);
        $success_msg = "HC Professional assigned to accreditation.";
        $reopen_fac_id = $_POST['reopen_fac_id'] ?? null;
    } catch (Exception $e) { $error_msg = "Error: " . $e->getMessage(); }
}

// G. Delete HC Professional
if (isset($_POST['delete_hcp'])) {
    try {
        $id = $_POST['del_hcp_id'];
        $pdo->prepare("DELETE FROM accreditation_hcps WHERE id = ?")->execute([$id]);
        $success_msg = "HC Professional removed.";
        $reopen_fac_id = $_POST['reopen_fac_id'] ?? null;
    } catch (Exception $e) { $error_msg = "Error: " . $e->getMessage(); }
}

// H. Edit HC Professional
if (isset($_POST['edit_hcp'])) {
    try {
        $id = $_POST['edit_hcp_id'];
        $name = trim($_POST['edit_hcp_name']);
        $stmt = $pdo->prepare("UPDATE accreditation_hcps SET hcp_name = ? WHERE id = ?");
        $stmt->execute([$name, $id]);
        $success_msg = "HC Professional updated.";
        $reopen_fac_id = $_POST['reopen_fac_id'] ?? null;
    } catch (Exception $e) { $error_msg = "Error: " . $e->getMessage(); }
}

// ---------------------------------------------------------
// 3. FETCH DATA
// ---------------------------------------------------------

// Fetch Facilities
$facilities = $pdo->query("SELECT * FROM facilities ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch all HCPs and group by accreditation_id
$hcp_stmt = $pdo->query("SELECT * FROM accreditation_hcps ORDER BY hcp_name ASC");
$hcps = [];
while ($hrow = $hcp_stmt->fetch(PDO::FETCH_ASSOC)) {
    $hcps[$hrow['accreditation_id']][] = $hrow;
}

// Fetch Accreditations and group them by Facility ID
$acc_stmt = $pdo->query("SELECT * FROM facility_accreditations ORDER BY package_type ASC");
$accreditations = [];
while ($row = $acc_stmt->fetch(PDO::FETCH_ASSOC)) {
    $row['hcps'] = $hcps[$row['id']] ?? [];
    $accreditations[$row['facility_id']][] = $row;
}

// Common Package Types for suggestions
$packages = ['PhilHealth Konsulta', 'PhilHealth Maternity', 'PhilHealth PCB', 'PhilHealth TBDOTS', 'PhilHealth ABTC', 'General / Default'];
?>

<div class="container-fluid mt-4 px-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex align-items-center gap-2">
            <h4 class="fw-bold text-dark mb-0">Master Data: Facilities & Accreditations</h4>
        </div>
        <button class="btn btn-success fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#addFacilityModal">
            <i class="bi bi-plus-circle me-1"></i> Add Facility
        </button>
    </div>

    <?php if ($success_msg): ?><div class="alert alert-success alert-dismissible fade show"><?php echo $success_msg; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
    <?php if ($error_msg): ?><div class="alert alert-danger"><?php echo $error_msg; ?></div><?php endif; ?>

    <div class="card shadow border-0">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th style="width: 35%" class="ps-3">Facility Name</th>
                            <th style="width: 15%" class="text-center">Status</th>
                            <th style="width: 25%" class="text-center">Active Accreditations</th>
                            <th style="width: 25%" class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(count($facilities) > 0): foreach($facilities as $fac): 
                            $fac_accs = $accreditations[$fac['id']] ?? [];
                            $acc_count = count($fac_accs);
                            $badge_color = $fac['status'] === 'active' ? 'bg-success' : 'bg-secondary';
                            
                            // Prepare data for JS Modals
                            $json_fac = htmlspecialchars(json_encode($fac), ENT_QUOTES, 'UTF-8');
                            $json_acc = htmlspecialchars(json_encode($fac_accs), ENT_QUOTES, 'UTF-8');
                        ?>
                        <tr>
                            <td class="ps-3 fw-bold text-dark">
                                <i class="bi bi-hospital me-2 text-muted"></i><?php echo htmlspecialchars($fac['name']); ?>
                            </td>
                            <td class="text-center">
                                <span class="badge <?php echo $badge_color; ?> text-uppercase"><?php echo $fac['status']; ?></span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-primary rounded-pill"><?php echo $acc_count; ?> Packages</span>
                            </td>
                            <td class="text-center">
                                <button id="btn-licenses-<?php echo $fac['id']; ?>" class="btn btn-sm btn-outline-primary fw-bold border-0 me-1" onclick='openAccModal(<?php echo $json_fac; ?>, <?php echo $json_acc; ?>)' title="Manage Accreditations">
                                    <i class="bi bi-card-list me-1"></i> Accreditations
                                </button>
                                <button class="btn btn-sm btn-outline-dark border-0 me-1" onclick='openEditFac(<?php echo $json_fac; ?>)' title="Edit Name/Status">
                                    <i class="bi bi-pencil-square"></i>
                                </button>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this facility?');">
                                    <input type="hidden" name="del_id" value="<?php echo $fac['id']; ?>">
                                    <button type="submit" name="delete_facility" class="btn btn-sm btn-outline-danger border-0" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; else: ?>
                        <tr><td colspan="4" class="text-center py-4 text-muted">No facilities registered.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="addFacilityModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle me-2"></i>Add New Facility</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <div class="mb-3">
                        <label class="small fw-bold text-muted">Facility Name</label>
                        <input type="text" name="name" class="form-control" required placeholder="e.g. Baclaran Health Center">
                    </div>
                    <div class="mb-3">
                        <label class="small fw-bold text-muted">Status</label>
                        <select name="status" class="form-select">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <button type="submit" name="add_facility" class="btn btn-success w-100 fw-bold">Save Facility</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="editFacilityModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i>Edit Facility</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="edit_id" id="edit_fac_id">
                    <div class="mb-3">
                        <label class="small fw-bold text-muted">Facility Name</label>
                        <input type="text" name="edit_name" id="edit_fac_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="small fw-bold text-muted">Status</label>
                        <select name="edit_status" id="edit_fac_status" class="form-select">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <button type="submit" name="edit_facility" class="btn btn-dark w-100 fw-bold">Update Details</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="accModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-card-list me-2"></i>Manage Accreditations</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-light">
                <h5 class="fw-bold text-dark mb-3" id="accModalFacName">Facility Name</h5>
                
                <div class="card border-primary mb-4 shadow-sm">
                    <div class="card-body p-3">
                        <form method="POST" class="row g-2 align-items-end">
                            <input type="hidden" name="acc_facility_id" id="acc_hidden_fac_id">
                            <div class="col-md-5">
                                <label class="small fw-bold text-muted">Package Type</label>
                                <input type="text" name="package_type" class="form-control form-control-sm" list="pkgOptions" placeholder="e.g. Konsulta" required>
                                <datalist id="pkgOptions">
                                    <?php foreach($packages as $pkg) echo "<option value=\"$pkg\">"; ?>
                                </datalist>
                            </div>
                            <div class="col-md-5">
                                <label class="small fw-bold text-muted">Accreditation Number</label>
                                <input type="text" name="accreditation_no" class="form-control form-control-sm font-monospace" placeholder="PH-XXXXXXXX" required>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" name="add_accreditation" class="btn btn-sm btn-primary w-100 fw-bold">Add</button>
                            </div>
                        </form>
                    </div>
                </div>

                <h6 class="fw-bold text-muted small text-uppercase mb-2">Registered Licenses for this Facility:</h6>
                <div class="table-responsive bg-white border rounded">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 25%">Package Type</th>
                                <th style="width: 25%">Accreditation Number</th>
                                <th style="width: 40%">HC Professionals</th>
                                <th class="text-center" style="width: 10%">Action</th>
                            </tr>
                        </thead>
                        <tbody id="accTableBody">
                            </tbody>
                    </table>
                </div>

            </div>
        </div>
    </div>
</div>

<script>
    // Prevent Form Double Submit
    if (window.history.replaceState) {
        window.history.replaceState(null, null, window.location.href);
    }

    // Open Edit Facility Modal
    function openEditFac(facData) {
        document.getElementById('edit_fac_id').value = facData.id;
        document.getElementById('edit_fac_name').value = facData.name;
        document.getElementById('edit_fac_status').value = facData.status;
        new bootstrap.Modal(document.getElementById('editFacilityModal')).show();
    }

    // Open Manage Accreditations Modal and populate table
    function openAccModal(facData, accData) {
        document.getElementById('acc_hidden_fac_id').value = facData.id;
        document.getElementById('accModalFacName').innerHTML = `<i class="bi bi-hospital me-2 text-primary"></i>${facData.name}`;
        
        const tbody = document.getElementById('accTableBody');
        tbody.innerHTML = '';
        
        if (accData && accData.length > 0) {
            accData.forEach(acc => {
                let hcpListHtml = '';
                if (acc.hcps && acc.hcps.length > 0) {
                    acc.hcps.forEach(hcp => {
                        let hcpNameSafe = hcp.hcp_name.replace(/'/g, "\\'");
                        hcpListHtml += `
                            <div class="d-flex justify-content-between align-items-center mb-1 bg-white p-1 rounded border">
                                <span class="small text-dark fw-bold" style="cursor:pointer;" onclick="editHcp(${hcp.id}, '${hcpNameSafe}', ${facData.id})" title="Click to edit name"><i class="bi bi-person-badge me-1 text-secondary"></i>${hcp.hcp_name}</span>
                                <form method="POST" onsubmit="return confirm('Remove this HC Professional?');" class="m-0 p-0">
                                    <input type="hidden" name="del_hcp_id" value="${hcp.id}">
                                    <input type="hidden" name="reopen_fac_id" value="${facData.id}">
                                    <button type="submit" name="delete_hcp" class="btn btn-sm btn-link text-danger border-0 p-0 m-0"><i class="bi bi-x-circle"></i></button>
                                </form>
                            </div>
                        `;
                    });
                } else {
                    hcpListHtml = `<div class="small text-muted mb-1 fst-italic">No HC Professional assigned</div>`;
                }

                let addHcpForm = `
                    <form method="POST" class="d-flex gap-1 mt-2">
                        <input type="hidden" name="hcp_acc_id" value="${acc.id}">
                        <input type="hidden" name="reopen_fac_id" value="${facData.id}">
                        <input type="text" name="hcp_name" class="form-control form-control-sm" placeholder="Add HC Professional" required>
                        <button type="submit" name="add_hcp" class="btn btn-sm btn-outline-success py-0 px-2" title="Add HCP"><i class="bi bi-plus-lg"></i></button>
                    </form>
                `;

                tbody.innerHTML += `
                    <tr>
                        <td class="fw-bold text-dark align-middle">${acc.package_type}</td>
                        <td class="font-monospace text-primary align-middle">${acc.accreditation_no}</td>
                        <td class="align-middle">
                            ${hcpListHtml}
                            ${addHcpForm}
                        </td>
                        <td class="text-center align-middle">
                            <form method="POST" onsubmit="return confirm('Remove this accreditation?');">
                                <input type="hidden" name="del_acc_id" value="${acc.id}">
                                <input type="hidden" name="reopen_fac_id" value="${facData.id}">
                                <button type="submit" name="delete_accreditation" class="btn btn-sm btn-outline-danger border-0 py-0"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                `;
            });
        } else {
            tbody.innerHTML = `<tr><td colspan="3" class="text-center text-muted py-3">No accreditations registered for this facility.</td></tr>`;
        }
        
        new bootstrap.Modal(document.getElementById('accModal')).show();
    }

    function editHcp(id, currentName, facId) {
        let newName = prompt("Edit HC Professional Name:", currentName);
        if (newName !== null && newName.trim() !== "" && newName !== currentName) {
            let form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="edit_hcp_id" value="${id}">
                <input type="hidden" name="edit_hcp_name" value="${newName}">
                <input type="hidden" name="reopen_fac_id" value="${facId}">
                <input type="hidden" name="edit_hcp" value="1">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    }
</script>

<?php if ($reopen_fac_id): ?>
<script>
    document.addEventListener("DOMContentLoaded", function() {
        let btn = document.getElementById('btn-licenses-<?php echo $reopen_fac_id; ?>');
        if (btn) btn.click();
    });
</script>
<?php endif; ?>

<?php require 'includes/footer.php'; ?>