
<?php
$pdo = db();
$sales = $pdo->query("SELECT SUM(amount) FROM journal_entries WHERE account_credit=2")->fetchColumn();
$cogs = $pdo->query("SELECT SUM(amount) FROM journal_entries WHERE account_debit=4")->fetchColumn();
$expenses = $pdo->query("SELECT SUM(amount) FROM journal_entries WHERE account_debit=6")->fetchColumn();

$profit = $sales - $cogs - $expenses;
?>
<h2>Financial Report</h2>
<p>Sales: <?php echo $sales; ?></p>
<p>COGS: <?php echo $cogs; ?></p>
<p>Expenses: <?php echo $expenses; ?></p>
<p>Net Profit: <?php echo $profit; ?></p>
