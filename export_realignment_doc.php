<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['role'])) { header("Location: index.php"); exit; }

require 'includes/db_connect.php';

$request_id = $_GET['id'] ?? null;
if (!$request_id) { die("Invalid Request ID"); }

// Fetch Request
$stmt = $pdo->prepare("SELECT * FROM realignment_requests WHERE id = ?");
$stmt->execute([$request_id]);
$req = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$req) { die("Request not found"); }

// Fetch Details
$sql_details = "
    SELECT rd.*, li.name as item_name, fs.name as fund_name
    FROM realignment_details rd
    JOIN budget_buckets bb ON rd.budget_bucket_id = bb.id
    JOIN fund_sources fs ON bb.fund_source_id = fs.id
    JOIN line_items li ON bb.line_item_id = li.id
    WHERE rd.request_id = ?
";
$stmt = $pdo->prepare($sql_details);
$stmt->execute([$request_id]);
$details = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Variables
$yr_suffix = substr($req['fiscal_year'], -2);
$padded_id = str_pad($req['id'], 3, '0', STR_PAD_LEFT);
$serialNo = "BRR-{$yr_suffix}-{$padded_id}";

$dateObj = new DateTime($req['request_date']);
$formattedDate = $dateObj->format('F j, Y');
$day = $dateObj->format('j');
$suffix = 'th';
if ($day % 10 == 1 && $day != 11) $suffix = 'st';
elseif ($day % 10 == 2 && $day != 12) $suffix = 'nd';
elseif ($day % 10 == 3 && $day != 13) $suffix = 'rd';

$fundName = count($details) > 0 ? $details[0]['fund_name'] : 'General Fund';
$fy = $req['fiscal_year'];
$justification = htmlspecialchars($req['particulars']);

$srcListHtml = '';
$destListHtml = '';
$cSrcBody = '';
$cDestBody = '';
$totalSrcAmt = 0;
$totalDestAmt = 0;

foreach ($details as $d) {
    $amt = (float)$d['amount'];
    $amtFormatted = number_format($amt, 2);
    
    if ($d['type'] === 'source') {
        $totalSrcAmt += $amt;
        $srcListHtml .= "<li style='margin-bottom: 5px;'>&#8226; {$d['item_name']} - <strong>Php {$amtFormatted}</strong></li>";
        $cSrcBody .= "
            <tr>
                <td style='border: 1px solid black; padding: 5px;'>{$d['item_name']}</td>
                <td style='border: 1px solid black; border-right: none; padding: 5px; width: 40px;'>Php</td>
                <td style='border: 1px solid black; border-left: none; padding: 5px; font-weight: bold; text-align: right;'>{$amtFormatted}</td>
            </tr>";
    } else {
        $totalDestAmt += $amt;
        $destListHtml .= "<li style='margin-bottom: 5px;'>&#8226; {$d['item_name']} - <strong>Php {$amtFormatted}</strong></li>";
        $cDestBody .= "
            <tr>
                <td style='border: 1px solid black; padding: 5px;'>{$d['item_name']}</td>
                <td style='border: 1px solid black; border-right: none; padding: 5px; width: 40px;'>Php</td>
                <td style='border: 1px solid black; border-left: none; padding: 5px; font-weight: bold; text-align: right;'>{$amtFormatted}</td>
            </tr>";
    }
}

$totalSrcStr = number_format($totalSrcAmt, 2);
$totalDestStr = number_format($totalDestAmt, 2);

// For Word Document compatibility, we use a basic HTML structure and output it with the Word content type.
header("Content-Type: application/vnd.ms-word");
header("Expires: 0");
header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
header("Content-disposition: attachment; filename=\"Realignment_Request_{$serialNo}.doc\"");

