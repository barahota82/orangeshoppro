
<h2>Purchases</h2>
<input id="supplier" placeholder="Supplier ID">
<input id="total" placeholder="Total">
<select id="type">
<option value="cash">Cash</option>
<option value="credit">Credit</option>
</select>
<button onclick="save()">Save</button>

<script>
function save(){
 fetch('/admin/api/purchases/create.php',{
  method:'POST',
  headers:{'Content-Type':'application/json'},
  body:JSON.stringify({
    supplier_id:document.getElementById('supplier').value,
    total:document.getElementById('total').value,
    type:document.getElementById('type').value,
    items:[]
  })
 }).then(()=>alert('Saved'));
}
</script>
