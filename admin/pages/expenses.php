
<h2>المصروفات</h2>
<p class="card-hint">يُسجَّل المصروف في طابور الترحيل أو القيد حسب إعداد النظام.</p>
<input id="name" placeholder="البيان / اسم المصروف">
<input id="amount" type="number" class="admin-inp-money" step="any" min="0" placeholder="المبلغ" inputmode="decimal" lang="en" dir="ltr">
<button type="button" class="btn" onclick="expSave()">حفظ</button>

<script>
function expSave() {
    var name = document.getElementById('name').value.trim();
    var amount = parseFloat(String(document.getElementById('amount').value || '0').replace(',', '.'));
    if (!name || amount <= 0) {
        alert('أدخل البيان والمبلغ بشكل صحيح');
        return;
    }
    postJSON('/admin/api/expenses/create.php', { name: name, amount: amount })
        .then(function (r) {
            if (r.success) {
                alert(r.message || 'تم الحفظ');
                return;
            }
            if (!orangeAdminOfferSuggestOnFailure(r, 'فشل الحفظ')) {
                alert(r.message || 'فشل');
            }
        })
        .catch(function (e) { alert(e.message || String(e)); });
}
</script>