?>
<html xmlns:o='urn:schemas-microsoft-com:office:office' xmlns:w='urn:schemas-microsoft-com:office:word' xmlns='http://www.w3.org/TR/REC-html40'>
<head>
    <meta charset="utf-8">
    <title>Realignment Request</title>
    <style>
        body { font-family: "Arial", sans-serif; font-size: 11pt; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .fw-bold { font-weight: bold; }
        .mt-3 { margin-top: 15px; }
        .mt-4 { margin-top: 20px; }
        .mb-0 { margin-bottom: 0; }
        .mb-3 { margin-bottom: 15px; }
        .mb-4 { margin-bottom: 20px; }
        .text-uppercase { text-transform: uppercase; }
        table { width: 100%; border-collapse: collapse; }
    </style>
</head>
<body>

    <!-- PAGE 1: LETTER -->
    <div style="text-align: center; margin-bottom: 20px;">
        <h6 style="font-family: 'Times New Roman', serif; font-weight: bold; margin: 0; font-size: 12pt;">Republic of the Philippines</h6>
        <h5 style="font-family: 'Times New Roman', serif; font-weight: bold; margin: 0; font-size: 14pt;">CITY GOVERNMENT OF PARAÑAQUE</h5>
        <h4 style="font-family: 'Times New Roman', serif; font-weight: bold; margin: 0; font-size: 16pt;">OFFICE OF THE CITY HEALTH OFFICER</h4>
    </div>
    <hr style="border: 1px solid black; margin-bottom: 15px;">
    
    <div class="text-right mb-3">
        <strong>Tracking No:</strong> <span style="font-family: monospace; font-weight: bold; color: red;"><?php echo $serialNo; ?></span>
    </div>

    <div class="mb-4">
        <p><strong>Date:</strong> <?php echo $formattedDate; ?></p>
        <p>
            <strong>HON. EDWIN L. OLIVAREZ</strong><br>
            City Mayor<br>
            City of Parañaque
        </p>
        <p class="mt-3">
            <strong>THRU: CITY BUDGET OFFICE</strong>
        </p>
        <p class="mt-3">
            <strong>SUBJECT: REQUEST FOR REALIGNMENT OF FUNDS (FY <?php echo $fy; ?>)</strong>
        </p>
    </div>

    <div style="line-height: 1.5; text-align: justify;">
        <p>Dear Mayor Olivarez,</p>
        
        <p>This is to respectfully request your approval for the realignment of funds within the <strong><?php echo $fundName; ?></strong> of the City Health Office for Fiscal Year <?php echo $fy; ?>.</p>
        
        <p>This realignment is necessary for the following reason(s): <strong><?php echo $justification; ?></strong>.</p>
        
        <p>Specifically, we propose to transfer funds <strong>FROM</strong> the following source line item(s), which currently have an unexpended and unobligated available balance:</p>
        
        <ul style="list-style-type: none; padding-left: 20px;">
            <?php echo $srcListHtml; ?>
        </ul>

        <p>We propose to transfer the aforementioned funds <strong>TO</strong> the following destination line item(s) to augment existing allocations:</p>
        
        <ul style="list-style-type: none; padding-left: 20px;">
            <?php echo $destListHtml; ?>
        </ul>

        <p class="mt-4">Attached is the detailed Certification to support this request. Your highly favorable consideration of this matter will ensure the uninterrupted delivery of essential health services to our constituents.</p>
        
        <p>Thank you.</p>
    </div>

    <div class="mt-3">
        <p>Very truly yours,</p>
        <br>
        <p class="fw-bold mb-0 text-uppercase">DR. DARIUS J. SEBASTIAN, MPH, PHSAE</p>
        <p>OIC - City Health Officer</p>
    </div>

    <!-- PAGE BREAK -->
    <br clear="all" style="page-break-before:always" />

    <!-- PAGE 2: CERTIFICATION -->
    <div style="text-align: center; margin-bottom: 20px;">
        <h6 style="font-family: 'Times New Roman', serif; font-weight: bold; margin: 0; font-size: 12pt;">Republic of the Philippines</h6>
        <h5 style="font-family: 'Times New Roman', serif; font-weight: bold; margin: 0; font-size: 14pt;">CITY GOVERNMENT OF PARAÑAQUE</h5>
        <h4 style="font-family: 'Times New Roman', serif; font-weight: bold; margin: 0; font-size: 16pt;">OFFICE OF THE CITY HEALTH OFFICER</h4>
    </div>
    <hr style="border: 1px solid black; margin-bottom: 15px;">

    <div class="text-right mb-4">
        <strong>Tracking No:</strong> <span style="font-family: monospace; font-weight: bold; color: red;"><?php echo $serialNo; ?></span>
    </div>

    <div class="text-center mb-3 mt-4">
        <h3 class="fw-bold text-uppercase" style="font-family: 'Arial', sans-serif;">CERTIFICATION</h3>
    </div>

    <div style="line-height: 1.5; text-align: justify;">
        <p>This is to certify that the appropriation for the following line budget items requested for realignment are no longer needed for implementation by this department:</p>
        
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 15px;">
            <thead>
                <tr style="background-color: #f0f0f0; text-transform: uppercase;">
                    <th style="border: 1px solid black; width: 70%; text-align: center; padding: 5px;">Object of Expenditures</th>
                    <th style="border: 1px solid black; width: 30%; text-align: center; padding: 5px;" colspan="2">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php echo $cSrcBody; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td style="border: 1px solid black; font-weight: bold; padding: 5px;">TOTAL</td>
                    <td style="border: 1px solid black; border-right: none; font-weight: bold; width: 40px; padding: 5px;">Php</td>
                    <td style="border: 1px solid black; border-left: none; text-align: right; font-weight: bold; padding: 5px;"><?php echo $totalSrcStr; ?></td>
                </tr>
            </tfoot>
        </table>

        <p class="mt-3">The realignment of the said appropriation will be used for the following:</p>
        
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
            <thead>
                <tr style="background-color: #f0f0f0; text-transform: uppercase;">
                    <th style="border: 1px solid black; width: 70%; text-align: center; padding: 5px;">Object of Expenditures</th>
                    <th style="border: 1px solid black; width: 30%; text-align: center; padding: 5px;" colspan="2">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php echo $cDestBody; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td style="border: 1px solid black; font-weight: bold; padding: 5px;">TOTAL</td>
                    <td style="border: 1px solid black; border-right: none; font-weight: bold; width: 40px; padding: 5px;">Php</td>
                    <td style="border: 1px solid black; border-left: none; text-align: right; font-weight: bold; padding: 5px;"><?php echo $totalDestStr; ?></td>
                </tr>
            </tfoot>
        </table>

        <p class="mt-3">Issued this <?php echo $day . $suffix; ?> day of <?php echo $dateObj->format('F'); ?> <?php echo $dateObj->format('Y'); ?> in the City of Parañaque.</p>
    </div>

    <div class="mt-4">
        <table style="width: 100%; border: none;">
            <tr>
                <td style="width: 45%; vertical-align: top; border: none;">
                    <p style="margin: 0;">Certified by:</p>
                    <br><br>
                    <p class="fw-bold mb-0 text-uppercase" style="margin: 0;">DR. DARIUS J. SEBASTIAN, MPH, PHSAE</p>
                    <p style="margin: 0;">OIC - City Health Officer</p>
                </td>
                <td style="width: 10%; border: none;"></td>
                <td style="width: 45%; vertical-align: top; border: none;">
                    <p style="margin: 0;">Noted by:</p>
                    <br><br>
                    <p class="fw-bold mb-0 text-uppercase" style="margin: 0;">ALEJANDRO C. DEPANO II</p>
                    <p style="margin: 0;">City Budget Officer</p>
                </td>
            </tr>
        </table>

        <div style="text-align: center; margin-top: 40px;">
            <p style="margin: 0;">Approved by:</p>
            <br><br>
            <p class="fw-bold mb-0 text-uppercase" style="margin: 0;">HON. EDWIN L. OLIVAREZ</p>
            <p style="margin: 0;">City Mayor</p>
        </div>
    </div>

</body>
</html>
