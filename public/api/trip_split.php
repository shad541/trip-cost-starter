<?php
require __DIR__ . '/../../lib/billing_http.php';

require_http_method('GET');
$tripId = filter_var($_GET['trip_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($tripId === false) {
    json_out(['error' => 'Укажите положительный идентификатор рейса.'], 400);
}

try {
    $split = calculate_trip_split(db(), $tripId);
    if ($split === null) {
        json_out(['error' => 'Рейс не найден.'], 404);
    }

    $split['cost'] = cents_to_rubles($split['cost_cents']);
    unset($split['cost_cents']);
    foreach ($split['clients'] as &$client) {
        $client['amount'] = cents_to_rubles($client['amount_cents']);
        unset($client['amount_cents']);
    }
    unset($client);
    json_out($split);
} catch (Throwable $error) {
    billing_json_error($error);
}
