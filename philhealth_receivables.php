<?php
require 'includes/db_connect.php';

// ---------------------------------------------------------
// 1. SECURITY & PERMISSIONS
// ---------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['role'])) { header("Location: index.php"); exit; }

$user_role  = strtolower($_SESSION['role']);
$can_edit   = in_array($user_role, ['admin', 'encoder']);
$can_delete = ($user_role === 'admin');

$success_msg = "";
$error_msg   = "";

// ---------------------------------------------------------
// 2. HELPERS
// ---------------------------------------------------------
function getDocumentType($pkg) {
    $pkg = strtolower($pkg);
    if (strpos($pkg, 'primary care') !== false || strpos($pkg, 'konsulta') !== false || strpos($pkg, 'pcb') !== false || strpos($pkg, 'capitation') !== false) {
        return 'capitation';
    }
    if (strpos($pkg, 'gamot') !== false || strpos($pkg, 'pharmacy') !== false || strpos($pkg, 'drug') !== false) {
        return 'pharmacy';
    }
    return 'claims'; // TBDOTS, MCP, OHAT, etc.
}

function recalculateParentClaimsTotals($pdo, $rec_id) {
    $totals = $pdo->query("SELECT SUM(gross) as total_gross, SUM(withholding_tax) as total_tax, SUM(hci) as total_hci, SUM(pf) as total_pf FROM philhealth_claim_lines WHERE receivable_id = $rec_id")->fetch(PDO::FETCH_ASSOC);
    $g = floatval($totals['total_gross'] ?? 0);
    $t = floatval($totals['total_tax'] ?? 0);
    $h = floatval($totals['total_hci'] ?? 0);
    $p = floatval($totals['total_pf'] ?? 0);
    $pdo->prepare("UPDATE philhealth_receivables SET gross_amount = ?, withholding_tax = ?, hci_share = ?, pf_share = ? WHERE id = ?")->execute([$g, $t, $h, $p, $rec_id]);
}

function recalculateParentPharmacyTotals($pdo, $rec_id) {
    $totals = $pdo->query("SELECT SUM(gross) as total_gross, SUM(withholding_tax) as total_tax, SUM(net_amount) as total_net FROM philhealth_pharmacy_lines WHERE receivable_id = $rec_id")->fetch(PDO::FETCH_ASSOC);
    $g = floatval($totals['total_gross'] ?? 0);
    $t = floatval($totals['total_tax'] ?? 0);
    $n = floatval($totals['total_net'] ?? 0);
    $pdo->prepare("UPDATE philhealth_receivables SET gross_amount = ?, withholding_tax = ?, amount = ? WHERE id = ?")->execute([$g, $t, $n, $rec_id]);
}

function generateACPN($pdo, $fac_id, $doc_type, $date_credited) {
    $stmt = $pdo->prepare("SELECT name FROM facilities WHERE id = ?");
    $stmt->execute([$fac_id]);
    $fac_name = 'Unknown';
    if ($row = $stmt->fetch()) {
        $fac_name = $row['name'];
    }
    
    // Extract acronym from facility name
    $words = explode(' ', preg_replace('/[^A-Za-z0-9 ]/', '', $fac_name));
    $acronym = '';
    foreach($words as $w) { if(!empty($w)) $acronym .= strtoupper($w[0]); }
    if (empty($acronym)) $acronym = 'FAC';

    $type_prefix = ['capitation' => 'CAP', 'claims' => 'CLM', 'pharmacy' => 'RX'][$doc_type] ?? 'DOC';
    $date_to_use = $date_credited ?: date('Y-m-d');
    $period_str = date('mY', strtotime($date_to_use));

    $prefix = "{$type_prefix}-{$acronym}-{$period_str}-";
    $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(acpn_number, '-', -1) AS UNSIGNED)) FROM philhealth_receivables WHERE acpn_number LIKE ?");
    $stmt->execute([$prefix . "%"]);
    $max = $stmt->fetchColumn();
    $count = ($max ? intval($max) : 0) + 1;
    
    return $prefix . str_pad($count, 3, '0', STR_PAD_LEFT);
}

