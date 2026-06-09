/**
 * رسالة تأكيد موحّدة (OK / Cancel) لأي زر «حفظ» أو «حذف» رئيسي على مستوى لوحة الإدارة.
 *
 * كيف يعمل (دون تعديل أي شاشة):
 * - يلتقط نقرة المستخدم الحقيقية (capture) على زر حفظ/حذف رئيسي، يوقف النقرة الأصلية،
 *   ويعرض رسالة تأكيد واحدة.
 * - عند «موافق» يُعيد إطلاق النقرة برمجياً (نقرة غير موثوقة isTrusted=false فيتجاهلها هذا
 *   المعالِج) مع تحييد أي confirm() داخلي مؤقتاً — حتى لا تظهر رسالتان للأزرار التي تملك
 *   تأكيداً أصلاً (السنوات المالية، السندات، إقفال التعديلات…).
 * - عند «إلغاء» لا يحدث شيء.
 *
 * الاستثناءات:
 * - حذف الأسطر داخل السندات/الجداول (admin-doc-line-remove / ob-row-del) — لا يطلب تأكيداً.
 * - زر عليه data-confirm-skip — يُستثنى.
 * - data-confirm-msg="..." — رسالة مخصّصة لهذا الزر.
 */
(function () {
    'use strict';

    var SAVE_ID_RE = /(^|[-_\s])save([-_\s]|$)/i;
    var DELETE_ID_RE = /(^|[-_\s])delete([-_\s]|$)/i;

    function labelText(el) {
        if (el.tagName === 'INPUT') {
            return String(el.value || '').trim();
        }
        return String(el.textContent || '').trim().replace(/\s+/g, ' ');
    }

    function isLineRemove(el) {
        if (el.classList) {
            if (el.classList.contains('admin-doc-line-remove') || el.classList.contains('ob-row-del')) {
                return true;
            }
        }
        return false;
    }

    /** @returns {'save'|'delete'|null} */
    function classify(el) {
        if (!el || el.hasAttribute('data-confirm-skip')) {
            return null;
        }
        if (el.disabled) {
            return null;
        }
        if (isLineRemove(el)) {
            return null;
        }
        var t = labelText(el);
        var idc = (el.id || '') + ' ' + (el.className || '') + ' ' + (el.getAttribute('name') || '');
        if (/^حفظ(\s|$)/.test(t) || SAVE_ID_RE.test(idc)) {
            return 'save';
        }
        if (/^حذف(\s|$)/.test(t) || DELETE_ID_RE.test(idc)) {
            return 'delete';
        }
        return null;
    }

    document.addEventListener('click', function (ev) {
        /* النقرات البرمجية (إعادة الإطلاق أدناه) isTrusted=false → نتجاهلها فلا تكرار ولا حلقة. */
        if (!ev.isTrusted) {
            return;
        }
        var target = ev.target;
        var btn = target && target.closest
            ? target.closest('button, a, input[type="submit"], input[type="button"]')
            : null;
        if (!btn) {
            return;
        }
        var kind = classify(btn);
        if (!kind) {
            return;
        }
        ev.preventDefault();
        ev.stopImmediatePropagation();
        var custom = btn.getAttribute('data-confirm-msg');
        var msg = custom || (kind === 'delete'
            ? 'تأكيد الحذف؟ لا يمكن التراجع عن هذه العملية.'
            : 'تأكيد حفظ التغييرات؟');
        if (!window.confirm(msg)) {
            return;
        }
        var origConfirm = window.confirm;
        window.confirm = function () { return true; };
        try {
            btn.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, view: window }));
        } finally {
            window.confirm = origConfirm;
        }
    }, true);
})();
