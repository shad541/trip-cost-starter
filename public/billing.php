<?php
require __DIR__ . '/../lib/billing_http.php';

$period = $_GET['period'] ?? null;
if (!valid_billing_period($period)) {
    $period = db()->query("SELECT MAX(substr(departure_date, 1, 7)) FROM trips")->fetchColumn() ?: date('Y-m');
}
page_header('Счета за месяц');
?>
<h1>Счета за месяц</h1>
<form id="billing-form" class="actions">
    <label for="billing-period">Месяц</label>
    <input id="billing-period" type="month" name="period" value="<?= h($period) ?>" required>
    <button id="billing-preview" type="submit">Показать</button>
    <button id="billing-apply" type="button" disabled>Применить</button>
</form>
<p id="billing-message" role="status"></p>
<table id="billing-results" class="grid" hidden>
    <thead>
    <tr>
        <th>Клиент</th>
        <th class="r">Было, ₽</th>
        <th class="r">Стало, ₽</th>
        <th class="r">Разница, ₽</th>
        <th id="billing-action-heading" hidden>Результат</th>
    </tr>
    </thead>
    <tbody></tbody>
    <tfoot>
    <tr class="total">
        <td>Итого</td>
        <td id="billing-total-before" class="r"></td>
        <td id="billing-total-after" class="r"></td>
        <td id="billing-total-diff" class="r"></td>
        <td id="billing-action-total" hidden></td>
    </tr>
    </tfoot>
</table>
<script src="js/billing.js"></script>
<?php page_footer();