// ---------------------------------------------------------
// 3. AJAX HANDLERS
// ---------------------------------------------------------
if (isset($_GET['ajax_generate_acpn'])) {
    header('Content-Type: application/json');
    try {
        $fac_id = $_GET['fac_id'] ?? null;
        $pkg = $_GET['pkg'] ?? null;
        $date = $_GET['date'] ?? null;
        if (!$fac_id || !$pkg) throw new Exception("Facility and Package are required.");
        $doc_type = getDocumentType($pkg);
        $acpn = generateACPN($pdo, $fac_id, $doc_type, $date);
        echo json_encode(['success' => true, 'acpn' => $acpn]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ---------------------------------------------------------
// 4. HANDLE ACTIONS
// ---------------------------------------------------------

// A. Add Receivable
if ($can_edit && isset($_POST['add_receivable'])) {
    try {
        $fac_id   = $_POST['facility_id'];
        $acc_id   = !empty($_POST['accreditation_id']) ? $_POST['accreditation_id'] : null;
        $package  = trim($_POST['package_category']);
        $doc_type = getDocumentType($package);
        
        $p_start = $_POST['period_start'] ?? '';
        $p_end   = $_POST['period_end'] ?? '';
        $period  = ($p_start && $p_end) ? date('m/d/Y', strtotime($p_start)) . ' to ' . date('m/d/Y', strtotime($p_end)) : trim($_POST['period_covered'] ?? '');
        
        $date_credited = !empty($_POST['date_credited']) ? $_POST['date_credited'] : null;
        $or_no    = !empty($_POST['or_number']) ? trim($_POST['or_number']) : null;
        $or_date  = !empty($_POST['or_date']) ? $_POST['or_date'] : null;
        $amount   = floatval(str_replace(',', '', $_POST['amount'])); 
        $user_id  = $_SESSION['user_id'] ?? null;

        if ($amount <= 0) throw new Exception("Net amount must be greater than zero.");

        $acpn = ''; $dv = ''; $sap = null; $tranche = null;
        $profiled_m = null; $profiled_d = null; $enlisted = null; $profiled = null;
        $gross = 0; $tax = 0; $hci = 0; $pf = 0;
        $gacn_no = null; $gacn_date = null; $hcp_id = null;

        $acpn = trim($_POST['acpn_number'] ?? '');
        if (empty($acpn)) {
            $acpn = generateACPN($pdo, $fac_id, $doc_type, $date_credited);
        }

        if ($doc_type === 'capitation') {
            $dv      = is_array($_POST['dv_number'] ?? null) ? implode(', ', array_filter(array_map('trim', $_POST['dv_number']))) : trim($_POST['dv_number'] ?? '');
            $sap     = is_array($_POST['sap_number'] ?? null) ? implode(', ', array_filter(array_map('trim', $_POST['sap_number']))) : trim($_POST['sap_number'] ?? '');
            $tranche = !empty($_POST['tranche_type']) ? $_POST['tranche_type'] : null;
            $enlisted    = !empty($_POST['enlisted_count']) ? intval($_POST['enlisted_count']) : null;
            $profiled_m  = !empty($_POST['profiled_members']) ? intval($_POST['profiled_members']) : null;
            $profiled_d  = !empty($_POST['profiled_dependents']) ? intval($_POST['profiled_dependents']) : null;
            $profiled    = ($profiled_m ?? 0) + ($profiled_d ?? 0);
        } elseif ($doc_type === 'claims') {
            $hcp_id  = !empty($_POST['hcp_id']) ? intval($_POST['hcp_id']) : null;
            $tax     = floatval(str_replace(',', '', $_POST['withholding_tax'] ?? '0'));
            // Gross, HCI, PF will be calculated from child lines later
        } elseif ($doc_type === 'pharmacy') {
            $acpn      = trim($_POST['acpn_number'] ?? ''); 
            $gacn_no   = null;
            $gacn_date = null;
        }

        $sql = "INSERT INTO philhealth_receivables 
                (facility_id, accreditation_id, acpn_number, dv_number, period_covered, date_credited, package_category, document_type, 
                 tranche_type, sap_number, profiled_members, profiled_dependents,
                 amount, gross_amount, withholding_tax, hci_share, pf_share, enlisted_count, profiled_count,
                 gacn_number, gacn_date, or_number, or_date, hcp_id,
                 status, created_by, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW())";
        $pdo->prepare($sql)->execute([
            $fac_id, $acc_id, $acpn, $dv, $period, $date_credited, $package, $doc_type,
            $tranche, $sap, $profiled_m, $profiled_d,
            $amount, $gross, $tax, $hci, $pf, $enlisted, $profiled,
            $gacn_no, $gacn_date, $or_no, $or_date, $hcp_id,
            $user_id
        ]);
        
        $rec_id = $pdo->lastInsertId();

        // Process dynamic claim lines if any
        if ($doc_type === 'claims' && isset($_POST['cl_pabn_number']) && is_array($_POST['cl_pabn_number'])) {
            $stmt_cl = $pdo->prepare("INSERT INTO philhealth_claim_lines (receivable_id, pabn_number, series_number, member_pin, patient_name, confinement_period, caserate1_code, caserate1_gross, caserate2_code, caserate2_gross, caserate3_code, caserate3_gross, gross, withholding_tax, hci, pf) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            for ($i = 0; $i < count($_POST['cl_pabn_number']); $i++) {
                $pabn = trim($_POST['cl_pabn_number'][$i]);
                if (empty($pabn)) continue;
                
                $c_start = $_POST['cl_conf_start'][$i] ?? '';
                $c_end   = $_POST['cl_conf_end'][$i] ?? '';
                $conf_period = ($c_start && $c_end) ? date('m/d/Y', strtotime($c_start)) . ' to ' . date('m/d/Y', strtotime($c_end)) : '';

                $stmt_cl->execute([
                    $rec_id,
                    $pabn,
                    trim($_POST['cl_series_number'][$i] ?? ''),
                    trim($_POST['cl_member_pin'][$i] ?? ''),
                    trim($_POST['cl_patient_name'][$i] ?? ''),
                    $conf_period,
                    trim($_POST['cl_cr1_code'][$i] ?? ''),
                    floatval(str_replace(',', '', $_POST['cl_cr1_gross'][$i] ?? '0')),
                    trim($_POST['cl_cr2_code'][$i] ?? ''),
                    floatval(str_replace(',', '', $_POST['cl_cr2_gross'][$i] ?? '0')),
                    trim($_POST['cl_cr3_code'][$i] ?? ''),
                    floatval(str_replace(',', '', $_POST['cl_cr3_gross'][$i] ?? '0')),
                    floatval(str_replace(',', '', $_POST['cl_gross'][$i] ?? '0')),
                    floatval(str_replace(',', '', $_POST['cl_tax'][$i] ?? '0')),
                    floatval(str_replace(',', '', $_POST['cl_hci'][$i] ?? '0')),
                    floatval(str_replace(',', '', $_POST['cl_pf'][$i] ?? '0'))
                ]);
            }
            recalculateParentClaimsTotals($pdo, $rec_id);
        }

        // Process dynamic pharmacy lines if any
        if ($doc_type === 'pharmacy' && isset($_POST['ph_gacn_number']) && is_array($_POST['ph_gacn_number'])) {
            $stmt_ph = $pdo->prepare("INSERT INTO philhealth_pharmacy_lines (receivable_id, gacn_number, gacn_date, pabn_number, gross, withholding_tax, net_amount) VALUES (?,?,?,?,?,?,?)");
            for ($i = 0; $i < count($_POST['ph_gacn_number']); $i++) {
                $gacn = trim($_POST['ph_gacn_number'][$i]);
                $pabn = trim($_POST['ph_pabn_number'][$i] ?? '');
                if (empty($gacn) && empty($pabn)) continue;
                
                $stmt_ph->execute([
                    $rec_id,
                    $gacn,
                    !empty($_POST['ph_gacn_date'][$i]) ? $_POST['ph_gacn_date'][$i] : null,
                    $pabn,
                    floatval(str_replace(',', '', $_POST['ph_gross'][$i] ?? '0')),
                    floatval(str_replace(',', '', $_POST['ph_tax'][$i] ?? '0')),
                    floatval(str_replace(',', '', $_POST['ph_net'][$i] ?? '0'))
                ]);
            }
            recalculateParentPharmacyTotals($pdo, $rec_id);
        }

        $success_msg = "PhilHealth receivable ($doc_type) successfully recorded.";
    } catch (Exception $e) { $error_msg = "Error: " . $e->getMessage(); }
}

// B. Update Receivable
if ($can_edit && isset($_POST['update_receivable'])) {
    try {
        $id       = $_POST['edit_id'];
        $fac_id   = $_POST['edit_facility_id'];
        $acc_id   = !empty($_POST['edit_accreditation_id']) ? $_POST['edit_accreditation_id'] : null;
        $package  = trim($_POST['edit_package_category']);
        $doc_type = getDocumentType($package);
        
        $p_start = $_POST['edit_period_start'] ?? '';
        $p_end   = $_POST['edit_period_end'] ?? '';
        $period  = ($p_start && $p_end) ? date('m/d/Y', strtotime($p_start)) . ' to ' . date('m/d/Y', strtotime($p_end)) : trim($_POST['edit_period_covered'] ?? '');
        
        $date_credited = !empty($_POST['edit_date_credited']) ? $_POST['edit_date_credited'] : null;
        $or_no    = !empty($_POST['edit_or_number']) ? trim($_POST['edit_or_number']) : null;
        $or_date  = !empty($_POST['edit_or_date']) ? $_POST['edit_or_date'] : null;
        $amount   = floatval(str_replace(',', '', $_POST['edit_amount'])); 

        $acpn = ''; $dv = ''; $sap = null; $tranche = null;
        $profiled_m = null; $profiled_d = null; $enlisted = null; $profiled = null;
        $gross = 0; $tax = 0; $hci = 0; $pf = 0;
        $gacn_no = null; $gacn_date = null; $hcp_id = null;

        $acpn = trim($_POST['edit_acpn_number'] ?? '');
        if (empty($acpn)) {
            $acpn = generateACPN($pdo, $fac_id, $doc_type, $date_credited);
        }

        if ($doc_type === 'capitation') {
            $dv      = is_array($_POST['edit_dv_number'] ?? null) ? implode(', ', array_filter(array_map('trim', $_POST['edit_dv_number']))) : trim($_POST['edit_dv_number'] ?? '');
            $sap     = is_array($_POST['edit_sap_number'] ?? null) ? implode(', ', array_filter(array_map('trim', $_POST['edit_sap_number']))) : trim($_POST['edit_sap_number'] ?? '');
            $tranche = !empty($_POST['edit_tranche_type']) ? $_POST['edit_tranche_type'] : null;
            $enlisted    = !empty($_POST['edit_enlisted_count']) ? intval($_POST['edit_enlisted_count']) : null;
            $profiled_m  = !empty($_POST['edit_profiled_members']) ? intval($_POST['edit_profiled_members']) : null;
            $profiled_d  = !empty($_POST['edit_profiled_dependents']) ? intval($_POST['edit_profiled_dependents']) : null;
            $profiled    = ($profiled_m ?? 0) + ($profiled_d ?? 0);
        } elseif ($doc_type === 'claims') {
            $hcp_id  = !empty($_POST['edit_hcp_id']) ? intval($_POST['edit_hcp_id']) : null;
            $tax     = floatval(str_replace(',', '', $_POST['edit_withholding_tax'] ?? '0'));
            
            // Retain existing totals unless recalculated by line additions/deletions
            $existing = $pdo->query("SELECT gross_amount, hci_share, pf_share FROM philhealth_receivables WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
            $gross = $existing['gross_amount'] ?? 0;
            $hci   = $existing['hci_share'] ?? 0;
            $pf    = $existing['pf_share'] ?? 0;
        } elseif ($doc_type === 'pharmacy') {
            $acpn      = trim($_POST['edit_acpn_number'] ?? '');
            $gacn_no   = null;
            $gacn_date = null;
            
            // Retain existing totals unless recalculated by line additions/deletions
            $existing = $pdo->query("SELECT gross_amount, withholding_tax, amount FROM philhealth_receivables WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
            $gross = $existing['gross_amount'] ?? 0;
            $tax   = $existing['withholding_tax'] ?? 0;
            $amount = $existing['amount'] ?? 0;
        }

        $sql = "UPDATE philhealth_receivables SET 
                facility_id=?, accreditation_id=?, acpn_number=?, dv_number=?, period_covered=?, date_credited=?, package_category=?, document_type=?,
                tranche_type=?, sap_number=?, profiled_members=?, profiled_dependents=?,
                amount=?, gross_amount=?, withholding_tax=?, hci_share=?, pf_share=?, enlisted_count=?, profiled_count=?,
                gacn_number=?, gacn_date=?, or_number=?, or_date=?, hcp_id=?
                WHERE id=?";
        $pdo->prepare($sql)->execute([
            $fac_id, $acc_id, $acpn, $dv, $period, $date_credited, $package, $doc_type,
            $tranche, $sap, $profiled_m, $profiled_d,
            $amount, $gross, $tax, $hci, $pf, $enlisted, $profiled,
            $gacn_no, $gacn_date, $or_no, $or_date, $hcp_id,
            $id
        ]);
        
        $success_msg = "Receivable record successfully updated.";
    } catch (Exception $e) { $error_msg = "Update Error: " . $e->getMessage(); }
}

// C. Delete Receivable
if ($can_delete && isset($_POST['delete_receivable'])) {
    try {
        $id = $_POST['del_id'];
        $pdo->prepare("DELETE FROM philhealth_receivables WHERE id = ?")->execute([$id]);
        $success_msg = "Record successfully deleted.";
    } catch (Exception $e) { $error_msg = "Delete Error: " . $e->getMessage(); }
}

// D. Reconcile
if ($can_edit && isset($_POST['reconcile_receivable'])) {
    try {
        $rec_id = $_POST['reconcile_id'];
        $inflow_id = $_POST['cash_inflow_id'];
        $pdo->prepare("UPDATE philhealth_receivables SET status = 'reconciled', cash_inflow_id = ? WHERE id = ?")->execute([$inflow_id, $rec_id]);
        $success_msg = "ACPN successfully reconciled with bank deposit.";
    } catch (Exception $e) { $error_msg = "Reconciliation Error: " . $e->getMessage(); }
}

// E. Unreconcile
if ($can_edit && isset($_POST['unreconcile_receivable'])) {
    try {
        $rec_id = $_POST['unreconcile_id'];
        $pdo->prepare("UPDATE philhealth_receivables SET status = 'pending', cash_inflow_id = NULL WHERE id = ?")->execute([$rec_id]);
        $success_msg = "Reconciliation reverted. ACPN is pending again.";
    } catch (Exception $e) { $error_msg = "Error: " . $e->getMessage(); }
}

// F. Add Claim Line (Claims documents only)
if ($can_edit && isset($_POST['add_claim_line'])) {
    try {
        $rec_id = $_POST['cl_receivable_id'];
        
        $c_start = $_POST['cl_conf_start'] ?? '';
        $c_end   = $_POST['cl_conf_end'] ?? '';
        $conf_period = ($c_start && $c_end) ? date('m/d/Y', strtotime($c_start)) . ' to ' . date('m/d/Y', strtotime($c_end)) : '';

        $sql = "INSERT INTO philhealth_claim_lines (receivable_id, pabn_number, series_number, member_pin, patient_name, confinement_period, caserate1_code, caserate1_gross, caserate2_code, caserate2_gross, caserate3_code, caserate3_gross, gross, withholding_tax, hci, pf) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        $pdo->prepare($sql)->execute([
            $rec_id,
            trim($_POST['cl_pabn_number'] ?? ''),
            trim($_POST['cl_series_number'] ?? ''),
            trim($_POST['cl_member_pin'] ?? ''),
            trim($_POST['cl_patient_name'] ?? ''),
            $conf_period,
            trim($_POST['cl_cr1_code'] ?? ''),
            floatval(str_replace(',', '', $_POST['cl_cr1_gross'] ?? '0')),
            trim($_POST['cl_cr2_code'] ?? ''),
            floatval(str_replace(',', '', $_POST['cl_cr2_gross'] ?? '0')),
            trim($_POST['cl_cr3_code'] ?? ''),
            floatval(str_replace(',', '', $_POST['cl_cr3_gross'] ?? '0')),
            floatval(str_replace(',', '', $_POST['cl_gross'] ?? '0')),
            floatval(str_replace(',', '', $_POST['cl_tax'] ?? '0')),
            floatval(str_replace(',', '', $_POST['cl_hci'] ?? '0')),
            floatval(str_replace(',', '', $_POST['cl_pf'] ?? '0')),
        ]);
        
        recalculateParentClaimsTotals($pdo, $rec_id);
        $success_msg = "Claim line added and totals updated.";
    } catch (Exception $e) { $error_msg = "Error adding claim: " . $e->getMessage(); }
}

// Update Claim Line
if ($can_edit && isset($_POST['update_claim_line'])) {
    try {
        $edit_id = $_POST['edit_cl_id'];
        $rec_id = $_POST['cl_receivable_id'];
        
        $c_start = $_POST['cl_conf_start'] ?? '';
        $c_end   = $_POST['cl_conf_end'] ?? '';
        $conf_period = ($c_start && $c_end) ? date('m/d/Y', strtotime($c_start)) . ' to ' . date('m/d/Y', strtotime($c_end)) : '';

        $sql = "UPDATE philhealth_claim_lines SET pabn_number=?, series_number=?, member_pin=?, patient_name=?, confinement_period=?, caserate1_code=?, caserate1_gross=?, caserate2_code=?, caserate2_gross=?, caserate3_code=?, caserate3_gross=?, gross=?, withholding_tax=?, hci=?, pf=? WHERE id=?";
        $pdo->prepare($sql)->execute([
            trim($_POST['cl_pabn_number'] ?? ''),
            trim($_POST['cl_series_number'] ?? ''),
            trim($_POST['cl_member_pin'] ?? ''),
            trim($_POST['cl_patient_name'] ?? ''),
            $conf_period,
            trim($_POST['cl_cr1_code'] ?? ''),
            floatval(str_replace(',', '', $_POST['cl_cr1_gross'] ?? '0')),
            trim($_POST['cl_cr2_code'] ?? ''),
            floatval(str_replace(',', '', $_POST['cl_cr2_gross'] ?? '0')),
            trim($_POST['cl_cr3_code'] ?? ''),
            floatval(str_replace(',', '', $_POST['cl_cr3_gross'] ?? '0')),
            floatval(str_replace(',', '', $_POST['cl_gross'] ?? '0')),
            floatval(str_replace(',', '', $_POST['cl_tax'] ?? '0')),
            floatval(str_replace(',', '', $_POST['cl_hci'] ?? '0')),
            floatval(str_replace(',', '', $_POST['cl_pf'] ?? '0')),
            $edit_id
        ]);
        
        recalculateParentClaimsTotals($pdo, $rec_id);
        $success_msg = "Claim line updated and totals recalculated.";
    } catch (Exception $e) { $error_msg = "Error updating claim: " . $e->getMessage(); }
}

// G. Delete Claim Line
if ($can_edit && isset($_POST['delete_claim_line'])) {
    try {
        $del_id = $_POST['del_cl_id'];
        $rec_id = $pdo->query("SELECT receivable_id FROM philhealth_claim_lines WHERE id = $del_id")->fetchColumn();
        $pdo->prepare("DELETE FROM philhealth_claim_lines WHERE id = ?")->execute([$del_id]);
        
        if ($rec_id) recalculateParentClaimsTotals($pdo, $rec_id);
        $success_msg = "Claim line removed and totals updated.";
    } catch (Exception $e) { $error_msg = "Error: " . $e->getMessage(); }
}

// H. Add Pharmacy Line
if ($can_edit && isset($_POST['add_pharmacy_line'])) {
    try {
        $rec_id = $_POST['ph_receivable_id'];
        $sql = "INSERT INTO philhealth_pharmacy_lines (receivable_id, gacn_number, gacn_date, pabn_number, gross, withholding_tax, net_amount) VALUES (?,?,?,?,?,?,?)";
        $pdo->prepare($sql)->execute([
            $rec_id,
            trim($_POST['ph_gacn_number'] ?? ''),
            !empty($_POST['ph_gacn_date']) ? $_POST['ph_gacn_date'] : null,
            trim($_POST['ph_pabn_number'] ?? ''),
            floatval(str_replace(',', '', $_POST['ph_gross'] ?? '0')),
            floatval(str_replace(',', '', $_POST['ph_tax'] ?? '0')),
            floatval(str_replace(',', '', $_POST['ph_net'] ?? '0'))
        ]);
        recalculateParentPharmacyTotals($pdo, $rec_id);
        $success_msg = "Pharmacy line added and totals updated.";
    } catch (Exception $e) { $error_msg = "Error adding pharmacy line: " . $e->getMessage(); }
}

// I. Delete Pharmacy Line
if ($can_edit && isset($_POST['delete_pharmacy_line'])) {
    try {
        $del_id = $_POST['del_ph_id'];
        $rec_id = $pdo->query("SELECT receivable_id FROM philhealth_pharmacy_lines WHERE id = $del_id")->fetchColumn();
        $pdo->prepare("DELETE FROM philhealth_pharmacy_lines WHERE id = ?")->execute([$del_id]);
        
        if ($rec_id) recalculateParentPharmacyTotals($pdo, $rec_id);
        $success_msg = "Pharmacy line removed and totals updated.";
    } catch (Exception $e) { $error_msg = "Error: " . $e->getMessage(); }
}

// H. Update OR Details (fill-in later)
if ($can_edit && isset($_POST['update_or_details'])) {
    try {
        $id = $_POST['or_rec_id'];
        $or_no = !empty($_POST['fill_or_number']) ? trim($_POST['fill_or_number']) : null;
        $or_date = !empty($_POST['fill_or_date']) ? $_POST['fill_or_date'] : null;
        $pdo->prepare("UPDATE philhealth_receivables SET or_number=?, or_date=? WHERE id=?")->execute([$or_no, $or_date, $id]);
        $success_msg = "OR details updated.";
    } catch (Exception $e) { $error_msg = "Error: " . $e->getMessage(); }
}

// ---------------------------------------------------------
// 4. FETCH DATA & FILTERS
// ---------------------------------------------------------
$f_status = $_GET['status'] ?? 'pending'; 
$f_fac    = $_GET['facility_id'] ?? 'all';
$f_start  = $_GET['start_date'] ?? date('Y-01-01');
$f_end    = $_GET['end_date'] ?? date('Y-12-31');

$facilities = $pdo->query("SELECT * FROM facilities WHERE status = 'active' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$accreditations = $pdo->query("SELECT * FROM facility_accreditations ORDER BY package_type ASC")->fetchAll(PDO::FETCH_ASSOC);

$all_hcps = $pdo->query("SELECT MIN(h.id) as id, h.hcp_name, fa.facility_id FROM accreditation_hcps h JOIN facility_accreditations fa ON h.accreditation_id = fa.id GROUP BY h.hcp_name, fa.facility_id ORDER BY h.hcp_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$bank_inflows = $pdo->query("
    SELECT ci.id, ci.date_received, ci.amount, ci.reference_no, ba.account_name 
    FROM cash_inflows ci 
    JOIN bank_accounts ba ON ci.bank_account_id = ba.id 
    ORDER BY ci.date_received DESC LIMIT 200
")->fetchAll(PDO::FETCH_ASSOC);

$where_clauses = ["pr.status = ?"];
$params = [$f_status];

if ($f_status === 'reconciled') {
    $where_clauses[] = "DATE(ci.date_received) BETWEEN ? AND ?";
} else {
    $where_clauses[] = "DATE(pr.created_at) BETWEEN ? AND ?";
}
$params[] = $f_start;
$params[] = $f_end;

if ($f_fac !== 'all') {
    $where_clauses[] = "pr.facility_id = ?";
    $params[] = $f_fac;
}
$where_sql = implode(" AND ", $where_clauses);

$order_by = ($f_status === 'reconciled') ? "ci.date_received DESC, pr.created_at DESC" : "pr.created_at DESC";

$sql_main = "SELECT pr.*, f.name as facility_name, fa.accreditation_no, fa.package_type as acc_package_type,
             ci.date_received as bank_date, ci.amount as bank_amount, ba.account_name as bank_name,
             hcp.hcp_name as hcp_name_display
             FROM philhealth_receivables pr 
             LEFT JOIN facilities f ON pr.facility_id = f.id 
             LEFT JOIN facility_accreditations fa ON pr.accreditation_id = fa.id 
             LEFT JOIN cash_inflows ci ON pr.cash_inflow_id = ci.id 
             LEFT JOIN bank_accounts ba ON ci.bank_account_id = ba.id
             LEFT JOIN accreditation_hcps hcp ON pr.hcp_id = hcp.id
             WHERE $where_sql
             ORDER BY $order_by";

$stmt = $pdo->prepare($sql_main);
$stmt->execute($params);
$receivables = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch claim lines and pharmacy lines
$claim_lines = [];
$cl_stmt = $pdo->query("SELECT * FROM philhealth_claim_lines ORDER BY id ASC");
while ($cl = $cl_stmt->fetch(PDO::FETCH_ASSOC)) {
    $claim_lines[$cl['receivable_id']][] = $cl;
}
$pharmacy_lines = [];
$ph_stmt = $pdo->query("SELECT * FROM philhealth_pharmacy_lines ORDER BY gacn_date ASC, id ASC");
while ($ph = $ph_stmt->fetch(PDO::FETCH_ASSOC)) {
    $pharmacy_lines[$ph['receivable_id']][] = $ph;
}
foreach ($receivables as &$r) {
    $r['claim_lines'] = $claim_lines[$r['id']] ?? [];
    $r['pharmacy_lines'] = $pharmacy_lines[$r['id']] ?? [];
}
unset($r);

$pending_total = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM philhealth_receivables WHERE status = 'pending'")->fetchColumn();
$reconciled_total = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM philhealth_receivables WHERE status = 'reconciled'")->fetchColumn();

// ---------------------------------------------------------
// 5. EXCEL EXPORT LOGIC
// ---------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] == 'excel') {
    $filename = "PhilHealth_SubLedger_" . strtoupper($f_status) . "_" . date('Ymd') . ".xls";
    if (ob_get_length()) ob_clean();
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    echo "<html xmlns:o='urn:schemas-microsoft-com:office:office' xmlns:x='urn:schemas-microsoft-com:office:excel' xmlns='http://www.w3.org/TR/REC-html40'>";
    echo "<head><meta http-equiv='Content-type' content='text/html;charset=utf-8'></head><body>";
    echo "<table border='1' style='font-family: Arial, sans-serif; font-size: 10pt;'>";
    echo "<tr><th colspan='15' style='font-size: 14pt; text-align: center;'>PhilHealth Receivables Sub-Ledger (" . strtoupper($f_status) . ")</th></tr>";
    echo "<tr><th colspan='15' style='text-align: center;'>Period: $f_start to $f_end</th></tr>";
    echo "<tr></tr><tr>";
    echo "<th style='background-color:#212529; color:#ffffff;'>HCI / Facility</th>";
    echo "<th style='background-color:#212529; color:#ffffff;'>Package</th>";
    echo "<th style='background-color:#212529; color:#ffffff;'>Type</th>";
    echo "<th style='background-color:#212529; color:#ffffff;'>ACPN / GACN</th>";
    echo "<th style='background-color:#212529; color:#ffffff;'>Date Credited</th>";
    echo "<th style='background-color:#212529; color:#ffffff;'>Period Covered</th>";
    echo "<th style='background-color:#0d6efd; color:#ffffff;'>PABN / Series</th>";
    echo "<th style='background-color:#0d6efd; color:#ffffff;'>Member PIN</th>";
    echo "<th style='background-color:#0d6efd; color:#ffffff;'>Patient Name</th>";
    echo "<th style='background-color:#0d6efd; color:#ffffff;'>Conf. Period</th>";
    echo "<th style='background-color:#0d6efd; color:#ffffff;'>Case Rates</th>";
    echo "<th style='background-color:#212529; color:#ffffff;'>Gross Amount</th>";
    echo "<th style='background-color:#212529; color:#ffffff;'>Tax / Deductions</th>";
    echo "<th style='background-color:#212529; color:#ffffff;'>Net Amount</th>";
    echo "<th style='background-color:#212529; color:#ffffff;'>OR No.</th>";
    if ($f_status == 'reconciled') {
        echo "<th style='background-color:#198754; color:#ffffff;'>GL Bank Account</th>";
        echo "<th style='background-color:#198754; color:#ffffff;'>GL Credit Date</th>";
        echo "<th style='background-color:#198754; color:#ffffff;'>GL Deposit Total</th>";
    }
    echo "</tr>";

    foreach($receivables as $r) {
        $tax = $r['withholding_tax'] > 0 ? $r['withholding_tax'] : 0;
        $raw_period = trim($r['period_covered']);
        $excel_period = htmlspecialchars($raw_period);
        $clean_period = str_replace('-', ' ', $raw_period);
        if (preg_match('/^([a-zA-Z]+)\s+(\d{2,4})$/', $clean_period, $matches)) {
            $month = $matches[1];
            $year = $matches[2];
            if (strlen($year) == 2) $year = "20" . $year;
            $ts = strtotime("1 $month $year");
            if ($ts) { $excel_period = date('m/01/Y', $ts) . " to " . date('m/t/Y', $ts); }
        }

        $type_label = strtoupper(substr($r['document_type'], 0, 3));
        $identifier = $r['document_type'] === 'pharmacy' ? $r['gacn_number'] : $r['acpn_number'];

        $base_row = [
            "<td>" . htmlspecialchars($r['facility_name']) . "</td>",
            "<td>" . htmlspecialchars($r['package_category']) . "</td>",
            "<td>" . $type_label . "</td>",
            "<td style='mso-number-format:\"\\@\";'>" . htmlspecialchars($identifier ?? '') . "</td>",
            "<td style='mso-number-format:\"\\@\"; text-align: center;'>" . ($r['date_credited'] ? date('m/d/Y', strtotime($r['date_credited'])) : '') . "</td>",
            "<td style='mso-number-format:\"\\@\"; text-align: center;'>" . $excel_period . "</td>",
        ];

        if ($r['document_type'] === 'claims' && !empty($r['claim_lines'])) {
            foreach($r['claim_lines'] as $cl) {
                $pin = $cl['member_pin'] ?? '';
                if (strlen($pin) > 4) {
                    $pin = str_repeat('*', strlen($pin) - 4) . substr($pin, -4);
                }
                
                $pname = $cl['patient_name'] ?? '';
                $parts = explode(',', $pname);
                if (count($parts) >= 2) {
                    $surname = trim($parts[0]);
                    $firstname = trim($parts[1]);
                    $redacted_surname = substr($surname, 0, 1) . str_repeat('*', max(0, strlen($surname) - 1));
                    $redacted_firstname = substr($firstname, 0, 1) . str_repeat('*', max(0, strlen($firstname) - 1));
                    $pname = $redacted_surname . ', ' . $redacted_firstname;
                }
                
                $case_rates = array_filter([$cl['caserate1_code'], $cl['caserate2_code'], $cl['caserate3_code']]);
                
                echo "<tr>";
                echo implode("", $base_row);
                echo "<td style='mso-number-format:\"\\@\";'>" . htmlspecialchars($cl['pabn_number']) . " / " . htmlspecialchars($cl['series_number']) . "</td>";
                echo "<td style='mso-number-format:\"\\@\";'>" . htmlspecialchars($pin) . "</td>";
                echo "<td>" . htmlspecialchars($pname) . "</td>";
                echo "<td>" . htmlspecialchars($cl['confinement_period'] ?? '') . "</td>";
                echo "<td>" . htmlspecialchars(implode(', ', $case_rates)) . "</td>";
                
                $cl_net = $cl['gross'] - $cl['withholding_tax'];
                echo "<td style='text-align: right;'>" . number_format($cl['gross'], 2) . "</td>";
                echo "<td style='text-align: right;'>" . number_format($cl['withholding_tax'], 2) . "</td>";
                echo "<td style='text-align: right; font-weight: bold;'>" . number_format($cl_net, 2) . "</td>";
                echo "<td style='mso-number-format:\"\\@\";'>" . htmlspecialchars($r['or_number'] ?? '') . "</td>";
                
                if ($f_status == 'reconciled') {
                    echo "<td>" . htmlspecialchars($r['bank_name']) . "</td>";
                    echo "<td style='text-align: center; mso-number-format:\"\\@\";'>" . date('m/d/Y', strtotime($r['bank_date'])) . "</td>";
                    echo "<td style='text-align: right; color: #198754; font-weight: bold;'>" . number_format($r['bank_amount'], 2) . "</td>";
                }
                echo "</tr>";
            }
        } else {
            echo "<tr>";
            echo implode("", $base_row);
            echo "<td></td><td></td><td></td><td></td><td></td>";
            echo "<td style='text-align: right;'>" . number_format($r['gross_amount'], 2) . "</td>";
            echo "<td style='text-align: right;'>" . number_format($tax, 2) . "</td>";
            echo "<td style='text-align: right; font-weight: bold;'>" . number_format($r['amount'], 2) . "</td>";
            echo "<td style='mso-number-format:\"\\@\";'>" . htmlspecialchars($r['or_number'] ?? '') . "</td>";
            if ($f_status == 'reconciled') {
                echo "<td>" . htmlspecialchars($r['bank_name']) . "</td>";
                echo "<td style='text-align: center; mso-number-format:\"\\@\";'>" . date('m/d/Y', strtotime($r['bank_date'])) . "</td>";
                echo "<td style='text-align: right; color: #198754; font-weight: bold;'>" . number_format($r['bank_amount'], 2) . "</td>";
            }
            echo "</tr>";
        }
    }
    echo "</table></body></html>";
    exit;
}

// ---------------------------------------------------------
// 6. RENDER UI
// ---------------------------------------------------------
$page_title = 'PhilHealth Receivables';
require 'includes/header.php';
?>

<div class="container-fluid mt-4 px-4">
    <!-- Header/Summary Cards/Filters stay identical -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex align-items-center gap-2">
            <h4 class="fw-bold text-dark mb-0">PhilHealth Receivables (ACPN)</h4>
            <span class="badge bg-primary">Sub-Ledger</span>
        </div>
        <?php if($can_edit): ?>
        <button class="btn btn-primary fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#addReceivableModal">
            <i class="bi bi-plus-circle me-1"></i> Encode Document
        </button>
        <?php endif; ?>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100 border-start border-4 border-warning">
                <div class="card-body p-3">
                    <div class="small text-muted text-uppercase fw-bold"><i class="bi bi-hourglass-split me-2"></i>Total Pending (Floating)</div>
                    <h4 class="mb-0 fw-bold mt-2 text-warning">₱ <?php echo number_format($pending_total, 2); ?></h4>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100 border-start border-4 border-success">
                <div class="card-body p-3">
                    <div class="small text-muted text-uppercase fw-bold"><i class="bi bi-check-circle-fill me-2"></i>Total Reconciled in Bank</div>
                    <h4 class="mb-0 fw-bold mt-2 text-success">₱ <?php echo number_format($reconciled_total, 2); ?></h4>
                </div>
            </div>
        </div>
    </div>

    <?php if ($success_msg): ?><div class="alert alert-success alert-dismissible fade show"><?php echo $success_msg; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
    <?php if ($error_msg): ?><div class="alert alert-danger"><?php echo $error_msg; ?></div><?php endif; ?>

    <div class="card shadow border-0 mb-5">
        <div class="card-header bg-white py-3">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <ul class="nav nav-pills card-header-pills mb-0">
                    <li class="nav-item">
                        <a class="nav-link fw-bold <?php echo $f_status == 'pending' ? 'active bg-warning text-dark' : 'text-muted'; ?>" href="?status=pending&facility_id=<?php echo $f_fac; ?>&start_date=<?php echo $f_start; ?>&end_date=<?php echo $f_end; ?>">Pending Documents</a>
                    </li>
                    <li class="nav-item ms-2">
                        <a class="nav-link fw-bold <?php echo $f_status == 'reconciled' ? 'active bg-success text-white' : 'text-muted'; ?>" href="?status=reconciled&facility_id=<?php echo $f_fac; ?>&start_date=<?php echo $f_start; ?>&end_date=<?php echo $f_end; ?>">Reconciled with Bank</a>
                    </li>
                </ul>
                <form method="GET" class="d-flex flex-wrap align-items-center gap-2 m-0">
                    <input type="hidden" name="status" value="<?php echo htmlspecialchars($f_status); ?>">
                    <input type="date" name="start_date" class="form-control form-control-sm border-secondary" style="max-width: 130px;" value="<?php echo $f_start; ?>">
                    <span class="text-muted small">to</span>
                    <input type="date" name="end_date" class="form-control form-control-sm border-secondary" style="max-width: 130px;" value="<?php echo $f_end; ?>">
                    <select name="facility_id" class="form-select form-select-sm border-secondary" style="width: 180px;">
                        <option value="all">All Facilities</option>
                        <?php foreach($facilities as $fac): ?>
                            <option value="<?php echo $fac['id']; ?>" <?php echo $f_fac == $fac['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($fac['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-sm btn-dark fw-bold"><i class="bi bi-funnel"></i> Filter</button>
                    <button type="submit" name="export" value="excel" class="btn btn-sm btn-success fw-bold"><i class="bi bi-file-earmark-excel"></i> Export</button>
                </form>
            </div>
        </div>
        
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">HCI / Package</th>
                            <th>Identifiers</th>
                            <th>Details / Breakdown</th>
                            <th class="text-end">Net Amount</th>
                            <th class="text-center" style="width: 18%">Status / GL Link</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(count($receivables) > 0): foreach($receivables as $r): 
                            $json_data = htmlspecialchars(json_encode($r), ENT_QUOTES, 'UTF-8');
                            $doc_badge = ['capitation' => 'bg-info text-dark', 'claims' => 'bg-warning text-dark', 'pharmacy' => 'bg-secondary text-white'];
                            $doc_label = ['capitation' => 'CAP', 'claims' => 'CLM', 'pharmacy' => 'RX'];
                            $dtype = $r['document_type'] ?? 'capitation';
                        ?>
                        <tr>
                            <td class="ps-3">
                                <div class="fw-bold text-primary"><i class="bi bi-hospital me-1"></i><?php echo htmlspecialchars($r['facility_name']); ?></div>
                                <div class="d-flex gap-1 mt-1 flex-wrap">
                                    <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($r['package_category']); ?></span>
                                    <span class="badge <?php echo $doc_badge[$dtype] ?? 'bg-light'; ?>"><?php echo $doc_label[$dtype] ?? '?'; ?></span>
                                </div>
                                <?php if($r['accreditation_no']): ?>
                                    <div class="text-muted mt-1" style="font-size: 0.75rem;">Acc: <?php echo htmlspecialchars($r['accreditation_no']); ?></div>
                                <?php endif; ?>
                                <?php if(!empty($r['hcp_name_display'])): ?>
                                    <div class="text-muted mt-1" style="font-size: 0.75rem;"><i class="bi bi-person-badge me-1"></i><?php echo htmlspecialchars($r['hcp_name_display']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if($dtype === 'capitation'): ?>
                                    <div><span class="text-muted">ACPN:</span> <strong class="font-monospace"><?php echo htmlspecialchars($r['acpn_number']); ?></strong></div>
                                    <?php if($r['dv_number']): ?><div><span class="text-muted">DV No:</span> <strong><?php echo htmlspecialchars($r['dv_number']); ?></strong></div><?php endif; ?>
                                    <?php if($r['sap_number']): ?><div><span class="text-muted">SAP:</span> <strong><?php echo htmlspecialchars($r['sap_number']); ?></strong></div><?php endif; ?>
                                    <?php if($r['tranche_type']): ?><div><span class="badge bg-primary bg-opacity-10 text-primary border"><?php echo htmlspecialchars($r['tranche_type']); ?></span></div><?php endif; ?>
                                <?php elseif($dtype === 'claims'): ?>
                                    <?php if(!empty($r['claim_lines'])): ?>
                                        <div class="mt-1"><span class="badge bg-warning text-dark"><?php echo count($r['claim_lines']); ?> Claim(s)</span></div>
                                    <?php else: ?>
                                        <span class="text-muted fst-italic">No claim lines</span>
                                    <?php endif; ?>
                                <?php elseif($dtype === 'pharmacy'): ?>
                                    <?php if($r['gacn_number']): ?><div><span class="text-muted">GACN:</span> <strong class="font-monospace"><?php echo htmlspecialchars($r['gacn_number']); ?></strong></div><?php endif; ?>
                                    <div><span class="text-muted">PABN:</span> <strong class="font-monospace"><?php echo htmlspecialchars($r['acpn_number']); ?></strong></div>
                                    <?php if($r['gacn_date']): ?><div><span class="text-muted">GACN Date:</span> <strong><?php echo date('M d, Y', strtotime($r['gacn_date'])); ?></strong></div><?php endif; ?>
                                <?php endif; ?>
                                <?php if($r['date_credited']): ?><div><span class="text-muted">Credited:</span> <strong><?php echo date('M d, Y', strtotime($r['date_credited'])); ?></strong></div><?php endif; ?>
                                <?php if($r['period_covered']): ?><div><span class="text-muted">Period:</span> <strong style="font-size: 0.75rem;"><?php echo htmlspecialchars($r['period_covered']); ?></strong></div><?php endif; ?>
                                <?php if(!empty($r['or_number'])): ?>
                                    <div class="mt-1 text-success" style="font-size: 0.75rem;"><i class="bi bi-receipt me-1"></i>OR: <?php echo htmlspecialchars($r['or_number']); ?><?php if(!empty($r['or_date'])): ?> (<?php echo date('m/d/Y', strtotime($r['or_date'])); ?>)<?php endif; ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if($dtype === 'capitation'): ?>
                                    <div class="small">
                                        <div><span class="text-muted">Enlisted:</span> <span class="fw-bold"><?php echo $r['enlisted_count'] ?: '0'; ?></span></div>
                                        <div><span class="text-muted">Members:</span> <span class="fw-bold"><?php echo $r['profiled_members'] ?: '0'; ?></span></div>
                                        <div><span class="text-muted">Dependents:</span> <span class="fw-bold"><?php echo $r['profiled_dependents'] ?: '0'; ?></span></div>
                                        <div><span class="text-muted">Total Profiled:</span> <span class="fw-bold text-primary"><?php echo ($r['profiled_members'] ?? 0) + ($r['profiled_dependents'] ?? 0); ?></span></div>
                                    </div>
                                <?php elseif($dtype === 'claims'): ?>
                                    <div class="small" style="font-size: 0.75rem;">
                                        <div><span class="text-muted">Gross:</span> ₱ <?php echo number_format($r['gross_amount'], 2); ?></div>
                                        <div><span class="text-muted">Tax:</span> <span class="text-danger">₱ <?php echo number_format($r['withholding_tax'], 2); ?></span></div>
                                        <div><span class="text-muted">HCI/PF:</span> ₱ <?php echo number_format($r['hci_share'], 2); ?> / ₱ <?php echo number_format($r['pf_share'], 2); ?></div>
                                    </div>
                                    <?php if(!empty($r['claim_lines'])): ?>
                                        <button class="btn btn-sm btn-outline-primary border-0 py-0 mt-1" onclick='viewClaimLines(<?php echo $json_data; ?>)'>
                                            <i class="bi bi-list-ul me-1"></i>View Claims
                                        </button>
                                    <?php endif; ?>
                                <?php elseif($dtype === 'pharmacy'): ?>
                                    <div class="small" style="font-size: 0.75rem;">
                                        <div><span class="text-muted">Gross:</span> ₱ <?php echo number_format($r['gross_amount'], 2); ?></div>
                                        <div><span class="text-muted">Tax:</span> <span class="text-danger">₱ <?php echo number_format($r['withholding_tax'], 2); ?></span></div>
                                        <div><span class="text-muted">Net:</span> <span class="text-success">₱ <?php echo number_format($r['amount'], 2); ?></span></div>
                                    </div>
                                    <?php if(!empty($r['pharmacy_lines'])): ?>
                                        <button class="btn btn-sm btn-outline-secondary border-0 py-0 mt-1" onclick='viewPharmacyLines(<?php echo $json_data; ?>)'>
                                            <i class="bi bi-list-ul me-1"></i>View GACN Lines
                                        </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-end fw-bold text-success fs-6">₱ <?php echo number_format($r['amount'], 2); ?></td>
                            <td class="text-center">
                                <?php if($r['status'] == 'pending'): ?>
                                    <span class="badge bg-warning text-dark mb-2 w-100 py-2"><i class="bi bi-hourglass-split me-1"></i>Pending Bank</span><br>
                                    <?php if($can_edit): ?>
                                    <div class="d-flex justify-content-center gap-1 flex-wrap">
                                        <button class="btn btn-sm btn-success py-0" onclick='openReconcile(<?php echo $json_data; ?>)' title="Reconcile with Bank Deposit"><i class="bi bi-link-45deg"></i> Link</button>
                                        <button class="btn btn-sm btn-outline-dark py-0" onclick='openEdit(<?php echo $json_data; ?>)'><i class="bi bi-pencil"></i></button>
                                        <?php if(empty($r['or_number'])): ?>
                                        <button class="btn btn-sm btn-outline-success py-0" onclick='openOrFill(<?php echo $r["id"]; ?>)' title="Fill OR Details"><i class="bi bi-receipt"></i></button>
                                        <?php endif; ?>
                                        <?php if($can_delete): ?>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this record?');">
                                            <input type="hidden" name="del_id" value="<?php echo $r['id']; ?>">
                                            <button type="submit" name="delete_receivable" class="btn btn-sm btn-outline-danger py-0 border-0"><i class="bi bi-trash"></i></button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge bg-success mb-1 w-100 py-1"><i class="bi bi-check-circle me-1"></i>Reconciled</span>
                                    <div class="text-start bg-light border rounded p-2 mt-1" style="font-size: 0.7rem; line-height: 1.3;">
                                        <div class="text-truncate fw-bold text-dark mb-1" title="<?php echo htmlspecialchars($r['bank_name']); ?>"><i class="bi bi-bank me-1 text-success"></i><?php echo htmlspecialchars($r['bank_name']); ?></div>
                                        <div class="text-muted mb-1">Credited: <strong><?php echo date('M d, Y', strtotime($r['bank_date'])); ?></strong></div>
                                        <div class="text-muted">GL Total: <strong class="text-success">₱ <?php echo number_format($r['bank_amount'], 2); ?></strong></div>
                                    </div>
                                    <?php if($can_edit): ?>
                                        <form method="POST" class="mt-1" onsubmit="return confirm('Unlink this record from the bank deposit?');">
                                            <input type="hidden" name="unreconcile_id" value="<?php echo $r['id']; ?>">
                                            <button type="submit" name="unreconcile_receivable" class="btn btn-link btn-sm text-danger p-0" style="font-size: 0.75rem;">Unlink Document</button>
                                        </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; else: ?>
                        <tr><td colspan="5" class="text-center py-5 text-muted">No <?php echo $f_status; ?> documents found matching the filter.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- ADD RECEIVABLE MODAL (2-Panel Layout) -->
<!-- ============================================================ -->
<?php if($can_edit): ?>
<style>
    .modal-xl-max { max-width: 96% !important; }
    .transition-all { transition: all 0.3s ease; }
    /* Sleek scrollbar for claim lines panel */
    #addClaimLinesBody::-webkit-scrollbar, #claimLinesBody::-webkit-scrollbar { width: 6px; }
    #addClaimLinesBody::-webkit-scrollbar-thumb, #claimLinesBody::-webkit-scrollbar-thumb { background-color: #dee2e6; border-radius: 4px; }
    #addClaimLinesBody::-webkit-scrollbar-thumb:hover, #claimLinesBody::-webkit-scrollbar-thumb:hover { background-color: #adb5bd; }
</style>
<div class="modal fade" id="addReceivableModal" tabindex="-1">
    <div class="modal-dialog modal-xl-max">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-file-earmark-plus me-2"></i>Encode PhilHealth Document</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form method="POST" id="addForm">
                    <div class="row">
                        <!-- LEFT PANEL -->
                        <div class="col-lg-12 transition-all" id="add_left_panel">
                            <!-- Facility & Accreditation -->
                            <div class="row g-2 mb-3 border-bottom pb-3">
                                <div class="col-md-6">
                                    <label class="small fw-bold text-muted">HCI / Facility</label>
                                    <select name="facility_id" id="add_fac" class="form-select fw-bold" required>
                                        <option value="" disabled selected>Select Facility...</option>
                                        <?php foreach($facilities as $fac): ?>
                                            <option value="<?php echo $fac['id']; ?>"><?php echo htmlspecialchars($fac['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="small fw-bold text-muted">Accreditation No.</label>
                                    <select name="accreditation_id" id="add_acc" class="form-select border-secondary" disabled required>
                                        <option value="">- Select Facility First -</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="small fw-bold text-muted">Package Category</label>
                                    <input type="text" name="package_category" id="add_pkg" class="form-control bg-light" readonly required placeholder="Auto-filled">
                                </div>
                                <div class="col-md-6">
                                    <label class="small fw-bold text-muted">Document Type</label>
                                    <input type="text" id="add_doc_type_display" class="form-control bg-light text-uppercase fw-bold" readonly placeholder="Auto-detected">
                                </div>
                            </div>

                            <!-- Shared Document Info -->
                            <div class="row g-2 mb-3 pb-3 border-bottom" id="add_shared_doc_info" style="display:none;">
                                <div class="col-md-12 mb-1">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <label class="small fw-bold text-primary mb-0">ACPN / Tracking Number</label>
                                        <button type="button" class="btn btn-sm py-0 btn-link text-decoration-none" onclick="generateACPNFrontend('add')"><i class="bi bi-magic"></i> Generate Code</button>
                                    </div>
                                    <input type="text" name="acpn_number" id="add_acpn" class="form-control border-primary fw-bold font-monospace" placeholder="Leave blank to auto-generate (e.g. CAP-LHHC-092026-001)">
                                </div>
                                <div class="col-md-6">
                                    <label class="small fw-bold text-muted">Date Credited (ACPN/Notice)</label>
                                    <input type="date" name="date_credited" id="add_date_credited" class="form-control">
                                </div>
                                <div class="col-md-6">
                                    <label class="small fw-bold text-muted">Period Covered</label>
                                    <div class="input-group">
                                        <input type="date" name="period_start" class="form-control">
                                        <span class="input-group-text bg-light border-0">to</span>
                                        <input type="date" name="period_end" class="form-control">
                                    </div>
                                    <input type="text" name="period_covered" class="form-control form-control-sm mt-1" placeholder="Or enter manually (e.g. Jan-Mar 2026)">
                                </div>
                            </div>

                            <!-- CAPITATION -->
                            <div id="add_capitation_fields" style="display:none;">
                                <div class="row g-2 mb-3 pb-3 border-bottom bg-light rounded p-2">
                                    <div class="col-12"><h6 class="fw-bold text-info mb-1"><i class="bi bi-cash-stack me-2"></i>Capitation Details</h6></div>
                                    <div class="col-md-6">
                                        <label class="small fw-bold text-muted">Tranche Type</label>
                                        <select name="tranche_type" class="form-select">
                                            <option value="">- Select -</option>
                                            <option value="Tranche 1">Tranche 1</option>
                                            <option value="Tranche 2">Tranche 2</option>
                                            <option value="Adjustment">Adjustment</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6" id="add_dv_container">
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <label class="small fw-bold text-muted mb-0">DV Number</label>
                                            <button type="button" class="btn btn-sm py-0 btn-link text-decoration-none" title="Add another DV Number field" onclick="addExtraInput('add_dv_container', 'dv_number[]', 'DV Number')"><i class="bi bi-plus"></i> Add</button>
                                        </div>
                                        <input type="text" name="dv_number[]" class="form-control font-monospace mb-1">
                                    </div>
                                    <div class="col-md-6" id="add_sap_container">
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <label class="small fw-bold text-muted mb-0">SAP Number</label>
                                            <button type="button" class="btn btn-sm py-0 btn-link text-decoration-none" title="Add another SAP Number field" onclick="addExtraInput('add_sap_container', 'sap_number[]', 'SAP Number')"><i class="bi bi-plus"></i> Add</button>
                                        </div>
                                        <input type="text" name="sap_number[]" class="form-control font-monospace mb-1">
                                    </div>
                                    
                                    <div class="col-12 mt-3"><h6 class="fw-bold text-info mb-1"><i class="bi bi-people me-2"></i>Profiled Count</h6></div>
                                    <div class="col-md-3">
                                        <label class="small fw-bold text-muted">Enlisted</label>
                                        <input type="number" name="enlisted_count" class="form-control text-center fw-bold">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="small fw-bold text-muted">Members</label>
                                        <input type="number" name="profiled_members" class="form-control text-center fw-bold" oninput="calcProfiledTotal('add')">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="small fw-bold text-muted">Dependents</label>
                                        <input type="number" name="profiled_dependents" class="form-control text-center fw-bold" oninput="calcProfiledTotal('add')">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="small fw-bold text-primary">Total</label>
                                        <input type="text" id="add_profiled_total" class="form-control text-center fw-bold bg-white text-primary" readonly value="0">
                                    </div>
                                </div>
                            </div>

                            <!-- CLAIMS SUMMARY -->
                            <div id="add_claims_fields" style="display:none;">
                                <div class="row g-2 mb-3 pb-3 border-bottom bg-light rounded p-3">
                                    <div class="col-12"><h6 class="fw-bold text-warning mb-2"><i class="bi bi-clipboard2-pulse me-2"></i>Claims Summary</h6></div>
                                    <div class="col-md-12 mb-2">
                                        <label class="small fw-bold text-muted">HC Professional</label>
                                        <select name="hcp_id" id="add_hcp" class="form-select"><option value="">- Select -</option></select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="small fw-bold text-muted">Total Gross (Auto)</label>
                                        <input type="text" name="gross_amount" id="add_total_gross" class="form-control bg-white" placeholder="0.00" readonly>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="small fw-bold text-danger">Withholding Tax (Auto)</label>
                                        <input type="text" name="withholding_tax" id="add_total_tax" class="form-control border-danger text-danger bg-white" placeholder="0.00" readonly>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="small fw-bold text-muted">Total HCI Share (Auto)</label>
                                        <input type="text" name="hci_share" id="add_total_hci" class="form-control bg-white" placeholder="0.00" readonly>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="small fw-bold text-muted">Total PF Share (Auto)</label>
                                        <input type="text" name="pf_share" id="add_total_pf" class="form-control bg-white" placeholder="0.00" readonly>
                                    </div>
                                </div>
                            </div>

                            <!-- PHARMACY -->
                            <!-- PHARMACY SUMMARY -->
                            <div id="add_pharmacy_fields" style="display:none;">
                                <div class="row g-2 mb-3 pb-3 border-bottom bg-light rounded p-3">
                                    <div class="col-12"><h6 class="fw-bold text-secondary mb-2"><i class="bi bi-capsule me-2"></i>Pharmacy Summary</h6></div>
                                    <div class="col-md-12">
                                        <label class="small fw-bold text-muted">Total Gross (Auto)</label>
                                        <input type="text" name="gross_amount" id="add_ph_total_gross" class="form-control bg-white" placeholder="0.00" readonly>
                                    </div>
                                    <div class="col-md-12">
                                        <label class="small fw-bold text-danger">Total Withholding Tax (Auto)</label>
                                        <input type="text" name="withholding_tax" id="add_ph_total_tax" class="form-control border-danger text-danger bg-white" placeholder="0.00" readonly>
                                    </div>
                                    <div class="col-md-12">
                                        <label class="small fw-bold text-success">Total Net Amount (HCI Total)</label>
                                        <input type="text" name="net_amount" id="add_ph_total_net" class="form-control border-success text-success bg-white" placeholder="0.00" readonly>
                                    </div>
                                </div>
                            </div>
                        </div> <!-- End Left Panel -->

                        <!-- RIGHT PANEL: CLAIM LINES & PHARMACY LINES -->
                        <div class="col-lg-8 ps-lg-4" id="add_right_panel" style="display:none;">
                            <div class="h-100 border rounded p-3 bg-white d-flex flex-column shadow-sm" id="add_claim_lines_section" style="display:none;">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h6 class="fw-bold text-warning mb-0"><i class="bi bi-list-columns-reverse me-2"></i>Patient Claim Lines <span id="add_claim_count" class="badge bg-warning text-dark ms-2 rounded-pill">0</span></h6>
                                    <button type="button" class="btn btn-sm btn-outline-success" onclick="addClaimLineRow('add')"><i class="bi bi-plus-lg me-1"></i>Add Patient Line</button>
                                </div>
                                <div class="flex-grow-1 pe-2" style="max-height: 550px; overflow-y: auto; overflow-x: hidden;" id="addClaimLinesBody">
                                    <!-- Cards added via JS -->
                                </div>
                            </div>
                            
                            <div class="h-100 border rounded p-3 bg-white d-flex flex-column shadow-sm" id="add_pharmacy_lines_section" style="display:none;">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h6 class="fw-bold text-secondary mb-0"><i class="bi bi-capsule me-2"></i>Pharmacy GACN Lines <span id="add_pharmacy_count" class="badge bg-secondary text-white ms-2 rounded-pill">0</span></h6>
                                    <button type="button" class="btn btn-sm btn-outline-success" onclick="addPharmacyLineRow('add')"><i class="bi bi-plus-lg me-1"></i>Add Pharmacy Line</button>
                                </div>
                                <div class="flex-grow-1 pe-2" style="max-height: 550px; overflow-y: auto; overflow-x: hidden;" id="addPharmacyLinesBody">
                                    <!-- Cards added via JS -->
                                </div>
                            </div>
                        </div> <!-- End Right Panel -->
                    </div> <!-- End Row 2-panel -->

                    <!-- BOTTOM CENTER -->
                    <div class="row justify-content-center mt-3 pt-3 border-top">
                        <div class="col-md-8 col-lg-6 text-center">
                            <!-- OR Fields -->
                            <div class="row g-2 mb-3" id="add_or_section" style="display:none;">
                                <div class="col-md-6">
                                    <label class="small fw-bold text-success">Official Receipt No.</label>
                                    <input type="text" name="or_number" class="form-control border-success text-center">
                                </div>
                                <div class="col-md-6">
                                    <label class="small fw-bold text-success">OR Date</label>
                                    <input type="date" name="or_date" class="form-control border-success text-center">
                                </div>
                            </div>
                            
                            <!-- Net Amount -->
                            <div class="mb-3" id="add_amount_section" style="display:none;">
                                <label class="small fw-bold text-success text-uppercase">Final Net Amount (Hits Bank)</label>
                                <div class="input-group input-group-lg justify-content-center">
                                    <span class="input-group-text bg-success text-white fw-bold">₱</span>
                                    <input type="text" name="amount" class="form-control fw-bold text-success fs-4 text-center" style="max-width: 300px;" required placeholder="0.00" oninput="formatAmount(this)">
                                </div>
                            </div>
                            
                            <button type="submit" name="add_receivable" class="btn btn-primary w-100 fw-bold py-2 mt-2 shadow">Save Document</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- EDIT RECEIVABLE MODAL (2-Panel Layout) -->
<!-- ============================================================ -->
<div class="modal fade" id="editReceivableModal" tabindex="-1">
    <div class="modal-dialog modal-xl-max">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i>Edit Document</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form method="POST" id="editForm">
                    <input type="hidden" name="edit_id" id="edit_id">
                    
                    <div class="row">
                        <!-- LEFT PANEL -->
                        <div class="col-lg-12 transition-all" id="edit_left_panel">
                            <!-- Facility & Accreditation -->
                            <div class="row g-2 mb-3 border-bottom pb-3">
                                <div class="col-md-6">
                                    <label class="small fw-bold text-muted">HCI / Facility</label>
                                    <select name="edit_facility_id" id="edit_fac" class="form-select fw-bold" required>
                                        <?php foreach($facilities as $fac): ?>
                                            <option value="<?php echo $fac['id']; ?>"><?php echo htmlspecialchars($fac['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="small fw-bold text-muted">Accreditation No.</label>
                                    <select name="edit_accreditation_id" id="edit_acc" class="form-select border-secondary" required></select>
                                </div>
                                <div class="col-md-6">
                                    <label class="small fw-bold text-muted">Package Category</label>
                                    <input type="text" name="edit_package_category" id="edit_pkg" class="form-control bg-light" readonly required>
                                </div>
                                <div class="col-md-6">
                                    <label class="small fw-bold text-muted">Doc Type</label>
                                    <input type="text" id="edit_doc_type_display" class="form-control bg-light text-uppercase fw-bold" readonly>
                                </div>
                            </div>

                            <!-- Shared Document Info Edit -->
                            <div class="row g-2 mb-3 pb-3 border-bottom" id="edit_shared_doc_info" style="display:none;">
                                <div class="col-md-12 mb-1">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <label class="small fw-bold text-primary mb-0">ACPN / Tracking Number</label>
                                        <button type="button" class="btn btn-sm py-0 btn-link text-decoration-none" onclick="generateACPNFrontend('edit')"><i class="bi bi-magic"></i> Generate Code</button>
                                    </div>
                                    <input type="text" name="edit_acpn_number" id="edit_acpn" class="form-control border-primary fw-bold font-monospace" placeholder="Leave blank to auto-generate (e.g. CAP-LHHC-092026-001)">
                                </div>
                                <div class="col-md-6">
                                    <label class="small fw-bold text-muted">Date Credited (ACPN/Notice)</label>
                                    <input type="date" name="edit_date_credited" id="edit_date_credited" class="form-control">
                                </div>
                                <div class="col-md-6">
                                    <label class="small fw-bold text-muted">Period Covered</label>
                                    <div class="input-group mb-1">
                                        <input type="date" name="edit_period_start" id="edit_period_start" class="form-control">
                                        <span class="input-group-text bg-light border-0">to</span>
                                        <input type="date" name="edit_period_end" id="edit_period_end" class="form-control">
                                    </div>
                                    <input type="text" name="edit_period_covered" id="edit_period" class="form-control form-control-sm mt-1" placeholder="Text format if not a simple date range">
                                </div>
                            </div>

                            <!-- EDIT: Capitation -->
                            <div id="edit_capitation_fields" style="display:none;">
                                <div class="row g-2 mb-3 pb-3 border-bottom bg-light rounded p-2">
                                    <div class="col-12"><h6 class="fw-bold text-info mb-1"><i class="bi bi-cash-stack me-2"></i>Capitation Details</h6></div>
                                    <div class="col-md-6">
                                        <label class="small fw-bold text-muted">Tranche Type</label>
                                        <select name="edit_tranche_type" id="edit_tranche" class="form-select">
                                            <option value="">- Select -</option>
                                            <option value="Tranche 1">Tranche 1</option>
                                            <option value="Tranche 2">Tranche 2</option>
                                            <option value="Adjustment">Adjustment</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6" id="edit_dv_container">
                                        <!-- Populated via JS openEdit -->
                                    </div>
                                    <div class="col-md-6" id="edit_sap_container">
                                        <!-- Populated via JS openEdit -->
                                    </div>
                                    
                                    <div class="col-12 mt-3"><h6 class="fw-bold text-info mb-1"><i class="bi bi-people me-2"></i>Profiled Count</h6></div>
                                    <div class="col-md-3">
                                        <label class="small fw-bold text-muted">Enlisted</label>
                                        <input type="number" name="edit_enlisted_count" id="edit_enlisted" class="form-control text-center fw-bold">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="small fw-bold text-muted">Members</label>
                                        <input type="number" name="edit_profiled_members" id="edit_prof_m" class="form-control text-center fw-bold" oninput="calcProfiledTotal('edit')">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="small fw-bold text-muted">Dependents</label>
                                        <input type="number" name="edit_profiled_dependents" id="edit_prof_d" class="form-control text-center fw-bold" oninput="calcProfiledTotal('edit')">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="small fw-bold text-primary">Total</label>
                                        <input type="text" id="edit_profiled_total" class="form-control text-center fw-bold bg-white text-primary" readonly value="0">
                                    </div>
                                </div>
                            </div>

                            <!-- EDIT: Claims -->
                            <div id="edit_claims_fields" style="display:none;">
                                <div class="row g-2 mb-3 pb-3 border-bottom bg-light rounded p-3">
                                    <div class="col-12"><h6 class="fw-bold text-warning mb-2"><i class="bi bi-clipboard2-pulse me-2"></i>Claims Summary</h6></div>
                                    <div class="col-md-12 mb-2">
                                        <label class="small fw-bold text-muted">HC Professional</label>
                                        <select name="edit_hcp_id" id="edit_hcp" class="form-select"><option value="">- Select -</option></select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="small fw-bold text-muted">Total Gross (Auto)</label>
                                        <input type="text" name="edit_gross_amount" id="edit_gross" class="form-control bg-white" readonly>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="small fw-bold text-danger">Withholding Tax (Auto)</label>
                                        <input type="text" name="edit_withholding_tax" id="edit_tax" class="form-control border-danger text-danger bg-white" readonly>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="small fw-bold text-muted">Total HCI Share (Auto)</label>
                                        <input type="text" name="edit_hci_share" id="edit_hci" class="form-control bg-white" readonly>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="small fw-bold text-muted">Total PF Share (Auto)</label>
                                        <input type="text" name="edit_pf_share" id="edit_pf" class="form-control bg-white" readonly>
                                    </div>
                                </div>
                            </div>

                            <!-- EDIT: Pharmacy -->
                            <!-- EDIT: Pharmacy -->
                            <div id="edit_pharmacy_fields" style="display:none;">
                                <div class="row g-2 mb-3 pb-3 border-bottom bg-light rounded p-3">
                                    <div class="col-12"><h6 class="fw-bold text-secondary mb-2"><i class="bi bi-capsule me-2"></i>Pharmacy Summary</h6></div>
                                    <div class="col-md-12">
                                        <label class="small fw-bold text-muted">Total Gross (Auto)</label>
                                        <input type="text" name="edit_gross_amount" id="edit_ph_total_gross" class="form-control bg-white" placeholder="0.00" readonly>
                                    </div>
                                    <div class="col-md-12">
                                        <label class="small fw-bold text-danger">Total Withholding Tax (Auto)</label>
                                        <input type="text" name="edit_withholding_tax" id="edit_ph_total_tax" class="form-control border-danger text-danger bg-white" placeholder="0.00" readonly>
                                    </div>
                                    <div class="col-md-12">
                                        <label class="small fw-bold text-success">Total Net Amount (HCI Total)</label>
                                        <input type="text" name="edit_amount" id="edit_ph_total_net" class="form-control border-success text-success bg-white" placeholder="0.00" readonly>
                                    </div>
                                </div>
                            </div>
                        </div> <!-- End Left Panel -->

                        <!-- RIGHT PANEL: CLAIM LINES & PHARMACY LINES -->
                        <div class="col-lg-8 ps-lg-4" id="edit_right_panel" style="display:none;">
                            <div class="h-100 border rounded p-3 bg-white d-flex flex-column shadow-sm" id="edit_claim_lines_section" style="display:none;">
                                <h6 class="fw-bold text-warning mb-3"><i class="bi bi-list-columns-reverse me-2"></i>Patient Claim Lines (Saved) <span id="edit_claim_count" class="badge bg-warning text-dark ms-2 rounded-pill">0</span></h6>
                                <div class="flex-grow-1 pe-2 mb-2" style="max-height: 400px; overflow-y:auto; overflow-x: hidden;" id="claimLinesBody">
                                    <!-- Saved lines render here -->
                                </div>
                                <div id="addClaimLineFormArea" class="mt-2 pt-2 border-top border-2"></div>
                            </div>
                            
                            <div class="h-100 border rounded p-3 bg-white d-flex flex-column shadow-sm" id="edit_pharmacy_lines_section" style="display:none;">
                                <h6 class="fw-bold text-secondary mb-3"><i class="bi bi-capsule me-2"></i>Pharmacy GACN Lines (Saved) <span id="edit_pharmacy_count" class="badge bg-secondary text-white ms-2 rounded-pill">0</span></h6>
                                <div class="flex-grow-1 pe-2 mb-2" style="max-height: 400px; overflow-y:auto; overflow-x: hidden;" id="pharmacyLinesBody">
                                    <!-- Saved lines render here -->
                                </div>
                                <div id="addPharmacyLineFormArea" class="mt-2 pt-2 border-top border-2"></div>
                            </div>
                        </div> <!-- End Right Panel -->
                    </div> <!-- End Row 2-panel -->

                    <!-- BOTTOM CENTER -->
                    <div class="row justify-content-center mt-3 pt-3 border-top">
                        <div class="col-md-8 col-lg-6 text-center">
                            <!-- OR Fields -->
                            <div class="row g-2 mb-3" id="edit_or_section" style="display:none;">
                                <div class="col-md-6">
                                    <label class="small fw-bold text-success">Official Receipt No.</label>
                                    <input type="text" name="edit_or_number" id="edit_or_no" class="form-control border-success text-center">
                                </div>
                                <div class="col-md-6">
                                    <label class="small fw-bold text-success">OR Date</label>
                                    <input type="date" name="edit_or_date" id="edit_or_dt" class="form-control border-success text-center">
                                </div>
                            </div>

                            <!-- Net Amount -->
                            <div class="mb-3" id="edit_amount_section" style="display:none;">
                                <label class="small fw-bold text-success text-uppercase">Final Net Amount (Hits Bank)</label>
                                <div class="input-group input-group-lg justify-content-center">
                                    <span class="input-group-text bg-success text-white fw-bold">₱</span>
                                    <input type="text" name="edit_amount" id="edit_net" class="form-control fw-bold text-success fs-4 text-center" style="max-width:300px;" required oninput="formatAmount(this)">
                                </div>
                            </div>
                            
                            <button type="submit" name="update_receivable" class="btn btn-dark w-100 fw-bold shadow py-2 mt-2">Save Changes</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- RECONCILE MODAL -->
<!-- ============================================================ -->
<div class="modal fade" id="reconcileModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-success">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-link-45deg me-2"></i>Link to Bank Deposit</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center p-4">
                <h6 class="text-muted mb-1">ACPN Document Amount:</h6>
                <h2 class="fw-bold text-success mb-4" id="rec_amount_display">₱ 0.00</h2>
                
                <form method="POST" onsubmit="return confirm('Confirm reconciliation link?');">
                    <input type="hidden" name="reconcile_id" id="rec_id">
                    
                    <div class="text-start mb-3">
                        <label class="small fw-bold text-muted mb-2">Select the matching Bank Deposit (Cash Inflow):</label>
                        <select name="cash_inflow_id" class="form-select border-success fw-bold" required>
                            <option value="" disabled selected>-- Select Matching Deposit --</option>
                            <?php foreach($bank_inflows as $inflow): ?>
                                <option value="<?php echo $inflow['id']; ?>">
                                    <?php echo date('M d, Y', strtotime($inflow['date_received'])); ?> - 
                                    ₱ <?php echo number_format($inflow['amount'], 2); ?> 
                                    (<?php echo htmlspecialchars($inflow['account_name']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <button type="submit" name="reconcile_receivable" class="btn btn-success w-100 fw-bold">Confirm Reconciliation</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- OR FILL-IN MODAL -->
<!-- ============================================================ -->
<div class="modal fade" id="orFillModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content border-success">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-receipt me-2"></i>Fill O.R. Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST">
                    <input type="hidden" name="or_rec_id" id="or_rec_id">
                    <div class="mb-3">
                        <label class="small fw-bold text-muted">Official Receipt No.</label>
                        <input type="text" name="fill_or_number" class="form-control border-success" required>
                    </div>
                    <div class="mb-3">
                        <label class="small fw-bold text-muted">OR Date</label>
                        <input type="date" name="fill_or_date" class="form-control border-success">
                    </div>
                    <button type="submit" name="update_or_details" class="btn btn-success w-100 fw-bold">Save OR</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- CLAIM LINES VIEW MODAL -->
<!-- ============================================================ -->
<div class="modal fade" id="claimLinesModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title fw-bold"><i class="bi bi-list-columns-reverse me-2"></i>Claim Lines</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="p-3 border-bottom bg-light">
                    <span class="fw-bold" id="cl_view_title">Document</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover small mb-0" style="min-width: 1500px;">
                        <thead class="table-warning text-center">
                            <tr>
                                <th>PABN No.</th><th>Series No.</th><th>Member PIN</th><th>Patient Name</th><th>Confinement</th>
                                <th>CR1 Code</th><th class="text-end">CR1 Gross</th>
                                <th>CR2 Code</th><th class="text-end">CR2 Gross</th>
                                <th>CR3 Code</th><th class="text-end">CR3 Gross</th>
                                <th class="text-end">Gross</th><th class="text-end text-danger">Tax</th><th class="text-end">HCI</th><th class="text-end">PF</th>
                            </tr>
                        </thead>
                        <tbody id="claimLinesViewBody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- PHARMACY LINES VIEW MODAL -->
<div class="modal fade" id="pharmacyLinesModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-secondary text-white py-2">
                <h6 class="modal-title fw-bold mb-0" id="ph_view_title">Pharmacy Lines</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover small mb-0">
                        <thead class="table-secondary text-center">
                            <tr>
                                <th>GACN Date</th>
                                <th>GACN Number</th>
                                <th>PABN Number</th>
                                <th class="text-end">Gross</th>
                                <th class="text-end text-danger">Tax</th>
                                <th class="text-end text-success">Net Amount</th>
                            </tr>
                        </thead>
                        <tbody id="pharmacyLinesViewBody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    const allAccreditations = <?php echo json_encode($accreditations); ?>;
    const allHcps = <?php echo json_encode($all_hcps); ?>;

    // RVS Code options (fixed list, expandable later)
    const rvsOptions = ['89221', '89222'];
    let rvsDatalistHtml = rvsOptions.map(c => `<option value="${c}">`).join('');
    // Inject datalist into body once
    document.body.insertAdjacentHTML('beforeend', `<datalist id="rvsOpts">${rvsDatalistHtml}</datalist>`);
    
    function formatAmount(i) { 
        let v = i.value.replace(/[^0-9.]/g, '');
        let p = v.split('.'); 
        p[0] = p[0].replace(/\B(?=(\d{3})+(?!\d))/g, ","); 
        i.value = p.join('.') + (p.length>1 && p[1].length>2 ? '' : ''); 
    }

    function addExtraInput(containerId, inputName, placeholder) {
        const container = document.getElementById(containerId);
        if (container) {
            const wrapper = document.createElement('div');
            wrapper.className = 'input-group mb-1 mt-1';
            
            const input = document.createElement('input');
            input.type = 'text';
            input.name = inputName;
            input.className = 'form-control font-monospace';
            input.placeholder = `Additional ${placeholder}`;
            
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-outline-danger';
            btn.innerHTML = '<i class="bi bi-x"></i>';
            btn.title = 'Remove this field';
            btn.onclick = function() { wrapper.remove(); };
            
            wrapper.appendChild(input);
            wrapper.appendChild(btn);
            container.appendChild(wrapper);
        }
    }

    async function generateACPNFrontend(prefix) {
        const fac_id = document.getElementById(prefix + '_fac')?.value;
        const pkg = document.getElementById(prefix + '_pkg')?.value;
        const dateEl = document.getElementById(prefix + '_date_credited');
        const acpnEl = document.getElementById(prefix + '_acpn');
        
        if (!fac_id || !pkg) {
            alert('Please select a facility and a package first.');
            return;
        }
        
        try {
            const res = await fetch(`?ajax_generate_acpn=1&fac_id=${fac_id}&pkg=${encodeURIComponent(pkg)}&date=${dateEl ? dateEl.value : ''}`);
            const data = await res.json();
            if (data.success) {
                if (acpnEl) acpnEl.value = data.acpn;
            } else {
                alert('Error generating ACPN: ' + data.error);
            }
        } catch (e) {
            alert('Network error while generating ACPN');
        }
    }

    function fmtNum(val) {
        return parseFloat(val || 0).toLocaleString('en-US', {minimumFractionDigits:2});
    }

    function parseDateRange(dateStr) {
        if(!dateStr || !dateStr.includes('to')) return {start:'', end:''};
        let pts = dateStr.split(' to ');
        if(pts.length === 2) {
            let d1 = new Date(pts[0]);
            let d2 = new Date(pts[1]);
            if(!isNaN(d1) && !isNaN(d2)) {
                return { start: d1.toISOString().split('T')[0], end: d2.toISOString().split('T')[0] };
            }
        }
        return {start:'', end:''};
    }

    function calcProfiledTotal(prefix) {
        const mEl = prefix === 'add' ? document.querySelector('[name="profiled_members"]') : document.getElementById('edit_prof_m');
        const dEl = prefix === 'add' ? document.querySelector('[name="profiled_dependents"]') : document.getElementById('edit_prof_d');
        const tEl = document.getElementById(prefix + '_profiled_total');
        const m = parseInt(mEl?.value || 0);
        const d = parseInt(dEl?.value || 0);
        if (tEl) tEl.value = m + d;
    }

    function detectDocType(pkg) {
        pkg = pkg.toLowerCase();
        if (pkg.includes('primary care') || pkg.includes('konsulta') || pkg.includes('pcb') || pkg.includes('capitation')) return 'capitation';
        if (pkg.includes('gamot') || pkg.includes('pharmacy') || pkg.includes('drug')) return 'pharmacy';
        return 'claims';
    }

    function toggleTypeFields(prefix, docType) {
        const capEl = document.getElementById(prefix + '_capitation_fields');
        const clmEl = document.getElementById(prefix + '_claims_fields');
        const rxEl = document.getElementById(prefix + '_pharmacy_fields');
        const orEl = document.getElementById(prefix + '_or_section');
        const amtEl = document.getElementById(prefix + '_amount_section');
        const shEl = document.getElementById(prefix + '_shared_doc_info');
        const dtEl = document.getElementById(prefix + '_doc_type_display');
        
        const leftPanel = document.getElementById(prefix + '_left_panel');
        const rightPanel = document.getElementById(prefix + '_right_panel');
        
        const clmSec = document.getElementById(prefix + '_claim_lines_section');
        const rxSec = document.getElementById(prefix + '_pharmacy_lines_section');

        [capEl, clmEl, rxEl, orEl, amtEl, shEl, clmSec, rxSec].forEach(el => { 
            if(el) { el.style.setProperty('display', 'none', 'important'); }
        });
        if (!docType) { if(dtEl) dtEl.value = ''; return; }

        if(dtEl) dtEl.value = docType.toUpperCase();
        if(orEl) orEl.style.setProperty('display', 'flex', 'important');
        if(amtEl) amtEl.style.setProperty('display', 'block', 'important');
        if(shEl) shEl.style.setProperty('display', 'flex', 'important');

        if (docType === 'claims') {
            leftPanel.className = 'col-lg-4 border-end transition-all pe-lg-4';
            rightPanel.style.display = 'block';
            if (clmEl) clmEl.style.setProperty('display', 'block', 'important');
            if (clmSec) clmSec.style.setProperty('display', 'flex', 'important');
            if (prefix === 'add') checkAddClaimLines();
        } else if (docType === 'pharmacy') {
            leftPanel.className = 'col-lg-4 border-end transition-all pe-lg-4';
            rightPanel.style.display = 'block';
            if (rxEl) rxEl.style.setProperty('display', 'block', 'important');
            if (rxSec) rxSec.style.setProperty('display', 'flex', 'important');
            if (prefix === 'add') checkAddPharmacyLines();
        } else {
            leftPanel.className = 'col-lg-12 transition-all';
            rightPanel.style.display = 'none';
            if (docType === 'capitation' && capEl) capEl.style.setProperty('display', 'block', 'important');
        }
    }

    function updateAccreditations(facId, targetSelectId, selectedAccId = '') {
        const accSelect = document.getElementById(targetSelectId);
        const prefix = targetSelectId === 'add_acc' ? 'add' : 'edit';
        const pkgId = prefix + '_pkg';
        
        accSelect.innerHTML = '<option value="">- Select Accreditation -</option>';
        if (document.getElementById(pkgId)) document.getElementById(pkgId).value = '';
        toggleTypeFields(prefix, null);

        if (!facId) { accSelect.disabled = true; return; }
        accSelect.disabled = false;
        
        const filtered = allAccreditations.filter(a => a.facility_id == facId);
        filtered.forEach(acc => {
            const opt = document.createElement('option');
            opt.value = acc.id;
            opt.textContent = `${acc.package_type} (${acc.accreditation_no})`;
            if (acc.id == selectedAccId) opt.selected = true;
            accSelect.appendChild(opt);
        });
        updateHcpDropdown(facId, prefix);
    }

    function updateHcpDropdown(facId, prefix) {
        const hcpEl = document.getElementById(prefix + '_hcp');
        if (!hcpEl) return;
        hcpEl.innerHTML = '<option value="">- Select HC Professional -</option>';
        allHcps.filter(h => h.facility_id == facId).forEach(h => {
            const opt = document.createElement('option');
            opt.value = h.id;
            opt.textContent = h.hcp_name;
            hcpEl.appendChild(opt);
        });
    }

    function handleAccreditationChange(accId, prefix) {
        const pkgEl = document.getElementById(prefix + '_pkg');
        if(accId) {
            const acc = allAccreditations.find(a => a.id == accId);
            if(acc) {
                pkgEl.value = acc.package_type;
                toggleTypeFields(prefix, detectDocType(acc.package_type));
            }
        } else {
            pkgEl.value = '';
            toggleTypeFields(prefix, null);
        }
    }

    if(document.getElementById('add_fac')) {
        document.getElementById('add_fac').addEventListener('change', function() { updateAccreditations(this.value, 'add_acc'); });
        document.getElementById('add_acc').addEventListener('change', function() { handleAccreditationChange(this.value, 'add'); });
    }

    if(document.getElementById('edit_fac')) {
        document.getElementById('edit_fac').addEventListener('change', function() { updateAccreditations(this.value, 'edit_acc'); });
        document.getElementById('edit_acc').addEventListener('change', function() { handleAccreditationChange(this.value, 'edit'); });
    }

    // -------------------------------------------------------------
    // DYNAMIC CLAIM LINES
    // -------------------------------------------------------------
    function toggleAdditionalCaserates(rowId) {
        const chk = document.getElementById('toggle_' + rowId);
        const div = document.getElementById('extra_cr_' + rowId);
        if(chk && chk.checked) {
            div.style.display = 'flex';
        } else if(div) {
            div.style.display = 'none';
        }
    }

    function checkAddClaimLines() {
        const tbody = document.getElementById('addClaimLinesBody');
        if(tbody && tbody.children.length === 0) addClaimLineRow('add'); // Auto-add first row
    }

    function addClaimLineRow(prefix) {
        const tbody = document.getElementById('addClaimLinesBody');
        const rowId = 'clrow_' + Date.now();
        const div = document.createElement('div');
        div.className = "card mb-3 border shadow-sm claim-line-card";
        div.style.borderColor = "#ffc107"; // warning color
        div.id = rowId;
        div.innerHTML = `
            <div class="card-header bg-warning bg-opacity-10 py-2 d-flex justify-content-between align-items-center">
                <span class="fw-bold text-dark small"><i class="bi bi-person me-2"></i>Patient Claim Details</span>
                <button type="button" class="btn btn-sm btn-outline-danger py-0" onclick="document.getElementById('${rowId}').remove(); calcClaimsTotals('add');" title="Remove Patient"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="card-body p-3 bg-light">
                <div class="row g-2 mb-2">
                    <div class="col-md-3">
                        <label class="small text-muted mb-1">PABN No.</label>
                        <input type="text" name="cl_pabn_number[]" class="form-control form-control-sm border-primary" required maxlength="14" title="e.g. P01-2605-02344">
                    </div>
                    <div class="col-md-3">
                        <label class="small text-muted mb-1">Series No.</label>
                        <input type="text" name="cl_series_number[]" class="form-control form-control-sm" maxlength="13" title="13 digits only">
                    </div>
                    <div class="col-md-3">
                        <label class="small text-muted mb-1">Member PIN</label>
                        <input type="text" name="cl_member_pin[]" class="form-control form-control-sm" maxlength="13" title="e.g. P011751471620">
                    </div>
                    <div class="col-md-3">
                        <label class="small text-muted mb-1">Patient Name</label>
                        <input type="text" name="cl_patient_name[]" class="form-control form-control-sm fw-bold" required>
                    </div>
                </div>

                <div class="row g-2 mb-2">
                    <div class="col-md-4">
                        <label class="small text-muted mb-1">Confinement Period</label>
                        <div class="input-group input-group-sm">
                            <input type="date" name="cl_conf_start[]" class="form-control p-1" title="Start">
                            <span class="input-group-text bg-white border-0 px-1 text-muted">to</span>
                            <input type="date" name="cl_conf_end[]" class="form-control p-1" title="End">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="small text-muted mb-1">Case Rate 1</label>
                        <div class="input-group input-group-sm">
                            <input type="text" name="cl_cr1_code[]" class="form-control" list="rvsOpts" placeholder="Code" style="max-width: 90px;" maxlength="5" title="5 alphanumeric characters">
                            <input type="text" name="cl_cr1_gross[]" class="form-control cl-val text-end fw-bold" placeholder="Gross" oninput="formatAmount(this); autoCalcRowGross(this)">
                        </div>
                    </div>
                    <div class="col-md-4 d-flex align-items-end pb-1">
                        <div class="form-check form-switch ms-2">
                            <input class="form-check-input" type="checkbox" role="switch" id="toggle_${rowId}" onchange="toggleAdditionalCaserates('${rowId}')">
                            <label class="form-check-label small fw-bold text-secondary" for="toggle_${rowId}">+ Add CR2 / CR3</label>
                        </div>
                    </div>
                </div>

                <div class="row g-2 mb-2 additional-cr" id="extra_cr_${rowId}" style="display:none;">
                    <div class="col-md-4 offset-md-4">
                        <label class="small text-muted mb-1">Case Rate 2</label>
                        <div class="input-group input-group-sm">
                            <input type="text" name="cl_cr2_code[]" class="form-control" list="rvsOpts" placeholder="Code" style="max-width: 90px;" maxlength="5" title="5 alphanumeric characters">
                            <input type="text" name="cl_cr2_gross[]" class="form-control cl-val text-end fw-bold" placeholder="Gross" oninput="formatAmount(this); autoCalcRowGross(this)">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="small text-muted mb-1">Case Rate 3</label>
                        <div class="input-group input-group-sm">
                            <input type="text" name="cl_cr3_code[]" class="form-control" list="rvsOpts" placeholder="Code" style="max-width: 90px;" maxlength="5" title="5 alphanumeric characters">
                            <input type="text" name="cl_cr3_gross[]" class="form-control cl-val text-end fw-bold" placeholder="Gross" oninput="formatAmount(this); autoCalcRowGross(this)">
                        </div>
                    </div>
                </div>

                <div class="row g-2 mt-2 pt-2 border-top">
                    <div class="col-md-3">
                        <label class="small text-muted mb-1">Total Gross</label>
                        <input type="text" name="cl_gross[]" class="form-control form-control-sm bg-white cl-gross text-end fw-bold text-dark" readonly placeholder="0.00">
                    </div>
                    <div class="col-md-3">
                        <label class="small text-danger mb-1">Withholding Tax</label>
                        <input type="text" name="cl_tax[]" class="form-control form-control-sm cl-tax text-end text-danger fw-bold" placeholder="0.00" oninput="formatAmount(this); calcClaimsTotals('add')">
                    </div>
                    <div class="col-md-3">
                        <label class="small text-muted mb-1">HCI Share</label>
                        <input type="text" name="cl_hci[]" class="form-control form-control-sm cl-hci text-end text-primary fw-bold" placeholder="0.00" oninput="formatAmount(this); calcClaimsTotals('add')">
                    </div>
                    <div class="col-md-3">
                        <label class="small text-muted mb-1">PF Share</label>
                        <input type="text" name="cl_pf[]" class="form-control form-control-sm cl-pf text-end text-success fw-bold" placeholder="0.00" oninput="formatAmount(this); calcClaimsTotals('add')">
                    </div>
                </div>
            </div>
        `;
        tbody.appendChild(div);
    }

    function autoCalcRowGross(el) {
        const card = el.closest('.claim-line-card');
        const cr1 = parseFloat(card.querySelector('[name*="_cr1_gross"]').value.replace(/,/g, '') || 0);
        const cr2 = parseFloat(card.querySelector('[name*="_cr2_gross"]').value.replace(/,/g, '') || 0);
        const cr3 = parseFloat(card.querySelector('[name*="_cr3_gross"]').value.replace(/,/g, '') || 0);
        const gross = cr1 + cr2 + cr3;
        
        card.querySelector('.cl-gross').value = fmtNum(gross);
        
        const prefix = card.closest('#addClaimLinesBody') ? 'add' : 'edit';
        calcClaimsTotals(prefix);
    }

    function calcClaimsTotals(prefix) {
        if (prefix === 'add') {
            let tGross = 0, tTax = 0, tHci = 0, tPf = 0;
            const cards = document.querySelectorAll('#addClaimLinesBody .claim-line-card');
            cards.forEach(card => {
                tGross += parseFloat(card.querySelector('.cl-gross').value.replace(/,/g, '') || 0);
                tTax += parseFloat(card.querySelector('.cl-tax').value.replace(/,/g, '') || 0);
                tHci += parseFloat(card.querySelector('.cl-hci').value.replace(/,/g, '') || 0);
                tPf += parseFloat(card.querySelector('.cl-pf').value.replace(/,/g, '') || 0);
            });
            document.getElementById('add_total_gross').value = fmtNum(tGross);
            document.getElementById('add_total_tax').value = fmtNum(tTax);
            document.getElementById('add_total_hci').value = fmtNum(tHci);
            document.getElementById('add_total_pf').value = fmtNum(tPf);
            
            const countEl = document.getElementById('add_claim_count');
            if (countEl) countEl.innerText = cards.length;
        }
    }

    // -------------------------------------------------------------
    // DYNAMIC PHARMACY LINES
    // -------------------------------------------------------------
    function checkAddPharmacyLines() {
        const tbody = document.getElementById('addPharmacyLinesBody');
        if(tbody && tbody.children.length === 0) addPharmacyLineRow('add'); // Auto-add first row
    }

    function addPharmacyLineRow(prefix) {
        const tbody = document.getElementById('addPharmacyLinesBody');
        const rowId = 'phrow_' + Date.now();
        const div = document.createElement('div');
        div.className = "card mb-3 border shadow-sm pharmacy-line-card";
        div.style.borderColor = "#6c757d"; // secondary color
        div.id = rowId;
        div.innerHTML = `
            <div class="card-header bg-secondary bg-opacity-10 py-2 d-flex justify-content-between align-items-center">
                <span class="fw-bold text-dark small"><i class="bi bi-capsule me-2"></i>GACN Detail Line</span>
                <button type="button" class="btn btn-sm btn-outline-danger py-0" onclick="document.getElementById('${rowId}').remove(); calcPharmacyTotals('add');" title="Remove Line"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="card-body p-3 bg-light">
                <div class="row g-2 mb-2">
                    <div class="col-md-4">
                        <label class="small text-muted mb-1">GACN Number</label>
                        <input type="text" name="ph_gacn_number[]" class="form-control form-control-sm font-monospace" required>
                    </div>
                    <div class="col-md-4">
                        <label class="small text-muted mb-1">GACN Date</label>
                        <input type="date" name="ph_gacn_date[]" class="form-control form-control-sm" required>
                    </div>
                    <div class="col-md-4">
                        <label class="small text-muted mb-1">PABN No.</label>
                        <input type="text" name="ph_pabn_number[]" class="form-control form-control-sm border-primary">
                    </div>
                </div>

                <div class="row g-2 mt-2 pt-2 border-top">
                    <div class="col-md-4">
                        <label class="small text-muted mb-1">Gross</label>
                        <input type="text" name="ph_gross[]" class="form-control form-control-sm bg-white ph-gross text-end fw-bold text-dark" placeholder="0.00" oninput="formatAmount(this); autoCalcPhRowNet(this)">
                    </div>
                    <div class="col-md-4">
                        <label class="small text-danger mb-1">Withholding Tax</label>
                        <input type="text" name="ph_tax[]" class="form-control form-control-sm ph-tax text-end text-danger fw-bold" placeholder="0.00" oninput="formatAmount(this); autoCalcPhRowNet(this)">
                    </div>
                    <div class="col-md-4">
                        <label class="small text-success mb-1">Net Amount</label>
                        <input type="text" name="ph_net[]" class="form-control form-control-sm ph-net text-end text-success fw-bold bg-white" placeholder="0.00" readonly>
                    </div>
                </div>
            </div>
        `;
        tbody.appendChild(div);
    }

    function autoCalcPhRowNet(el) {
        const card = el.closest('.pharmacy-line-card');
        const gross = parseFloat(card.querySelector('.ph-gross').value.replace(/,/g, '') || 0);
        const tax = parseFloat(card.querySelector('.ph-tax').value.replace(/,/g, '') || 0);
        const net = gross - tax;
        
        card.querySelector('.ph-net').value = fmtNum(net);
        
        const prefix = card.closest('#addPharmacyLinesBody') ? 'add' : 'edit';
        calcPharmacyTotals(prefix);
    }

    function calcPharmacyTotals(prefix) {
        if (prefix === 'add') {
            let tGross = 0, tTax = 0, tNet = 0;
            const cards = document.querySelectorAll('#addPharmacyLinesBody .pharmacy-line-card');
            cards.forEach(card => {
                tGross += parseFloat(card.querySelector('.ph-gross').value.replace(/,/g, '') || 0);
                tTax += parseFloat(card.querySelector('.ph-tax').value.replace(/,/g, '') || 0);
                tNet += parseFloat(card.querySelector('.ph-net').value.replace(/,/g, '') || 0);
            });
            document.getElementById('add_ph_total_gross').value = fmtNum(tGross);
            document.getElementById('add_ph_total_tax').value = fmtNum(tTax);
            document.getElementById('add_ph_total_net').value = fmtNum(tNet);
            
            const countEl = document.getElementById('add_pharmacy_count');
            if (countEl) countEl.innerText = cards.length;
        }
    }


    // -------------------------------------------------------------
    // EDIT MODAL LOGIC
    // -------------------------------------------------------------
    function openEdit(data) {
        document.getElementById('edit_id').value = data.id;
        document.getElementById('edit_fac').value = data.facility_id;
        
        updateAccreditations(data.facility_id, 'edit_acc', data.accreditation_id);
        
        const pkg = data.package_category;
        document.getElementById('edit_pkg').value = pkg;
        const docType = data.document_type || detectDocType(pkg);
        toggleTypeFields('edit', docType);

        document.getElementById('edit_or_no').value = data.or_number || '';
        document.getElementById('edit_or_dt').value = data.or_date || '';
        document.getElementById('edit_net').value = fmtNum(data.amount);
        document.getElementById('edit_date_credited').value = data.date_credited || '';

        let dates = parseDateRange(data.period_covered);
        document.getElementById('edit_period_start').value = dates.start;
        document.getElementById('edit_period_end').value = dates.end;
        document.getElementById('edit_period').value = data.period_covered || '';

        updateHcpDropdown(data.facility_id, 'edit');
        setTimeout(() => {
            if(document.getElementById('edit_hcp') && data.hcp_id) {
                document.getElementById('edit_hcp').value = data.hcp_id;
            }
        }, 100);

        document.getElementById('edit_acpn').value = data.acpn_number || '';
        
        if (docType === 'capitation') {
            document.getElementById('edit_tranche').value = data.tranche_type || '';
            
            let dvContainer = document.getElementById('edit_dv_container');
            if (dvContainer) {
                let html = `<div class="d-flex justify-content-between align-items-center mb-1">
                                <label class="small fw-bold text-muted mb-0">DV Number</label>
                                <button type="button" class="btn btn-sm py-0 btn-link text-decoration-none" title="Add another DV Number field" onclick="addExtraInput('edit_dv_container', 'edit_dv_number[]', 'DV Number')"><i class="bi bi-plus"></i> Add</button>
                            </div>`;
                let dvs = (data.dv_number || '').split(',').map(s => s.trim()).filter(s => s);
                if (dvs.length === 0) dvs = [''];
                dvs.forEach((val, i) => {
                    if (i === 0) {
                        html += `<input type="text" name="edit_dv_number[]" class="form-control font-monospace mb-1" value="${val}">`;
                    } else {
                        html += `<div class="input-group mb-1 mt-1">
                                    <input type="text" name="edit_dv_number[]" class="form-control font-monospace" value="${val}">
                                    <button type="button" class="btn btn-outline-danger" title="Remove this field" onclick="this.parentElement.remove()"><i class="bi bi-x"></i></button>
                                 </div>`;
                    }
                });
                dvContainer.innerHTML = html;
            }

            let sapContainer = document.getElementById('edit_sap_container');
            if (sapContainer) {
                let html = `<div class="d-flex justify-content-between align-items-center mb-1">
                                <label class="small fw-bold text-muted mb-0">SAP Number</label>
                                <button type="button" class="btn btn-sm py-0 btn-link text-decoration-none" title="Add another SAP Number field" onclick="addExtraInput('edit_sap_container', 'edit_sap_number[]', 'SAP Number')"><i class="bi bi-plus"></i> Add</button>
                            </div>`;
                let saps = (data.sap_number || '').split(',').map(s => s.trim()).filter(s => s);
                if (saps.length === 0) saps = [''];
                saps.forEach((val, i) => {
                    if (i === 0) {
                        html += `<input type="text" name="edit_sap_number[]" class="form-control font-monospace mb-1" value="${val}">`;
                    } else {
                        html += `<div class="input-group mb-1 mt-1">
                                    <input type="text" name="edit_sap_number[]" class="form-control font-monospace" value="${val}">
                                    <button type="button" class="btn btn-outline-danger" title="Remove this field" onclick="this.parentElement.remove()"><i class="bi bi-x"></i></button>
                                 </div>`;
                    }
                });
                sapContainer.innerHTML = html;
            }

            document.getElementById('edit_enlisted').value = data.enlisted_count || '';
            document.getElementById('edit_prof_m').value = data.profiled_members || '';
            document.getElementById('edit_prof_d').value = data.profiled_dependents || '';
            calcProfiledTotal('edit');
        } else if (docType === 'claims') {
            document.getElementById('edit_gross').value = fmtNum(data.gross_amount);
            document.getElementById('edit_tax').value = fmtNum(data.withholding_tax);
            document.getElementById('edit_hci').value = fmtNum(data.hci_share);
            document.getElementById('edit_pf').value = fmtNum(data.pf_share);
            renderClaimLines(data);
        } else if (docType === 'pharmacy') {
            // document.getElementById('edit_acpn_pharma').value = data.acpn_number || ''; // removed PABN from left panel
            document.getElementById('edit_ph_total_gross').value = fmtNum(data.gross_amount);
            document.getElementById('edit_ph_total_tax').value = fmtNum(data.withholding_tax);
            document.getElementById('edit_ph_total_net').value = fmtNum(data.amount);
            renderPharmacyLines(data);
        }
        new bootstrap.Modal(document.getElementById('editReceivableModal')).show();
    }

    function renderClaimLines(data) {
        const tbody = document.getElementById('claimLinesBody');
        const addArea = document.getElementById('addClaimLineFormArea');
        tbody.innerHTML = '';

        const lines = data.claim_lines || [];
        const countEl = document.getElementById('edit_claim_count');
        if (countEl) countEl.innerText = lines.length;

        if (lines.length > 0) {
            lines.forEach(cl => {
                let showExtra = (cl.caserate2_code || cl.caserate2_gross > 0 || cl.caserate3_code || cl.caserate3_gross > 0);
                let extraHtml = '';

                if(showExtra) {
                    extraHtml = `
                    <div class="row g-2 mb-2 mt-2 pt-2 border-top border-dashed">
                        <div class="col-md-4 offset-md-4">
                            <label class="small text-muted mb-1">Case Rate 2</label>
                            <div class="fw-bold small">${cl.caserate2_code || '-'} <span class="ms-2">₱ ${fmtNum(cl.caserate2_gross)}</span></div>
                        </div>
                        <div class="col-md-4">
                            <label class="small text-muted mb-1">Case Rate 3</label>
                            <div class="fw-bold small">${cl.caserate3_code || '-'} <span class="ms-2">₱ ${fmtNum(cl.caserate3_gross)}</span></div>
                        </div>
                    </div>
                    `;
                }

                let conf = cl.confinement_period || 'N/A';

                tbody.innerHTML += `
                <div class="card mb-3 border shadow-sm">
                    <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                        <span class="fw-bold text-dark small"><i class="bi bi-person me-2"></i>${cl.patient_name || 'Patient'}</span>
                        <div>
                            <button type="button" class="btn btn-sm btn-outline-primary border-0 py-0 me-2" title="Edit Saved Line" data-cl="${encodeURIComponent(JSON.stringify(cl))}" onclick="editClaimLine(this)"><i class="bi bi-pencil"></i></button>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Delete this claim line? (Parent totals will auto-update)');">
                                <input type="hidden" name="del_cl_id" value="${cl.id}">
                                <button type="submit" name="delete_claim_line" class="btn btn-sm btn-outline-danger border-0 py-0" title="Delete Saved Line"><i class="bi bi-trash"></i></button>
                            </form>
                        </div>
                    </div>
                    <div class="card-body p-3 bg-light">
                        <div class="row g-2 mb-2">
                            <div class="col-md-4">
                                <label class="small text-muted mb-1">PABN / Series / PIN</label>
                                <div class="fw-bold small font-monospace">${cl.pabn_number || '-'} / ${cl.series_number || '-'} / ${cl.member_pin || '-'}</div>
                            </div>
                            <div class="col-md-4">
                                <label class="small text-muted mb-1">Confinement Period</label>
                                <div class="fw-bold small">${conf}</div>
                            </div>
                            <div class="col-md-4">
                                <label class="small text-muted mb-1">Case Rate 1</label>
                                <div class="fw-bold small">${cl.caserate1_code || '-'} <span class="ms-2 text-dark">₱ ${fmtNum(cl.caserate1_gross)}</span></div>
                            </div>
                        </div>
                        ${extraHtml}
                        <div class="row g-2 mt-2 pt-2 border-top">
                            <div class="col-md-3">
                                <label class="small text-muted mb-1">Total Gross</label>
                                <div class="fw-bold text-dark fs-6">₱ ${fmtNum(cl.gross)}</div>
                            </div>
                            <div class="col-md-3">
                                <label class="small text-danger mb-1">Withholding Tax</label>
                                <div class="fw-bold text-danger fs-6">₱ ${fmtNum(cl.withholding_tax)}</div>
                            </div>
                            <div class="col-md-3">
                                <label class="small text-muted mb-1">HCI Share</label>
                                <div class="fw-bold text-primary fs-6">₱ ${fmtNum(cl.hci)}</div>
                            </div>
                            <div class="col-md-3">
                                <label class="small text-muted mb-1">PF Share</label>
                                <div class="fw-bold text-success fs-6">₱ ${fmtNum(cl.pf)}</div>
                            </div>
                        </div>
                    </div>
                </div>
                `;
            });
        } else {
            tbody.innerHTML = '<div class="text-center text-muted py-4 fst-italic">No claim lines yet.</div>';
        }

        const editRowId = 'edit_add_cl';
        addArea.innerHTML = `
            <div class="fw-bold text-success small mb-2"><i class="bi bi-plus-circle me-1"></i>Add New Patient Line (Auto-saves & updates parent total)</div>
            <form method="POST">
                <input type="hidden" name="cl_receivable_id" value="${data.id}">
                <div class="card border shadow-sm claim-line-card" style="border-color:#198754;" id="${editRowId}">
                    <div class="card-body p-3 bg-light">
                        <div class="row g-2 mb-2">
                            <div class="col-md-3">
                                <label class="small text-muted mb-1">PABN No.</label>
                                <input type="text" name="cl_pabn_number" class="form-control form-control-sm border-success" required maxlength="14" title="e.g. P01-2605-02344">
                            </div>
                            <div class="col-md-3">
                                <label class="small text-muted mb-1">Series No.</label>
                                <input type="text" name="cl_series_number" class="form-control form-control-sm" maxlength="13" title="13 digits only">
                            </div>
                            <div class="col-md-3">
                                <label class="small text-muted mb-1">Member PIN</label>
                                <input type="text" name="cl_member_pin" class="form-control form-control-sm" maxlength="13" title="e.g. P011751471620">
                            </div>
                            <div class="col-md-3">
                                <label class="small text-muted mb-1">Patient Name</label>
                                <input type="text" name="cl_patient_name" class="form-control form-control-sm fw-bold" required>
                            </div>
                        </div>

                        <div class="row g-2 mb-2">
                            <div class="col-md-4">
                                <label class="small text-muted mb-1">Confinement Period</label>
                                <div class="input-group input-group-sm">
                                    <input type="date" name="cl_conf_start" class="form-control p-1">
                                    <span class="input-group-text bg-white border-0 px-1 text-muted">to</span>
                                    <input type="date" name="cl_conf_end" class="form-control p-1">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="small text-muted mb-1">Case Rate 1</label>
                                <div class="input-group input-group-sm">
                                    <input type="text" name="cl_cr1_code" class="form-control" list="rvsOpts" placeholder="Code" style="max-width: 90px;" maxlength="5" title="5 alphanumeric characters">
                                    <input type="text" name="cl_cr1_gross" class="form-control cl-val text-end fw-bold" placeholder="Gross" oninput="formatAmount(this); autoCalcRowGross(this)">
                                </div>
                            </div>
                            <div class="col-md-4 d-flex align-items-end pb-1">
                                <div class="form-check form-switch ms-2">
                                    <input class="form-check-input" type="checkbox" role="switch" id="toggle_${editRowId}" onchange="toggleAdditionalCaserates('${editRowId}')">
                                    <label class="form-check-label small fw-bold text-secondary" for="toggle_${editRowId}">+ Add CR2 / CR3</label>
                                </div>
                            </div>
                        </div>

                        <div class="row g-2 mb-2 additional-cr" id="extra_cr_${editRowId}" style="display:none;">
                            <div class="col-md-4 offset-md-4">
                                <label class="small text-muted mb-1">Case Rate 2</label>
                                <div class="input-group input-group-sm">
                                    <input type="text" name="cl_cr2_code" class="form-control" list="rvsOpts" placeholder="Code" style="max-width: 90px;" maxlength="5" title="5 alphanumeric characters">
                                    <input type="text" name="cl_cr2_gross" class="form-control cl-val text-end fw-bold" placeholder="Gross" oninput="formatAmount(this); autoCalcRowGross(this)">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="small text-muted mb-1">Case Rate 3</label>
                                <div class="input-group input-group-sm">
                                    <input type="text" name="cl_cr3_code" class="form-control" list="rvsOpts" placeholder="Code" style="max-width: 90px;" maxlength="5" title="5 alphanumeric characters">
                                    <input type="text" name="cl_cr3_gross" class="form-control cl-val text-end fw-bold" placeholder="Gross" oninput="formatAmount(this); autoCalcRowGross(this)">
                                </div>
                            </div>
                        </div>

                        <div class="row g-2 mt-2 pt-2 border-top">
                            <div class="col-md-2">
                                <label class="small text-muted mb-1">Gross</label>
                                <input type="text" name="cl_gross" class="form-control form-control-sm bg-white cl-gross text-end fw-bold text-dark px-1" readonly placeholder="0.00">
                            </div>
                            <div class="col-md-2">
                                <label class="small text-danger mb-1">Tax</label>
                                <input type="text" name="cl_tax" class="form-control form-control-sm cl-tax text-end text-danger fw-bold px-1" placeholder="0.00" oninput="formatAmount(this)">
                            </div>
                            <div class="col-md-2">
                                <label class="small text-muted mb-1">HCI Share</label>
                                <input type="text" name="cl_hci" class="form-control form-control-sm cl-hci text-end text-primary fw-bold px-1" placeholder="0.00" oninput="formatAmount(this)">
                            </div>
                            <div class="col-md-3">
                                <label class="small text-muted mb-1">PF Share</label>
                                <input type="text" name="cl_pf" class="form-control form-control-sm cl-pf text-end text-success fw-bold px-1" placeholder="0.00" oninput="formatAmount(this)">
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <button type="submit" name="add_claim_line" class="btn btn-sm btn-success fw-bold w-100 py-1"><i class="bi bi-save me-1"></i>Save Line</button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        `;
    }
    
    function renderPharmacyLines(data) {
        const tbody = document.getElementById('pharmacyLinesBody');
        const addArea = document.getElementById('addPharmacyLineFormArea');
        tbody.innerHTML = '';

        const lines = data.pharmacy_lines || [];
        const countEl = document.getElementById('edit_pharmacy_count');
        if (countEl) countEl.innerText = lines.length;

        if (lines.length > 0) {
            lines.forEach(ph => {
                let dString = ph.gacn_date || 'N/A';

                tbody.innerHTML += `
                <div class="card mb-3 border shadow-sm">
                    <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                        <span class="fw-bold text-dark small"><i class="bi bi-capsule me-2"></i>GACN: ${ph.gacn_number || '-'}</span>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this pharmacy line? (Parent totals will auto-update)');">
                            <input type="hidden" name="del_ph_id" value="${ph.id}">
                            <button type="submit" name="delete_pharmacy_line" class="btn btn-sm btn-outline-danger border-0 py-0" title="Delete Saved Line"><i class="bi bi-trash"></i></button>
                        </form>
                    </div>
                    <div class="card-body p-3 bg-light">
                        <div class="row g-2 mb-2">
                            <div class="col-md-6">
                                <label class="small text-muted mb-1">GACN Date</label>
                                <div class="fw-bold small">${dString}</div>
                            </div>
                            <div class="col-md-6">
                                <label class="small text-muted mb-1">PABN No.</label>
                                <div class="fw-bold small font-monospace">${ph.pabn_number || '-'}</div>
                            </div>
                        </div>
                        <div class="row g-2 mt-2 pt-2 border-top">
                            <div class="col-md-4">
                                <label class="small text-muted mb-1">Gross</label>
                                <div class="fw-bold text-dark fs-6">₱ ${fmtNum(ph.gross)}</div>
                            </div>
                            <div class="col-md-4">
                                <label class="small text-danger mb-1">Withholding Tax</label>
                                <div class="fw-bold text-danger fs-6">₱ ${fmtNum(ph.withholding_tax)}</div>
                            </div>
                            <div class="col-md-4">
                                <label class="small text-muted mb-1">Net Amount</label>
                                <div class="fw-bold text-success fs-6">₱ ${fmtNum(ph.net_amount)}</div>
                            </div>
                        </div>
                    </div>
                </div>
                `;
            });
        } else {
            tbody.innerHTML = '<div class="text-center text-muted py-4 fst-italic">No pharmacy lines yet.</div>';
        }

        const editRowId = 'edit_add_ph';
        addArea.innerHTML = `
            <div class="fw-bold text-success small mb-2"><i class="bi bi-plus-circle me-1"></i>Add New Pharmacy Line (Auto-saves & updates parent total)</div>
            <form method="POST">
                <input type="hidden" name="ph_receivable_id" value="${data.id}">
                <div class="card border shadow-sm pharmacy-line-card" style="border-color:#198754;" id="${editRowId}">
                    <div class="card-body p-3 bg-light">
                        <div class="row g-2 mb-2">
                            <div class="col-md-4">
                                <label class="small text-muted mb-1">GACN Number</label>
                                <input type="text" name="ph_gacn_number" class="form-control form-control-sm font-monospace border-success" required>
                            </div>
                            <div class="col-md-4">
                                <label class="small text-muted mb-1">GACN Date</label>
                                <input type="date" name="ph_gacn_date" class="form-control form-control-sm border-success" required>
                            </div>
                            <div class="col-md-4">
                                <label class="small text-muted mb-1">PABN No.</label>
                                <input type="text" name="ph_pabn_number" class="form-control form-control-sm">
                            </div>
                        </div>

                        <div class="row g-2 mt-2 pt-2 border-top">
                            <div class="col-md-3">
                                <label class="small text-muted mb-1">Gross</label>
                                <input type="text" name="ph_gross" class="form-control form-control-sm bg-white ph-gross text-end fw-bold text-dark px-1" placeholder="0.00" oninput="formatAmount(this); autoCalcPhRowNet(this)">
                            </div>
                            <div class="col-md-3">
                                <label class="small text-danger mb-1">Tax</label>
                                <input type="text" name="ph_tax" class="form-control form-control-sm ph-tax text-end text-danger fw-bold px-1" placeholder="0.00" oninput="formatAmount(this); autoCalcPhRowNet(this)">
                            </div>
                            <div class="col-md-3">
                                <label class="small text-muted mb-1">Net Amount</label>
                                <input type="text" name="ph_net" class="form-control form-control-sm ph-net text-end text-success fw-bold bg-white px-1" readonly placeholder="0.00">
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <button type="submit" name="add_pharmacy_line" class="btn btn-sm btn-success fw-bold w-100 py-1"><i class="bi bi-save me-1"></i>Save Line</button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        `;
    }

    function viewClaimLines(data) {
        document.getElementById('cl_view_title').innerHTML = `<i class="bi bi-hospital me-2"></i>${data.facility_name} — ${data.package_category}`;
        const tbody = document.getElementById('claimLinesViewBody');
        tbody.innerHTML = '';
        (data.claim_lines || []).forEach(cl => {
            tbody.innerHTML += `<tr>
                <td class="font-monospace">${cl.pabn_number || ''}</td>
                <td class="font-monospace">${cl.series_number || ''}</td>
                <td class="font-monospace">${cl.member_pin || ''}</td>
                <td class="fw-bold">${cl.patient_name || ''}</td>
                <td>${cl.confinement_period || ''}</td>
                <td>${cl.caserate1_code || ''}</td><td class="text-end">${fmtNum(cl.caserate1_gross)}</td>
                <td>${cl.caserate2_code || ''}</td><td class="text-end">${fmtNum(cl.caserate2_gross)}</td>
                <td>${cl.caserate3_code || ''}</td><td class="text-end">${fmtNum(cl.caserate3_gross)}</td>
                <td class="text-end fw-bold text-dark">${fmtNum(cl.gross)}</td>
                <td class="text-end text-danger">${fmtNum(cl.withholding_tax)}</td>
                <td class="text-end text-primary">${fmtNum(cl.hci)}</td>
                <td class="text-end text-success">${fmtNum(cl.pf)}</td>
            </tr>`;
        });
        new bootstrap.Modal(document.getElementById('claimLinesModal')).show();
    }

    function viewPharmacyLines(data) {
        document.getElementById('ph_view_title').innerHTML = `<i class="bi bi-hospital me-2"></i>${data.facility_name} — ${data.package_category}`;
        const tbody = document.getElementById('pharmacyLinesViewBody');
        tbody.innerHTML = '';
        (data.pharmacy_lines || []).forEach(ph => {
            let dString = ph.gacn_date || 'N/A';
            tbody.innerHTML += `<tr>
                <td>${dString}</td>
                <td class="font-monospace">${ph.gacn_number || ''}</td>
                <td class="font-monospace">${ph.pabn_number || ''}</td>
                <td class="text-end fw-bold text-dark">${fmtNum(ph.gross)}</td>
                <td class="text-end text-danger">${fmtNum(ph.withholding_tax)}</td>
                <td class="text-end text-success fw-bold">${fmtNum(ph.net_amount)}</td>
            </tr>`;
        });
        new bootstrap.Modal(document.getElementById('pharmacyLinesModal')).show();
    }

    function openReconcile(data) {
        document.getElementById('rec_id').value = data.id;
        document.getElementById('rec_amount_display').innerText = '₱ ' + fmtNum(data.amount);
        new bootstrap.Modal(document.getElementById('reconcileModal')).show();
    }
    function openOrFill(recId) {
        document.getElementById('or_rec_id').value = recId;
        new bootstrap.Modal(document.getElementById('orFillModal')).show();
    }
    if (window.history.replaceState) { window.history.replaceState(null, null, window.location.href); }
    window.editClaimLine = function(btn) {
        try {
            if (!btn.hasAttribute('data-cl')) {
                btn = btn.closest('[data-cl]');
            }
            if (!btn) throw new Error("Could not find button with data-cl");
            
            let clStr = btn.getAttribute('data-cl');
            let cl = JSON.parse(decodeURIComponent(clStr));
            const area = document.getElementById('addClaimLineFormArea');
            
            if (!area) throw new Error("Could not find addClaimLineFormArea");

            if (!area.querySelector('input[name="edit_cl_id"]')) {
                let form = area.querySelector('form');
                if (form) form.insertAdjacentHTML('afterbegin', '<input type="hidden" name="edit_cl_id" id="edit_cl_id">');
            }
            let idInput = area.querySelector('#edit_cl_id');
            if (idInput) idInput.value = cl.id;
            
            let titleEl = area.querySelector('.fw-bold.text-success.small');
            if (titleEl) {
                titleEl.innerHTML = '<i class="bi bi-pencil me-1"></i>Edit Patient Line (Auto-saves & updates parent total)';
                titleEl.classList.remove('text-success');
                titleEl.classList.add('text-primary');
            }

            let submitBtn = area.querySelector('button[name="add_claim_line"]') || area.querySelector('button[name="update_claim_line"]');
            if (submitBtn) {
                submitBtn.setAttribute('name', 'update_claim_line');
                submitBtn.className = 'btn btn-sm btn-primary w-100 fw-bold';
                submitBtn.innerHTML = 'Update Claim Line';
            }
            
            const q = (name) => area.querySelector(`[name="${name}"]`);
            if(q('cl_pabn_number')) q('cl_pabn_number').value = cl.pabn_number || '';
            if(q('cl_series_number')) q('cl_series_number').value = cl.series_number || '';
            if(q('cl_member_pin')) q('cl_member_pin').value = cl.member_pin || '';
            if(q('cl_patient_name')) q('cl_patient_name').value = cl.patient_name || '';
            
            if(cl.confinement_period) {
                let parts = cl.confinement_period.split(' to ');
                if (parts.length === 2 && q('cl_conf_start') && q('cl_conf_end')) {
                    let startD = new Date(parts[0]);
                    let endD = new Date(parts[1]);
                    if(!isNaN(startD)) q('cl_conf_start').value = startD.getFullYear() + '-' + ('0'+(startD.getMonth()+1)).slice(-2) + '-' + ('0'+startD.getDate()).slice(-2);
                    if(!isNaN(endD)) q('cl_conf_end').value = endD.getFullYear() + '-' + ('0'+(endD.getMonth()+1)).slice(-2) + '-' + ('0'+endD.getDate()).slice(-2);
                }
            }

            if(q('cl_cr1_code')) q('cl_cr1_code').value = cl.caserate1_code || '';
            if(q('cl_cr1_gross')) q('cl_cr1_gross').value = typeof fmtNum === 'function' ? fmtNum(cl.caserate1_gross || 0) : cl.caserate1_gross;
            
            if(cl.caserate2_code || cl.caserate3_code) {
                let toggle = area.querySelector('#toggle_edit_add_cl');
                if (toggle && !toggle.checked) {
                    toggle.checked = true;
                    if(typeof toggleAdditionalCaserates === 'function') toggleAdditionalCaserates('edit_add_cl');
                }
            }

            if(q('cl_cr2_code')) q('cl_cr2_code').value = cl.caserate2_code || '';
            if(q('cl_cr2_gross')) q('cl_cr2_gross').value = typeof fmtNum === 'function' ? fmtNum(cl.caserate2_gross || 0) : cl.caserate2_gross;
            
            if(q('cl_cr3_code')) q('cl_cr3_code').value = cl.caserate3_code || '';
            if(q('cl_cr3_gross')) q('cl_cr3_gross').value = typeof fmtNum === 'function' ? fmtNum(cl.caserate3_gross || 0) : cl.caserate3_gross;
            
            if(q('cl_gross')) q('cl_gross').value = typeof fmtNum === 'function' ? fmtNum(cl.gross || 0) : cl.gross;
            if(q('cl_tax')) q('cl_tax').value = typeof fmtNum === 'function' ? fmtNum(cl.withholding_tax || 0) : cl.withholding_tax;
            if(q('cl_hci')) q('cl_hci').value = typeof fmtNum === 'function' ? fmtNum(cl.hci || 0) : cl.hci;
            if(q('cl_pf')) q('cl_pf').value = typeof fmtNum === 'function' ? fmtNum(cl.pf || 0) : cl.pf;

            let card = area.querySelector('.card');
            if (card) {
                card.classList.remove('border-success');
                card.classList.add('border-primary');
                card.style.backgroundColor = '#e7f1ff';
                setTimeout(() => { card.style.transition = 'background-color 0.5s'; card.style.backgroundColor = ''; }, 300);
            }
        } catch (e) {
            console.error("Error editing claim line:", e);
            alert("Error: " + e.message);
        }
    };

    document.addEventListener('input', function(e) {
        if (!e.target || !e.target.name) return;
        const name = e.target.name;

        if (name === 'cl_pabn_number' || name === 'cl_pabn_number[]' || name === 'ph_pabn_number') {
            let val = e.target.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
            let formatted = '';
            if (val.length > 0) {
                formatted += val.substring(0, 3);
                if (val.length > 3) formatted += '-' + val.substring(3, 7);
                if (val.length > 7) formatted += '-' + val.substring(7, 12);
            }
            e.target.value = formatted;
        } else if (name === 'cl_series_number' || name === 'cl_series_number[]') {
            e.target.value = e.target.value.replace(/[^0-9]/g, '').substring(0, 13);
        } else if (name === 'cl_member_pin' || name === 'cl_member_pin[]') {
            e.target.value = e.target.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase().substring(0, 13);
        } else if (['cl_cr1_code', 'cl_cr2_code', 'cl_cr3_code', 'cl_cr1_code[]', 'cl_cr2_code[]', 'cl_cr3_code[]'].includes(name)) {
            e.target.value = e.target.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase().substring(0, 5);
        }
    });
</script>
<?php require 'includes/footer.php'; ?>