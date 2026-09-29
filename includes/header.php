<?php
// Ensure session is started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Get Session Data
$role = $_SESSION['role'] ?? 'viewer';
$username = $_SESSION['username'] ?? 'Guest';
// If you added fullname to session in login, use it here. Otherwise fallback to username.
$display_name = $_SESSION['fullname'] ?? $username; 

// Default page title fallback to prevent undefined variable notices
$page_title = $page_title ?? 'CHO Budget System';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=Roboto+Condensed:wght@400;500;700&display=swap" rel="stylesheet">
    
    <style>
        body { 
            background-color: #f8f9fa; 
            font-family: 'Inter', sans-serif;
        }
        /* Navbar Tweaks */
        .navbar { box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .navbar-brand { font-family: 'Roboto Condensed', sans-serif; font-weight: 700; letter-spacing: 0.5px; }
        .nav-link { font-size: 0.95rem; font-weight: 500; }
        
        /* Dropdown Styling */
        .dropdown-menu { font-size: 0.9rem; }
        .dropdown-item.active { background-color: #0d6efd; color: white; }
        .dropdown-item i { width: 20px; text-align: center; }

        /* User Profile Section in Nav */
        .user-profile-box { line-height: 1.2; }
        .user-name { font-size: 0.9rem; font-weight: 600; }
        .user-role { font-size: 0.7rem; letter-spacing: 0.5px; opacity: 0.8; }

        /* Print Hiding */
        @media print {
            .print-hide { display: none !important; }
            .card { border: none !important; box-shadow: none !important; }
            .main-content { margin: 0 !important; padding: 0 !important; }
        }
    </style>
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4 print-hide">
    <div class="container-fluid px-4">
        
        <a class="navbar-brand text-nowrap" href="dashboard.php">
            <i class="bi bi-wallet2 me-2 text-warning"></i>
            CHO Budget System
        </a>
        
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse flex-grow-1" id="navbarNav">
            <ul class="navbar-nav ms-auto align-items-center text-nowrap">
                
                <li class="nav-item">
                    <a class="nav-link px-3 <?php echo ($page_title == 'Dashboard') ? 'active fw-bold text-warning' : ''; ?>" href="dashboard.php">
                        <i class="bi bi-speedometer2 me-1"></i> Dashboard
                    </a>
                </li>
                
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle px-3 <?php echo in_array($page_title, ['Cash Inflows Ledger', 'Petty Cash Management']) ? 'active fw-bold text-warning' : ''; ?>" href="#" id="fundsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-cash-stack me-1"></i> Cash & Funds
                    </a>
                    <ul class="dropdown-menu dropdown-menu-dark shadow border-0" aria-labelledby="fundsDropdown">
                        <li>
                            <a class="dropdown-item <?php echo ($page_title == 'Cash Inflows Ledger') ? 'active' : ''; ?>" href="cash_inflows.php">
                                <i class="bi bi-box-arrow-in-down-right me-2 text-success"></i> Bank Receipts (GL)
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item <?php echo ($page_title == 'PhilHealth Receivables') ? 'active' : ''; ?>" href="philhealth_receivables.php">
                                <i class="bi bi-file-earmark-medical me-2 text-primary"></i> PhilHealth ACPNs (Sub)
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item <?php echo ($page_title == 'Petty Cash Management') ? 'active' : ''; ?>" href="petty_cash.php">
                                <i class="bi bi-wallet2 me-2 text-info"></i> Petty Cash
                            </a>
                        </li>
                    </ul>
                </li>

                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle px-3 <?php echo in_array($page_title, ['Obligation Log', 'Create Obligation', 'Edit Obligation']) ? 'active fw-bold text-warning' : ''; ?>" href="#" id="expendituresDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-cart-dash me-1"></i> Expenditures
                    </a>
                    <ul class="dropdown-menu dropdown-menu-dark shadow border-0" aria-labelledby="expendituresDropdown">
                        <li>
                            <a class="dropdown-item <?php echo ($page_title == 'Obligation Log') ? 'active' : ''; ?>" href="view_obligations.php">
                                <i class="bi bi-journal-text me-2 text-primary"></i> Master Obligation Log
                            </a>
                        </li>
                        <?php if ($role !== 'viewer'): ?>
                        <li>
                            <a class="dropdown-item <?php echo ($page_title == 'Create Obligation') ? 'active' : ''; ?>" href="create_obligation.php">
                                <i class="bi bi-plus-circle me-2 text-success"></i> New Obligation
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </li>

                <?php if ($role === 'admin'): ?>
                <li class="nav-item border-start border-secondary mx-2 d-none d-lg-block"></li> 
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle px-3 <?php echo in_array($page_title, ['Budget Setup', 'Manage Facilities', 'Manage Users', 'Audit Trail', 'Settings']) ? 'active fw-bold text-warning' : ''; ?>" href="#" id="adminDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-shield-lock me-1"></i> Administrator
                    </a>
                    <ul class="dropdown-menu dropdown-menu-dark shadow border-0" aria-labelledby="adminDropdown">
                        <li>
                            <a class="dropdown-item <?php echo ($page_title == 'Settings') ? 'active' : ''; ?>" href="settings.php">
                                <i class="bi bi-gear me-2"></i> System Settings
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item <?php echo ($page_title == 'LHB Program Registry') ? 'active' : ''; ?>" href="lhb_programs.php">
                                <i class="bi bi-person-lines-fill me-2 text-warning"></i> LHB Program Registry
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item <?php echo ($page_title == 'Budget Setup') ? 'active' : ''; ?>" href="budget_matrix.php">
                                <i class="bi bi-table me-2"></i> Budget Setup
                            </a>    
                        </li>
                        <li>
                            <a class="dropdown-item <?php echo ($page_title == 'Budget Realignments') ? 'active' : ''; ?>" href="realignments.php">
                                <i class="bi bi-arrow-left-right me-2 text-warning"></i> Realignments
                            </a>
                        </li>
                        <li><hr class="dropdown-divider border-secondary"></li>
                        <li>
                            <a class="dropdown-item <?php echo ($page_title == 'Manage Facilities') ? 'active' : ''; ?>" href="manage_facilities.php">
                                <i class="bi bi-hospital me-2 text-info"></i> Manage Facilities
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item <?php echo ($page_title == 'Manage Users') ? 'active' : ''; ?>" href="manage_users.php">
                                <i class="bi bi-people me-2"></i> Manage Users
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item <?php echo ($page_title == 'Audit Trail') ? 'active' : ''; ?>" href="activity_log.php">
                                <i class="bi bi-list-check me-2 text-warning"></i> Audit Trail
                            </a>
                        </li>
                    </ul>
                </li>
                <?php endif; ?>

                <li class="nav-item border-start border-secondary ms-3 ps-3 d-flex align-items-center">
                    <div class="text-end text-white me-2 user-profile-box">
                        <div class="user-name"><?php echo htmlspecialchars($display_name); ?></div>
                        <div class="user-role text-warning text-uppercase"><?php echo htmlspecialchars($role); ?></div>
                    </div>
                    <div class="rounded-circle bg-secondary d-flex align-items-center justify-content-center text-white" style="width: 38px; height: 38px;">
                        <i class="bi bi-person-fill fs-5"></i>
                    </div>
                </li>

                <li class="nav-item ms-3">
                    <a class="btn btn-outline-light btn-sm" href="logout.php" title="Logout">
                        <i class="bi bi-box-arrow-right"></i>
                    </a>
                </li>

            </ul>
        </div>
    </div>
</nav>