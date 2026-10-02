<?php
/**
 * CHO Procurement Bridge for CHO Budget System
 * Facilitates cross-database communication between cho_budget_db and cho_procurement_db.
 */

// 18 Canonical Stages matching the Parañaque City Health Office physical green folder routing slip
const PROCUREMENT_ROUTING_STAGES = [
    1 => [
        'name' => 'SUBMISSION OF REQUISITION TO CHO',
        'department' => 'City Health Office (Admin)',
        'dept_code' => 'CHO',
        'phase' => 'Requisition',
    ],
    2 => [
        'name' => 'REQUISITION FOR SIGNATURE OIC-CHO',
        'department' => 'OIC-CHO',
        'dept_code' => 'OIC-CHO',
        'phase' => 'Requisition',
    ],
    3 => [
        'name' => 'GSO(REQUISITION SIGNED,PR,GSO ESTIMATE)',
        'department' => 'General Services Office',
        'dept_code' => 'GSO',
        'phase' => 'Requisition',
    ],
    4 => [
        'name' => 'REQUISITION FOR SIGNATURE BUDGET',
        'department' => 'City Budget Office',
        'dept_code' => 'BUDGET',
        'phase' => 'Requisition',
    ],
    5 => [
        'name' => 'REQUISITION FOR SIGNATURE MAYOR',
        'department' => "Office of the City Mayor",
        'dept_code' => 'MAYOR',
        'phase' => 'Requisition',
    ],
    6 => [
        'name' => 'PURCHASE REQUEST:FOR SIGNATURE OIC-CHO',
        'department' => 'OIC-CHO',
        'dept_code' => 'OIC-CHO',
        'phase' => 'Purchase Request',
    ],
    7 => [
        'name' => 'PURCHASE REQUEST:FOR SIGNATURE TREASURY',
        'department' => "City Treasurer's Office",
        'dept_code' => 'TREASURY',
        'phase' => 'Purchase Request',
    ],
    8 => [
        'name' => 'PURCHASE REQUEST:FOR SIGNATURE MAYOR',
        'department' => "Office of the City Mayor",
        'dept_code' => 'MAYOR',
        'phase' => 'Purchase Request',
    ],
    9 => [
        'name' => 'CERTIFICATION BUDGET',
        'department' => 'City Budget Office',
        'dept_code' => 'BUDGET',
        'phase' => 'Certifications & BAC',
    ],
    10 => [
        'name' => 'CERTIFICATION PLANNING',
        'department' => 'City Planning & Development',
        'dept_code' => 'PLANNING',
        'phase' => 'Certifications & BAC',
    ],
    11 => [
        'name' => 'PPMP (BAC,BUDGET,CHO COPY)',
        'department' => 'BAC Secretariat & Budget',
        'dept_code' => 'BAC',
        'phase' => 'Certifications & BAC',
    ],
    12 => [
        'name' => 'BAC COPY',
        'department' => 'BAC Secretariat',
        'dept_code' => 'BAC',
        'phase' => 'Certifications & BAC',
    ],
    13 => [
        'name' => 'ALOBS:FOR SIGNATURE OIC-CHO',
        'department' => 'OIC-CHO',
        'dept_code' => 'OIC-CHO',
        'phase' => 'Obligation (ALOBS)',
    ],
    14 => [
        'name' => 'ALOBS:FOR SIGNATURE BUDGET',
        'department' => 'City Budget Office',
        'dept_code' => 'BUDGET',
        'phase' => 'Obligation (ALOBS)',
    ],
    15 => [
        'name' => 'ALOBS:FOR SIGNATURE ACCOUNTING',
        'department' => 'City Accounting Office',
        'dept_code' => 'ACCOUNTING',
        'phase' => 'Obligation (ALOBS)',
    ],
    16 => [
        'name' => 'VOUCHER:FOR SIGNATURE OIC-CHO',
        'department' => 'OIC-CHO',
        'dept_code' => 'OIC-CHO',
        'phase' => 'Disbursement Voucher',
    ],
    17 => [
        'name' => 'VOUCHER:FOR SIGNATURE TREASURY',
        'department' => "City Treasurer's Office",
        'dept_code' => 'TREASURY',
        'phase' => 'Disbursement Voucher',
    ],
    18 => [
        'name' => 'VOUCHER:FOR SIGNATURE MAYOR',
        'department' => "Office of the City Mayor",
        'dept_code' => 'MAYOR',
        'phase' => 'Disbursement Voucher',
    ],
];

