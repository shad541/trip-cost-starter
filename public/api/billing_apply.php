<?php
require __DIR__ . '/../../lib/billing_http.php';

require_http_method('POST');
$period = require_billing_period($_POST['period'] ?? null);

try {
    json_out(billing_json(apply_billing_period(db(), $period)));
} catch (Throwable $error) {
    billing_json_error($error);
}
