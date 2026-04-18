
<h2>Expenses</h2>
<input id="name" placeholder="Expense Name">
<input id="amount" type="number" class="admin-inp-money" step="any" min="0" placeholder="المبلغ" inputmode="decimal" lang="en" dir="ltr">
<button onclick="save()">Save</button>

<script>
function save(){
 fetch('/admin/api/expenses/create.php',{
  method:'POST',
  headers:{'Content-Type':'application/json'},
  body:JSON.stringify({
    name:document.getElementById('name').value,
    amount:document.getElementById('amount').value
  })
 }).then(()=>alert('Saved'));
}
</script>
