<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TripSplitTest extends TestCase
{
    public function testContractExampleUsesClientVolumesAndAggregatesCargo(): void
    {
        $split = calculate_trip_split(seeded_database(), 11);
        self::assertSame('МСК-1111', $split['number']);
        self::assertSame(4875050, $split['cost_cents']);
        self::assertSame('volume', $split['basis']);
        self::assertSame([2, 1], array_column($split['clients'], 'cargo_count'));
        self::assertSame([120.0, 2000.0], array_column($split['clients'], 'weight_kg'));
        self::assertSame([8.0, 2.0], array_column($split['clients'], 'volume_m3'));
        self::assertSame([0.8, 0.2], array_column($split['clients'], 'share'));
    }

    public static function tripShares(): array
    {
        return [
            'zero volume switches the whole trip to weight' => [5, 'weight', [5 => 238333, 6 => 7951667]],
            'equal remainders use client ID' => [6, 'volume', [3 => 1666667, 4 => 1666667, 5 => 1666666]],
            'all volumes missing' => [8, 'weight', [2 => 2870968, 5 => 172258, 6 => 2296774]],
            'one null switches the whole trip to weight' => [9, 'weight', [2 => 2947368, 3 => 1919667, 4 => 581717, 5 => 1105263]],
            'contract example' => [11, 'volume', [1 => 3900040, 2 => 975010]],
        ];
    }

    #[DataProvider('tripShares')]
    public function testDistributesEveryCentDeterministically(int $tripId, string $basis, array $expected): void
    {
        $db = seeded_database();
        $split = calculate_trip_split($db, $tripId);
        $amounts = array_column($split['clients'], 'amount_cents', 'client_id');
        self::assertSame($expected, $amounts);
        self::assertSame($basis, $split['basis']);
        self::assertSame($split['cost_cents'], array_sum($amounts));
        self::assertSame($split, calculate_trip_split($db, $tripId));
    }

    public function testClientVolumeIsUnknownIfAnyOfTheirCargoVolumesIsMissing(): void
    {
        $db = seeded_database();
        $db->exec('UPDATE cargo SET volume_m3 = 0 WHERE id = 31');
        $clients = array_column(calculate_trip_split($db, 9)['clients'], null, 'client_id');
        self::assertNull($clients[2]['volume_m3']);
        self::assertNull($clients[5]['volume_m3']);
        self::assertSame([2.1, 2.4], array_column([$clients[3], $clients[4]], 'volume_m3'));
    }

    public function testUnknownTripReturnsNull(): void
    {
        self::assertNull(calculate_trip_split(seeded_database(), 999));
    }

    public function testRejectsNonzeroCostWithoutAnyAllocationBase(): void
    {
        $db = seeded_database();
        $db->exec('UPDATE cargo SET weight_kg = 0 WHERE trip_id = 8');
        $this->expectException(DomainException::class);
        calculate_trip_split($db, 8);
    }

    public function testUsesWeightForAllClientsWhenZeroAppearsAmongDifferentKnownVolumes(): void
    {
        $db = seeded_database();
        $db->exec('UPDATE cargo SET volume_m3 = 0 WHERE id = 23');
        $split = calculate_trip_split($db, 7);
        self::assertSame('weight', $split['basis']);
        self::assertSame([1 => 432442, 2 => 4101226, 4 => 362694, 5 => 265045],
            array_column($split['clients'], 'amount_cents', 'client_id'));
    }
}
