<?php

function rubles_to_cents(float $amount): int
{
    return (int)round($amount * 100);
}

function cents_to_rubles(int $cents): string
{
    return sprintf('%s%d.%02d', $cents < 0 ? '-' : '', abs(intdiv($cents, 100)), abs($cents % 100));
}

function calculate_trip_split(PDO $db, int $tripId): ?array
{
    $statement = $db->prepare('SELECT number, cost FROM trips WHERE id = ?');
    $statement->execute([$tripId]);
    $trip = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$trip) {
        return null;
    }

    $statement = $db->prepare('SELECT c.client_id, cl.name, c.weight_kg, c.volume_m3
        FROM cargo c JOIN clients cl ON cl.id = c.client_id
        WHERE c.trip_id = ? ORDER BY c.client_id, c.id');
    $statement->execute([$tripId]);
    $clients = [];
    $basis = 'volume';
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $item) {
        if ($item['weight_kg'] < 0 || ($item['volume_m3'] !== null && $item['volume_m3'] < 0)) {
            throw new DomainException("Рейс № {$trip['number']} содержит груз с отрицательным весом или объёмом.");
        }

        $clientId = $item['client_id'];
        $clients[$clientId] ??= [
            'client_id' => $clientId,
            'name' => $item['name'],
            'cargo_count' => 0,
            'weight_kg' => 0.0,
            'volume_m3' => 0.0,
        ];
        $clients[$clientId]['cargo_count']++;
        $clients[$clientId]['weight_kg'] += $item['weight_kg'];
        if ($item['volume_m3'] <= 0) {
            $basis = 'weight';
            $clients[$clientId]['volume_m3'] = null;
        } elseif ($clients[$clientId]['volume_m3'] !== null) {
            $clients[$clientId]['volume_m3'] += $item['volume_m3'];
        }
    }

    $cost = rubles_to_cents($trip['cost']);
    $quantity = $basis === 'weight' ? 'weight_kg' : 'volume_m3';
    $total = array_sum(array_column($clients, $quantity));
    if ($cost !== 0 && $total <= 0) {
        $label = $basis === 'weight' ? 'вес' : 'объём';
        throw new DomainException("Рейс № {$trip['number']} имеет стоимость, но суммарный {$label} равен нулю.");
    }

    $remaining = abs($cost);
    $remainders = [];
    foreach ($clients as $clientId => &$client) {
        $client['share'] = $total > 0 ? $client[$quantity] / $total : 0.0;
        $quota = abs($cost) * $client['share'];
        $client['amount_cents'] = (int)floor($quota);
        $remaining -= $client['amount_cents'];
        // Стабилизируем сравнение равных остатков после операций с SQLite REAL.
        $remainders[$clientId] = round($quota - $client['amount_cents'], 9);
    }
    unset($client);

    if ($remaining < 0 || $remaining > count($clients)) {
        throw new DomainException("Не удалось распределить стоимость рейса № {$trip['number']} с точностью до копейки.");
    }

    $order = array_keys($remainders);
    usort($order, static function (int $left, int $right) use ($remainders): int {
        return ($remainders[$right] <=> $remainders[$left]) ?: ($left <=> $right);
    });
    foreach (array_slice($order, 0, $remaining) as $clientId) {
        $clients[$clientId]['amount_cents']++;
    }
    if ($cost < 0) {
        foreach ($clients as &$client) {
            $client['amount_cents'] = -$client['amount_cents'];
        }
        unset($client);
    }

    return [
        'trip_id' => $tripId,
        'number' => $trip['number'],
        'cost_cents' => $cost,
        'basis' => $basis,
        'clients' => array_values($clients),
    ];
}

