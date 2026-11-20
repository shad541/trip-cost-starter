<?php

require_once __DIR__ . '/bootstrap.php';

function require_http_method(string $method): void
{
    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        header('Allow: ' . $method);
        json_out(['error' => 'Метод запроса не поддерживается.'], 405);
    }
}

function valid_billing_period(mixed $period): bool
{
    return is_string($period)
        && preg_match('/\A(?!0000)[0-9]{4}-(0[1-9]|1[0-2])\z/', $period) === 1;
}

function require_billing_period(mixed $period): string
{
    if (!valid_billing_period($period)) {
        json_out(['error' => 'Укажите месяц в формате ГГГГ-ММ.'], 400);
    }
    return $period;
}

function billing_json(array $result): array
{
    $clients = [];
    foreach ($result['clients'] as $client) {
        $row = [
            'client_id' => $client['client_id'],
            'name' => $client['name'],
            'invoice_id' => $client['invoice_id'],
            'before' => cents_to_rubles($client['before_cents']),
            'after' => cents_to_rubles($client['after_cents']),
            'diff' => cents_to_rubles($client['after_cents'] - $client['before_cents']),
        ];
        if (isset($client['action'])) {
            $row['action'] = $client['action'];
        }
        $clients[] = $row;
    }
    return [
        'period' => $result['period'],
        'clients' => $clients,
        'total_before' => cents_to_rubles($result['total_before_cents']),
        'total_after' => cents_to_rubles($result['total_after_cents']),
        'total_diff' => cents_to_rubles($result['total_after_cents'] - $result['total_before_cents']),
    ] + array_intersect_key($result, array_flip(['created', 'updated', 'unchanged']));
}

function billing_json_error(Throwable $error): never
{
    if ($error instanceof DomainException) {
        json_out(['error' => $error->getMessage()], 422);
    }
    error_log((string)$error);
    json_out(['error' => 'Не удалось выполнить расчёт. Повторите запрос позже.'], 500);
}
