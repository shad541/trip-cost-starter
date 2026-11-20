<?php
require __DIR__ . '/../lib/bootstrap.php';

$months = db()->query("SELECT DISTINCT substr(departure_date, 1, 7) FROM trips ORDER BY 1 DESC")->fetchAll(PDO::FETCH_COLUMN);
$month = isset($_GET['month']) ? $_GET['month'] : '';
if (!in_array($month, $months)) {
    $month = $months[0];
}

$trips = db()->query("SELECT t.*, (SELECT SUM(weight_kg) FROM cargo WHERE trip_id = t.id) AS weight_kg
    FROM trips t
    WHERE t.departure_date LIKE '$month%' AND t.status <> 'cancelled'
    ORDER BY t.departure_date")->fetchAll();
$clientWeights = db()->prepare('SELECT cl.name, SUM(c.weight_kg) AS weight_kg
    FROM cargo c JOIN clients cl ON cl.id = c.client_id
    WHERE c.trip_id = ? GROUP BY c.client_id, cl.name ORDER BY weight_kg DESC, c.client_id');

page_header('Загрузка машин');
?>
<h1>Загрузка машин по весу</h1>

<form class="filter" method="get">
    Месяц:
    <select name="month" onchange="this.form.submit()">
        <?php foreach ($months as $m): ?>
            <option value="<?= h($m) ?>"<?= $m == $month ? ' selected' : '' ?>><?= h($m) ?></option>
        <?php endforeach; ?>
    </select>
</form>

<table class="grid">
    <thead>
    <tr>
        <th>Рейс / клиент</th>
        <th>Отправление</th>
        <th>Машина</th>
        <th class="r">Грузоподъёмность, кг</th>
        <th class="r">Вес, кг</th>
        <th class="r">Загрузка, %</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($trips as $trip): ?>
        <?php
        $load = $trip['capacity_kg'] ? $trip['weight_kg'] / $trip['capacity_kg'] * 100 : 0;
        $clientWeights->execute([$trip['id']]);
        ?>
        <tr class="total">
            <td><a href="trip.php?id=<?= $trip['id'] ?>"><?= h($trip['number']) ?></a></td>
            <td><?= h(format_dt($trip['departure_date'])) ?></td>
            <td><?= h($trip['vehicle_plate']) ?></td>
            <td class="r"><?= num($trip['capacity_kg']) ?></td>
            <td class="r"><?= num($trip['weight_kg']) ?></td>
            <td class="r"><?= num($load, 1) ?></td>
        </tr>
        <?php foreach ($clientWeights->fetchAll() as $client): ?>
            <tr class="sub">
                <td><?= h($client['name']) ?></td>
                <td colspan="3"></td>
                <td class="r"><?= num($client['weight_kg']) ?></td>
                <td class="r"><?= num($trip['weight_kg'] > 0 ? $client['weight_kg'] / $trip['weight_kg'] * 100 : 0, 1) ?></td>
            </tr>
        <?php endforeach; ?>
    <?php endforeach; ?>
    <?php if (!$trips): ?>
        <tr><td colspan="6" class="empty">Рейсов нет</td></tr>
    <?php endif; ?>
    </tbody>
</table>
<?php page_footer();
