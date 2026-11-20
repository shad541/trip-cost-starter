<?php

use PHPUnit\Framework\TestCase;

final class BillingPeriodTest extends TestCase
{
    public function testPreviewPredictsOctoberAmountsWithoutWriting(): void
    {
        $db = seeded_database();
        $before = $this->snapshot($db);
        $preview = calculate_billing_period($db, '2026-10');

        self::assertSame(45461078, $preview['total_before_cents']);
        self::assertSame(45461080, $preview['total_after_cents']);
        self::assertCount(6, $preview['clients']);
        self::assertSame([1 => 7391028, 2 => 10165806, 3 => 7967100, 4 => 2623759, 5 => 4272402, 6 => 13040985],
            array_column($preview['clients'], 'after_cents', 'client_id'));
        self::assertSame($before, $this->snapshot($db));
    }

    public function testApplyReconcilesTripsAndInvoicesAndKeepsHistory(): void
    {
        $db = seeded_database();
        [$invoices, $lines] = $this->snapshot($db);
        $preview = calculate_billing_period($db, '2026-10');
        $result = apply_billing_period($db, '2026-10');
        self::assertSame(0, $result['created']);
        self::assertSame(5, $result['updated']);
        self::assertSame(1, $result['unchanged']);
        self::assertSame($preview['clients'], array_map(static function (array $client): array {
            unset($client['action']);
            return $client;
        }, $result['clients']));

        [$newInvoices, $newLines] = $this->snapshot($db);
        self::assertSame(array_column($lines, 'id'), array_column($newLines, 'id'));
        foreach ($invoices as $index => $oldInvoice) {
            $newInvoice = $newInvoices[$index];
            if ($oldInvoice['period'] === '2026-10') {
                unset($oldInvoice['total'], $newInvoice['total']);
            }
            self::assertSame($oldInvoice, $newInvoice);
        }
        self::assertSame(array_slice($lines, 0, 12), array_slice($newLines, 0, 12));
        self::assertSame(
            array_filter($lines, static fn(array $line) => $line['invoice_id'] === 8),
            array_filter($newLines, static fn(array $line) => $line['invoice_id'] === 8)
        );

        $lineCentsByTrip = $db->query("SELECT l.trip_id, SUM(CAST(ROUND(l.amount * 100) AS INTEGER))
            FROM invoice_lines l JOIN invoices i ON i.id = l.invoice_id
            WHERE i.period = '2026-10' GROUP BY l.trip_id ORDER BY l.trip_id")->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertSame([
            5 => 8190000, 6 => 5000000, 7 => 5161407, 8 => 5340000,
            9 => 6554015, 10 => 4210000, 11 => 4875050, 12 => 6130608,
        ], $lineCentsByTrip);
        $reconciled = $db->query("SELECT i.id, CAST(ROUND(i.total * 100) AS INTEGER) AS invoice_cents,
                SUM(CAST(ROUND(l.amount * 100) AS INTEGER)) AS line_cents
            FROM invoices i JOIN invoice_lines l ON l.invoice_id = i.id
            WHERE i.period = '2026-10' GROUP BY i.id")->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame(array_column($reconciled, 'invoice_cents'), array_column($reconciled, 'line_cents'));
        self::assertSame(45461080, array_sum(array_column($reconciled, 'invoice_cents')));
    }

    public function testSecondApplicationDoesNotWrite(): void
    {
        $db = seeded_database();
        apply_billing_period($db, '2026-10');
        $before = $this->snapshot($db);
        $changeCount = $db->query('SELECT total_changes()')->fetchColumn();

        $result = apply_billing_period($db, '2026-10');

        self::assertSame([0, 0, 6], [$result['created'], $result['updated'], $result['unchanged']]);
        self::assertSame($before, $this->snapshot($db));
        self::assertSame($changeCount, $db->query('SELECT total_changes()')->fetchColumn());
    }

