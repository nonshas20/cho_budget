<footer class="footer mt-auto py-3 bg-white border-top no-print">
    <div class="container-fluid text-center">
        <span class="text-muted small">
            City Health Office - Budget Management System &copy; <?php echo date('Y'); ?> 
            <span class="mx-2">|</span> 
            <span class="fw-bold text-primary" style="font-family: 'Roboto Condensed', sans-serif;">v<?php echo APP_VERSION; ?></span>
        </span>
    </div>
</footer>

<script>
function exportTableToExcel(tableID, filename = '') {
    var downloadLink;
    var dataType = 'application/vnd.ms-excel';
    var tableSelect = document.getElementById(tableID);
    
    // Clean up HTML for Excel export (clone to avoid destroying visible table)
    var cloneTable = tableSelect.cloneNode(true);
    
    var tableHTML = cloneTable.outerHTML.replace(/ /g, '%20');
    
    filename = filename ? filename + '.xls' : 'excel_data.xls';
    downloadLink = document.createElement("a");
    document.body.appendChild(downloadLink);
    
    if(navigator.msSaveOrOpenBlob){
        var blob = new Blob(['\ufeff', tableHTML], { type: dataType });
        navigator.msSaveOrOpenBlob( blob, filename);
    } else {
        downloadLink.href = 'data:' + dataType + ', ' + tableHTML;
        downloadLink.download = filename;
        downloadLink.click();
    }
}
</script>

<script>
document.addEventListener('contextmenu', event => event.preventDefault());

document.onkeydown = function(e) {
    if(e.keyCode == 123) { return false; } // F12
    if(e.ctrlKey && e.shiftKey && e.keyCode == 'I'.charCodeAt(0)){ return false; } // Ctrl+Shift+I
    if(e.ctrlKey && e.shiftKey && e.keyCode == 'J'.charCodeAt(0)){ return false; } // Ctrl+Shift+J
    if(e.ctrlKey && e.keyCode == 'U'.charCodeAt(0)){ return false; } // Ctrl+U
}
</script>

<style>
@media print {
    /* 1. HIDE NON-ESSENTIALS */
    .print-hide, .navbar, .btn, form, .alert, .card-header button, .card-header a, footer { 
        display: none !important; 
    }

    /* 2. RESET LAYOUT & COLORS */
    body, .container, .container-fluid, .card, .card-body, .card-header {
        background-color: white !important;
        color: black !important;
        padding: 0 !important;
        margin: 0 !important;
        width: 100% !important;
        border: none !important;
        box-shadow: none !important;
    }

    /* 3. TABLE STYLING (The Data Sheet Look) */
    table { 
        width: 100% !important; 
        border-collapse: collapse !important; 
        font-family: 'Arial', sans-serif !important;
        font-size: 9pt !important; /* Compact font size */
    }
    
    th, td { 
        border: 1px solid #000 !important; /* Thin black borders */
        color: #000 !important; 
        padding: 4px 6px !important; 
        background-color: transparent !important; /* No background colors */
    }

    /* Header Specifics */
    thead th {
        font-weight: bold !important;
        text-transform: uppercase;
        background-color: transparent !important; /* Ensure headers are white */
        border-bottom: 2px solid #000 !important; /* Thicker header divider */
    }

    /* 4. OVERRIDE BOOTSTRAP COLORS (Force Black) */
    .text-primary, .text-success, .text-danger, .text-info, .text-warning, .text-muted, .text-white {
        color: #000 !important;
    }
    
    .bg-primary, .bg-success, .bg-danger, .bg-info, .bg-warning, .bg-secondary, .bg-dark, .table-dark, .table-striped, .table-hover {
        background-color: transparent !important;
        color: #000 !important;
    }

    /* 5. BADGES (Convert to Simple Borders) */
    .badge {
        border: 1px solid #000 !important;
        color: #000 !important;
        background: transparent !important;
        font-weight: normal;
        padding: 1px 3px;
        font-size: 0.75em;
    }

    /* 6. REPORT HEADER */
    /* Add a custom title to the top of the printout */
    .card-header::before {
        content: "CHO Budget Management System - Official Report";
        display: block;
        font-weight: bold;
        font-size: 14pt;
        text-align: center;
        margin-bottom: 5px;
        text-decoration: underline;
    }
    
    .card-header::after {
        content: "Generated on: " attr(data-date); /* Optional: Requires JS to set attribute, or just static text */
        display: block;
        font-size: 9pt;
        text-align: center;
        margin-bottom: 15px;
    }

    /* 7. PAGE SETUP */
    @page { 
        size: landscape; 
        margin: 1cm; 
    }
}
</style>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>