/**
 * Ensure document_code column exists in cho_budget_db.obligations
 */
function ensure_obligations_has_document_code_column(PDO $pdo): void {
    static $checked = false;
    if ($checked) return;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM cho_budget_db.obligations LIKE 'document_code'")->fetch();
        if (!$cols) {
            $pdo->exec("ALTER TABLE cho_budget_db.obligations ADD COLUMN document_code VARCHAR(50) NULL AFTER obr_number, ADD INDEX idx_doc_code (document_code)");
        }
        $checked = true;
    } catch (\Throwable $e) {
        // Silently continue if column already exists or restricted
    }
}

/**
 * Initialize 18 stages for a procurement project if missing
 */
function bridge_init_project_stages(PDO $pdo, int $projectId): void {
    $count = $pdo->query("SELECT COUNT(*) FROM cho_procurement_db.document_tracking_stages WHERE project_id = {$projectId}")->fetchColumn();
    if ($count >= 18) {
        return;
    }

    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM cho_procurement_db.document_tracking_stages WHERE project_id = ? AND stage_order = ?");
    $stmtInsert = $pdo->prepare("INSERT INTO cho_procurement_db.document_tracking_stages 
        (project_id, stage_order, stage_name, department, phase, date_submitted, date_released, status, remarks, created_at, updated_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");

    $today = date('Y-m-d');
    foreach (PROCUREMENT_ROUTING_STAGES as $order => $meta) {
        $stmtCheck->execute([$projectId, $order]);
        if ($stmtCheck->fetchColumn() == 0) {
            $status = ($order === 1) ? 'IN_PROGRESS' : 'PENDING';
            $dateSub = ($order === 1) ? $today : null;
            $stmtInsert->execute([
                $projectId,
                $order,
                $meta['name'],
                $meta['department'],
                $meta['phase'],
                $dateSub,
                null,
                $status,
                null
            ]);
        }
    }
}

/**
 * Unlink an obligation from its procurement tracking code.
 * Sets document_code to NULL, strips legacy PROC- code from status_routing,
 * and records an unlinking log in routing_history.
 */
function unlink_procurement_code_from_obligation(PDO $pdo, int $obligationId, string $username = 'admin'): array {
    ensure_obligations_has_document_code_column($pdo);

    $stmt = $pdo->prepare("SELECT id, transaction_ref, document_code, status_routing FROM cho_budget_db.obligations WHERE id = ?");
    $stmt->execute([$obligationId]);
    $ob = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ob) {
        return ['success' => false, 'message' => 'Obligation not found.'];
    }

    $oldCode = $ob['document_code'] ?? '';

    // Clean any legacy PROC-XXXX from status_routing so it cannot trigger legacy detection
    $cleanRouting = preg_replace('/(\r?\n)*\bPROC-\d{4}-[A-Z0-9]+\b/i', '', $ob['status_routing'] ?? '');
    $cleanRouting = trim($cleanRouting);

    $pdo->prepare("UPDATE cho_budget_db.obligations SET document_code = NULL, status_routing = ? WHERE id = ?")
        ->execute([($cleanRouting !== '' ? $cleanRouting : null), $obligationId]);

    // Record in routing_history (Document Diary)
    $logMsg = "UNLINKED FROM PROCUREMENT DOCUMENT" . ($oldCode ? ": {$oldCode}" : "");
    $pdo->prepare("INSERT INTO cho_budget_db.routing_history (obligation_id, user_name, status, created_at) VALUES (?, ?, ?, NOW())")
        ->execute([$obligationId, $username, $logMsg]);

    return [
        'success' => true,
        'message' => "Unlinked procurement code " . ($oldCode ?: "") . " from this obligation.",
        'old_code' => $oldCode
    ];
}

/**
 * Get linked procurement project & routing status for an obligation
 */
function get_linked_procurement_project(PDO $pdo, array $obligation): ?array {
    ensure_obligations_has_document_code_column($pdo);

    $code = !empty($obligation['document_code']) ? trim($obligation['document_code']) : '';

    if (empty($code)) {
        return null;
    }

    // Query project from cho_procurement_db
    $stmt = $pdo->prepare("SELECT * FROM cho_procurement_db.requisition_projects WHERE document_code = ?");
    $stmt->execute([$code]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);

    // If project not in procurement db yet, create it automatically so it tracks immediately
    if (!$project) {
        try {
            $title = !empty($obligation['ppa_name']) ? $obligation['ppa_name'] : ('Procurement Obligation ' . ($obligation['transaction_ref'] ?? $code));
            $fund = !empty($obligation['fund_name']) ? $obligation['fund_name'] : 'General Fund';
            $year = !empty($obligation['obligation_date']) ? date('Y', strtotime($obligation['obligation_date'])) : date('Y');

            $stmtIns = $pdo->prepare("INSERT INTO cho_procurement_db.requisition_projects 
                (document_code, user_id, title, fund_source, is_locked, status, target_year, created_at, updated_at) 
                VALUES (?, 1, ?, ?, 1, 'LOCKED', ?, NOW(), NOW())");
            $stmtIns->execute([$code, $title, $fund, $year]);
            $newId = (int) $pdo->lastInsertId();

            bridge_init_project_stages($pdo, $newId);

            $stmt->execute([$code]);
            $project = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [
                'document_code' => $code,
                'project' => null,
                'error' => 'Unable to initialize procurement project: ' . $e->getMessage()
            ];
        }
    }

    if (!$project) {
        return null;
    }

    $projectId = (int) $project['id'];
    bridge_init_project_stages($pdo, $projectId);

    // Fetch stages
    $stmtStages = $pdo->prepare("SELECT * FROM cho_procurement_db.document_tracking_stages WHERE project_id = ? ORDER BY stage_order ASC");
    $stmtStages->execute([$projectId]);
    $stages = $stmtStages->fetchAll(PDO::FETCH_ASSOC);

    $completedCount = 0;
    $currentStage = null;

    foreach ($stages as $st) {
        if ($st['status'] === 'COMPLETED') {
            $completedCount++;
        }
    }

    // Find current active stage
    foreach ($stages as $st) {
        if ($st['status'] === 'IN_PROGRESS') {
            $currentStage = $st;
            break;
        }
    }
    if (!$currentStage && $completedCount < 18) {
        foreach ($stages as $st) {
            if ($st['status'] === 'PENDING') {
                $currentStage = $st;
                break;
            }
        }
    }
    if (!$currentStage && !empty($stages)) {
        $currentStage = end($stages);
    }

    $daysInStage = 0;
    if ($currentStage && $currentStage['status'] !== 'COMPLETED') {
        $subDate = !empty($currentStage['date_submitted']) ? $currentStage['date_submitted'] : ($currentStage['created_at'] ?? date('Y-m-d'));
        $daysInStage = max(0, (int) ((time() - strtotime($subDate)) / 86400));
    }

    $progressPercent = (int) round(($completedCount / 18) * 100);
    $overallStatus = ($completedCount >= 18) ? 'COMPLETED' : 'ROUTING';

    $isCancelled = ($project['status'] ?? '') === 'CANCELLED';
    $isDraft = (int)($project['is_locked'] ?? 1) === 0;

    return [
        'document_code' => $code,
        'project' => $project,
        'stages' => $stages,
        'current_stage' => $currentStage,
        'completed_count' => $completedCount,
        'progress_percent' => $progressPercent,
        'days_in_stage' => $daysInStage,
        'is_delayed' => ($daysInStage >= 5),
        'overall_status' => $isCancelled ? 'CANCELLED' : ($isDraft ? 'DRAFT' : $overallStatus),
        'is_cancelled' => $isCancelled,
        'is_draft' => $isDraft
    ];
}

/**
 * Forward a procurement stage from CHO Budget
 * Updates cho_procurement_db AND appends to cho_budget_db.routing_history (the Document Diary)
 */
function forward_procurement_stage_from_budget(
    PDO $pdo,
    int $obligationId,
    int $projectId,
    int $stageOrder,
    string $dateReleased,
    string $remarks,
    string $username
): array {
    try {
        $dateReleased = !empty($dateReleased) ? date('Y-m-d', strtotime($dateReleased)) : date('Y-m-d');
        $remarks = trim($remarks);

        // 1. Fetch current stage
        $stmtCurr = $pdo->prepare("SELECT * FROM cho_procurement_db.document_tracking_stages WHERE project_id = ? AND stage_order = ?");
        $stmtCurr->execute([$projectId, $stageOrder]);
        $currentStage = $stmtCurr->fetch(PDO::FETCH_ASSOC);

        if (!$currentStage) {
            return ['success' => false, 'message' => "Stage {$stageOrder} not found."];
        }

        // 2. Mark current stage as COMPLETED
        $stmtUp = $pdo->prepare("UPDATE cho_procurement_db.document_tracking_stages 
            SET status = 'COMPLETED', date_released = ?, remarks = ?, updated_at = NOW() 
            WHERE project_id = ? AND stage_order = ?");
        $stmtUp->execute([$dateReleased, ($remarks ?: $currentStage['remarks']), $projectId, $stageOrder]);

        // 3. Log into cho_procurement_db.document_tracking_logs
        $actionNote = "Stage {$stageOrder} ({$currentStage['stage_name']}) released on {$dateReleased}. " . ($remarks ? "Remarks: {$remarks}" : "");
        $stmtLog = $pdo->prepare("INSERT INTO cho_procurement_db.document_tracking_logs 
            (project_id, stage_order, action, notes, user_id, created_at) 
            VALUES (?, ?, 'STAGE_RELEASED', ?, NULL, NOW())");
        $stmtLog->execute([$projectId, $stageOrder, $actionNote]);

        // 4. Advance next stage if <= 18
        $nextOrder = $stageOrder + 1;
        $nextStageName = "";
        if ($nextOrder <= 18) {
            $stmtNext = $pdo->prepare("SELECT * FROM cho_procurement_db.document_tracking_stages WHERE project_id = ? AND stage_order = ?");
            $stmtNext->execute([$projectId, $nextOrder]);
            $nextStage = $stmtNext->fetch(PDO::FETCH_ASSOC);

            if ($nextStage && $nextStage['status'] !== 'COMPLETED') {
                $nextStageName = $nextStage['stage_name'];
                $pdo->prepare("UPDATE cho_procurement_db.document_tracking_stages 
                    SET status = 'IN_PROGRESS', date_submitted = ?, updated_at = NOW() 
                    WHERE project_id = ? AND stage_order = ?")
                    ->execute([$dateReleased, $projectId, $nextOrder]);

                $nextNote = "Forwarded to Stage {$nextOrder} ({$nextStage['stage_name']}) on {$dateReleased}";
                $stmtLog->execute([$projectId, $nextOrder, $nextNote]);
            }
        }

        // 5. AUTO-APPEND TO CHO BUDGET DOCUMENT DIARY (routing_history table)
        // Format matches official routing diary style
        $stageSummary = "STAGE {$stageOrder} RELEASED: " . $currentStage['stage_name'] . " on " . date('m/d/Y', strtotime($dateReleased));
        if (!empty($nextStageName)) {
            $stageSummary .= " -> FORWARDED TO STAGE {$nextOrder}: {$nextStageName}";
        }
        if (!empty($remarks)) {
            $stageSummary .= " [Remarks: {$remarks}]";
        }

        // Append to routing_history
        $stmtDiary = $pdo->prepare("INSERT INTO cho_budget_db.routing_history (obligation_id, user_name, status, created_at) VALUES (?, ?, ?, NOW())");
        $stmtDiary->execute([$obligationId, $username, $stageSummary]);

        // 6. Update obligations.status_routing with the latest stage info
        $newStatusRouting = "STAGE {$nextOrder}: " . ($nextStageName ?: 'COMPLETED ALL 18 STAGES') . " (Updated on " . date('m/d/Y') . ")";
        $pdo->prepare("UPDATE cho_budget_db.obligations SET status_routing = ? WHERE id = ?")->execute([$newStatusRouting, $obligationId]);

        return [
            'success' => true,
            'message' => "Stage {$stageOrder} released and forwarded successfully! Diary log recorded.",
            'next_stage' => $nextOrder,
            'next_stage_name' => $nextStageName
        ];
    } catch (\Throwable $e) {
        return ['success' => false, 'message' => "Error advancing stage: " . $e->getMessage()];
    }
}

/**
 * Get available procurement projects that are NOT yet linked to any CHO Budget obligation.
 * Optionally excludes a specific obligation ID (useful when editing an existing obligation).
 */
function get_available_unlinked_procurement_projects(PDO $pdo, ?int $excludeObligationId = null): array {
    ensure_obligations_has_document_code_column($pdo);

    $excludeSql = $excludeObligationId ? " AND id != " . (int)$excludeObligationId : "";
    $linkedCodes = $pdo->query("SELECT DISTINCT document_code FROM cho_budget_db.obligations WHERE document_code IS NOT NULL AND document_code != '' {$excludeSql}")->fetchAll(PDO::FETCH_COLUMN);

    if (empty($linkedCodes)) {
        $stmt = $pdo->query("SELECT id, document_code, title, fund_source, target_year, is_locked, status 
            FROM cho_procurement_db.requisition_projects 
            WHERE document_code IS NOT NULL AND document_code != '' 
              AND is_locked = 1 
              AND status != 'CANCELLED' 
            ORDER BY id DESC LIMIT 60");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $inPlaceholders = implode(',', array_fill(0, count($linkedCodes), '?'));
    $stmt = $pdo->prepare("SELECT id, document_code, title, fund_source, target_year, is_locked, status 
        FROM cho_procurement_db.requisition_projects 
        WHERE document_code IS NOT NULL AND document_code != '' 
          AND is_locked = 1 
          AND status != 'CANCELLED' 
          AND document_code NOT IN ({$inPlaceholders}) 
        ORDER BY id DESC LIMIT 60");
    $stmt->execute($linkedCodes);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Check if a procurement document code is already linked to another obligation.
 * Returns the existing obligation row (id, transaction_ref, ppa_name) if already linked, or null.
 */
function check_procurement_code_already_linked(PDO $pdo, string $documentCode, ?int $excludeObligationId = null): ?array {
    ensure_obligations_has_document_code_column($pdo);

    $cleanCode = strtoupper(trim($documentCode));
    if (empty($cleanCode)) {
        return null;
    }

    $sql = "SELECT id, transaction_ref, ppa_name, program_unit 
            FROM cho_budget_db.obligations 
            WHERE document_code = ?";
    $params = [$cleanCode];

    if ($excludeObligationId) {
        $sql .= " AND id != ?";
        $params[] = $excludeObligationId;
    }

    $sql .= " LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    return $existing ?: null;
}

/**
 * Look up procurement project lifecycle state by document code in cho_procurement_db.
 * Returns project details including is_locked, status, cancel_reason, or null if not found.
 */
function check_procurement_project_state(PDO $pdo, string $documentCode): ?array {
    $cleanCode = strtoupper(trim($documentCode));
    if (empty($cleanCode)) return null;

    try {
        $stmt = $pdo->prepare("SELECT id, document_code, title, fund_source, target_year, is_locked, status, cancel_reason 
            FROM cho_procurement_db.requisition_projects 
            WHERE document_code = ? LIMIT 1");
        $stmt->execute([$cleanCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (\Throwable $e) {
        return null;
    }
}

