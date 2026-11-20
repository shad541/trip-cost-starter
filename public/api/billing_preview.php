<?php
require __DIR__ . '/../../lib/billing_http.php';

require_http_method('GET');
$period = require_billing_period($_GET['period'] ?? null);

try {
    $db = db();
    $db->beginTransaction();
    $preview = calculate_billing_period($db, $period);
    $db->commit();
    json_out(billing_json($preview));
} catch (Throwable $error) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    billing_json_error($error);
}
