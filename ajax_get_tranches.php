<?php
require 'includes/db_connect.php';
$id = $_GET['id'];

$stmt = $pdo->prepare("SELECT * FROM budget_buckets WHERE id = ?");
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$options = [];

function addOpt(&$arr, $code, $label, $is_locked) {
    $arr[] = ['code' => $code, 'label' => $label, 'is_locked' => $is_locked];
}

// Logic matches your Budget Engine
if ($row['pct_full'] > 0) {
    addOpt($options, 'full', 'Full Release', 0); 
}

if ($row['pct_sem1'] > 0) {
    addOpt($options, 'sem1', '1st Semester', 0);
} else {
    if ($row['pct_q1'] > 0) addOpt($options, 'q1', '1st Quarter', 0);
    if ($row['pct_q2'] > 0) addOpt($options, 'q2', '2nd Quarter', !$row['q2_released']);
}

if ($row['pct_sem2'] > 0) {
    addOpt($options, 'sem2', '2nd Semester', !$row['sem2_released']);
} else {
    if ($row['pct_q3'] > 0) addOpt($options, 'q3', '3rd Quarter', !$row['q3_released']);
    if ($row['pct_q4'] > 0) addOpt($options, 'q4', '4th Quarter', !$row['q4_released']);
}

if ($row['pct_reserve'] > 0) {
    addOpt($options, 'reserve', 'Reserve Fund', !$row['reserve_released']);
}

echo json_encode($options);
?>