    public function testReportsAnInvoiceWhoseLinesChangedButTotalStayedTheSame(): void
    {
        $db = seeded_database();
        apply_billing_period($db, '2026-10');
        $before = $this->snapshot($db);
        $db->exec('UPDATE invoice_lines SET amount = amount + (CASE WHEN trip_id = 7 THEN 1 ELSE -1 END)
            WHERE invoice_id = 12');

        $result = apply_billing_period($db, '2026-10');
        $clients = array_column($result['clients'], null, 'client_id');

        self::assertSame($clients[1]['before_cents'], $clients[1]['after_cents']);
        self::assertSame('updated', $clients[1]['action'] ?? null);
        self::assertSame('unchanged', $clients[2]['action'] ?? null);
        self::assertSame(1, $result['updated']);
        self::assertSame($before, $this->snapshot($db));
    }

    public function testMissingInvoiceIsCreatedOnlyOnce(): void
    {
        $db = seeded_database();
        $db->exec('DELETE FROM invoice_lines WHERE invoice_id = 12');
        $db->exec('DELETE FROM invoices WHERE id = 12');
        $preview = calculate_billing_period($db, '2026-10');
        $clients = array_column($preview['clients'], null, 'client_id');
        self::assertNull($clients[1]['invoice_id']);
        self::assertSame(0, $clients[1]['before_cents']);

        $first = apply_billing_period($db, '2026-10');
        self::assertSame(1, $first['created']);
        $invoice = $db->query("SELECT id, status FROM invoices WHERE client_id = 1 AND period = '2026-10'")->fetch(PDO::FETCH_ASSOC);
        self::assertSame('draft', $invoice['status']);
        self::assertSame($invoice['id'], array_column($first['clients'], null, 'client_id')[1]['invoice_id']);
        self::assertSame('created', array_column($first['clients'], null, 'client_id')[1]['action']);
        self::assertSame(0, apply_billing_period($db, '2026-10')['created']);
        self::assertSame(6, $db->query("SELECT COUNT(*) FROM invoices WHERE period = '2026-10'")->fetchColumn());
    }

    public function testCancelledTripsAreRemovedIncludingFromInvoicesWithoutRemainingTrips(): void
    {
        $db = seeded_database();
        $db->exec("UPDATE trips SET status = 'cancelled' WHERE id IN (7, 11)");
        apply_billing_period($db, '2026-10');
        self::assertSame(0, $db->query('SELECT COUNT(*) FROM invoice_lines WHERE trip_id IN (7, 11)')->fetchColumn());
        self::assertSame(0.0, $db->query('SELECT total FROM invoices WHERE id = 12')->fetchColumn());
    }

    public function testRollbackRestoresAllInvoicesAfterWriteFailure(): void
    {
        $db = seeded_database();
        $before = $this->snapshot($db);
        $db->exec("CREATE TRIGGER fail_invoice_update BEFORE UPDATE ON invoices
            WHEN NEW.client_id = 2 BEGIN SELECT RAISE(ABORT, 'test write failure'); END");
        try {
            apply_billing_period($db, '2026-10');
            self::fail('The forced database error did not occur.');
        } catch (PDOException $error) {
            self::assertStringContainsString('test write failure', $error->getMessage());
        }
        self::assertSame($before, $this->snapshot($db));
        $db->exec('DROP TRIGGER fail_invoice_update');
        self::assertSame(5, apply_billing_period($db, '2026-10')['updated']);
    }

    public function testDuplicateInvoicesAreRejectedBeforeWriting(): void
    {
        $db = seeded_database();
        $db->exec("INSERT INTO invoices (client_id, period) VALUES (1, '2026-10')");
        $before = $this->snapshot($db);
        try {
            apply_billing_period($db, '2026-10');
            self::fail('Duplicate invoices cannot be reconciled.');
        } catch (DomainException) {
            self::assertSame($before, $this->snapshot($db));
        }
    }

    private function snapshot(PDO $db): array
    {
        return [
            $db->query('SELECT * FROM invoices ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            $db->query('SELECT * FROM invoice_lines ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        ];
    }
}