function calculate_billing_period(PDO $db, string $period): array
{
    $statement = $db->prepare('SELECT i.id, i.client_id, i.total, cl.name
        FROM invoices i JOIN clients cl ON cl.id = i.client_id
        WHERE i.period = ? ORDER BY i.client_id, i.id');
    $statement->execute([$period]);
    $clients = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $invoice) {
        $clientId = $invoice['client_id'];
        if (isset($clients[$clientId])) {
            throw new DomainException("За {$period} найдено несколько счетов клиента № {$clientId}.");
        }
        $clients[$clientId] = [
            'client_id' => $clientId,
            'name' => $invoice['name'],
            'invoice_id' => $invoice['id'],
            'before_cents' => rubles_to_cents($invoice['total']),
            'after_cents' => 0,
            'lines_cents' => [],
        ];
    }

    $statement = $db->prepare("SELECT id FROM trips
        WHERE departure_date LIKE ? AND status <> 'cancelled' ORDER BY departure_date, id");
    $statement->execute([$period . '-%']);
    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $tripId) {
        $split = calculate_trip_split($db, $tripId);
        foreach ($split['clients'] as $share) {
            $clientId = $share['client_id'];
            $clients[$clientId] ??= [
                'client_id' => $clientId,
                'name' => $share['name'],
                'invoice_id' => null,
                'before_cents' => 0,
                'after_cents' => 0,
                'lines_cents' => [],
            ];
            $clients[$clientId]['lines_cents'][$tripId] = $share['amount_cents'];
            $clients[$clientId]['after_cents'] += $share['amount_cents'];
        }
    }

    ksort($clients, SORT_NUMERIC);
    return [
        'period' => $period,
        'clients' => array_values($clients),
        'total_before_cents' => array_sum(array_column($clients, 'before_cents')),
        'total_after_cents' => array_sum(array_column($clients, 'after_cents')),
    ];
}

function apply_billing_period(PDO $db, string $period): array
{
    // Резервируем запись до чтения счетов, чтобы параллельный запрос увидел актуальные данные.
    $db->exec('BEGIN IMMEDIATE');
    try {
        $result = calculate_billing_period($db, $period);
        $result += ['created' => 0, 'updated' => 0, 'unchanged' => 0];
        $createInvoice = $db->prepare("INSERT INTO invoices (client_id, period, total, created_at)
            VALUES (?, ?, ?, datetime('now', 'localtime'))");
        $updateInvoice = $db->prepare('UPDATE invoices SET total = ? WHERE id = ?');
        $readLines = $db->prepare('SELECT id, trip_id, amount FROM invoice_lines WHERE invoice_id = ? ORDER BY id');
        $insertLine = $db->prepare('INSERT INTO invoice_lines (invoice_id, trip_id, amount) VALUES (?, ?, ?)');
        $updateLine = $db->prepare('UPDATE invoice_lines SET amount = ? WHERE id = ?');
        $deleteLine = $db->prepare('DELETE FROM invoice_lines WHERE id = ?');

        foreach ($result['clients'] as &$client) {
            $invoiceId = $client['invoice_id'];
            $total = cents_to_rubles($client['after_cents']);
            $expected = $client['lines_cents'];
            $created = $invoiceId === null;
            $changed = $client['before_cents'] !== $client['after_cents'];

            if ($created) {
                $createInvoice->execute([$client['client_id'], $period, $total]);
                $invoiceId = $client['invoice_id'] = (int)$db->lastInsertId();
            }

            $readLines->execute([$invoiceId]);
            $seen = [];
            foreach ($readLines->fetchAll(PDO::FETCH_ASSOC) as $line) {
                $tripId = $line['trip_id'];
                if (isset($seen[$tripId])) {
                    throw new DomainException("Счёт № {$invoiceId} содержит несколько строк по рейсу № {$tripId}.");
                }
                $seen[$tripId] = true;
                if (!isset($expected[$tripId])) {
                    $deleteLine->execute([$line['id']]);
                    $changed = true;
                    continue;
                }
                if (rubles_to_cents($line['amount']) !== $expected[$tripId]) {
                    $updateLine->execute([cents_to_rubles($expected[$tripId]), $line['id']]);
                    $changed = true;
                }
                unset($expected[$tripId]);
            }

            foreach ($expected as $tripId => $amount) {
                $insertLine->execute([$invoiceId, $tripId, cents_to_rubles($amount)]);
                $changed = true;
            }
            if ($created) {
                $client['action'] = 'created';
            } elseif ($changed) {
                if ($client['before_cents'] !== $client['after_cents']) {
                    $updateInvoice->execute([$total, $invoiceId]);
                }
                $client['action'] = 'updated';
            } else {
                $client['action'] = 'unchanged';
            }
            $result[$client['action']]++;
        }
        unset($client);

        $db->exec('COMMIT');
        return $result;
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->exec('ROLLBACK');
        }
        throw $error;
    }
}
