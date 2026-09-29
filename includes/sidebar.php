<?php
// Sidebar navigation component
?>

<aside class="sidebar bg-light border-end" style="min-height: 100vh;">
    <nav class="navbar navbar-expand-lg navbar-light bg-light flex-column p-3">
        <a class="navbar-brand fw-bold mb-4" href="index.php">
            <i class="bi bi-calculator"></i> CHO Budget
        </a>
        
        <button class="navbar-toggler d-lg-none" type="button" data-bs-toggle="collapse" data-bs-target="#sidebarNav" aria-controls="sidebarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        
        <div class="collapse navbar-collapse w-100" id="sidebarNav">
            <ul class="navbar-nav flex-column w-100">
                <li class="nav-item mb-2">
                    <a class="nav-link text-dark" href="dashboard.php">
                        <i class="bi bi-house-door"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item mb-2">
                    <a class="nav-link text-dark" href="encode_obligation.php">
                        <i class="bi bi-pencil-square"></i> Encode Obligation
                    </a>
                </li>
                <li class="nav-item mb-2">
                    <a class="nav-link text-dark" href="settings.php">
                        <i class="bi bi-gear"></i> Settings
                    </a>
                </li>
            </ul>
        </div>
    </nav>
</aside>
