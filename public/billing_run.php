<?php
require __DIR__ . '/../lib/billing_http.php';

$period = $_POST['period'] ?? $_GET['period'] ?? null;
$query = valid_billing_period($period) ? '?period=' . rawurlencode($period) : '';
header('Location: billing.php' . $query, true, 303);
