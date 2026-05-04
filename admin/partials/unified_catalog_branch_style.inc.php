<style>
.uc-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px 18px;direction:ltr;}
.uc-form-grid label,.uc-form-grid input,.uc-form-grid select{direction:rtl;text-align:right;}
.uc-form-grid #uc_sec_slug,#uc_cat_slug,#uc_sub_slug,#uc_sec_name_en,#uc_cat_name_en,#uc_sub_name_en{text-align:left;direction:ltr;}
.uc-form-grid .admin-sort-field-wrap{width:100%;max-width:var(--admin-sort-field-max-w,220px);}
@media(max-width:860px){.uc-form-grid{grid-template-columns:1fr;}}
.uc-sec-form-grid{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));grid-template-areas:"active active . . . . . . . . sort sort""slug slug slug slug slug slug dept dept dept dept dept dept""en en en en en en ar ar ar ar ar ar""hi hi hi hi hi hi fil fil fil fil fil fil";gap:14px 18px;direction:ltr;}
.uc-sec-form-grid .uc-sec-sort{grid-area:sort;justify-self:end;width:100%;}
.uc-sec-form-grid .uc-sec-active{grid-area:active;justify-self:start;width:100%;}
.uc-sec-form-grid .uc-sec-dept{grid-area:dept;}
.uc-sec-form-grid .uc-sec-slug{grid-area:slug;}
.uc-sec-form-grid .uc-sec-ar{grid-area:ar;}
.uc-sec-form-grid .uc-sec-en{grid-area:en;}
.uc-sec-form-grid .uc-sec-fil{grid-area:fil;}
.uc-sec-form-grid .uc-sec-hi{grid-area:hi;}
.uc-sec-form-grid label,.uc-sec-form-grid input,.uc-sec-form-grid select{direction:rtl;text-align:right;}
.uc-sec-form-grid #uc_sec_slug,.uc-sec-form-grid #uc_sec_name_en{text-align:left;direction:ltr;}
.uc-sec-form-grid .uc-sec-sort.admin-sort-field-wrap,.uc-sec-form-grid .uc-sec-active.admin-sort-field-wrap{max-width:var(--admin-sort-field-max-w,220px);}
.uc-sec-form-grid #uc_sec_sort,.uc-sec-form-grid #uc_sec_active,.uc-sec-form-grid #uc_sec_slug{margin-inline:0;display:block;width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:var(--radius-sm,10px);font-size:14px;line-height:calc(var(--input-min-h,36px) - 2px);min-height:var(--input-min-h,36px);height:var(--input-min-h,36px);max-height:var(--input-min-h,36px);padding-block:0;padding-inline:12px;}
.uc-sec-form-grid input#uc_sec_sort::-webkit-outer-spin-button,.uc-sec-form-grid input#uc_sec_sort::-webkit-inner-spin-button{-webkit-appearance:none;margin:0;}
.uc-sec-form-grid input#uc_sec_sort{-moz-appearance:textfield;appearance:textfield;}
.uc-sec-form-grid #uc_sec_active{-webkit-appearance:none;appearance:none;background-color:#fff;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2394a3b8' d='M2.75 4.25L6 7.55l3.25-3.3.65.64L6 8.82 2.1 4.9l.65-.65z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-size:12px;background-position:left 12px center;padding-inline-end:32px;}
@media(max-width:860px){.uc-sec-form-grid{grid-template-columns:1fr;grid-template-areas:"sort""active""dept""slug""ar""en""fil""hi";}.uc-sec-form-grid .uc-sec-sort,.uc-sec-form-grid .uc-sec-active{justify-self:start;max-width:var(--admin-sort-field-max-w,220px);}}
.uc-table{border-collapse:collapse;width:100%;font-size:0.93rem;}
.uc-table th,.uc-table td{padding:10px;border-bottom:1px solid #f0f1f5;vertical-align:top;}
.uc-table thead th{border-bottom-color:#e8e9ec;text-align:right;}
.uc-table thead th:last-child{text-align:center;}
.uc-table tbody td:last-child{text-align:center;}
</style>
