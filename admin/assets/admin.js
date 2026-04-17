function stripUtf8Bom(s) {
    if (!s || s.length < 1) return s;
    if (s.charCodeAt(0) === 0xfeff) return s.slice(1);
    if (s.length >= 3 && s.charCodeAt(0) === 0xef && s.charCodeAt(1) === 0xbb && s.charCodeAt(2) === 0xbf) {
        return s.slice(3);
    }
    return s;
}

function parseResponseJson(text) {
    if (text == null || text === '') {
        return { ok: false, reason: 'empty' };
    }
    let t = stripUtf8Bom(String(text)).trim();
    if (!t) {
        return { ok: false, reason: 'empty' };
    }
    try {
        const data = JSON.parse(t);
        if (data !== null && typeof data === 'object') {
            return { ok: true, data: data };
        }
    } catch (e) { /* loose parse below */ }
    const start = t.indexOf('{');
    const end = t.lastIndexOf('}');
    if (start >= 0 && end > start) {
        try {
            const data = JSON.parse(t.slice(start, end + 1));
            if (data !== null && typeof data === 'object') {
                return { ok: true, data: data };
            }
        } catch (e2) { /* */ }
    }
    return { ok: false, reason: 'notjson', raw: t };
}

function readableSnippet(s, max) {
    if (!s) return '';
    const noTags = s.replace(/<[^>]+>/g, ' ');
    let out = '';
    for (let i = 0; i < noTags.length && out.length < max + 40; i++) {
        const ch = noTags[i];
        const c = noTags.charCodeAt(i);
        if (c === 9 || c === 10 || c === 13) {
            out += ' ';
            continue;
        }
        if (c === 0xfffd) continue;
        if (c >= 32 && c < 127) {
            out += ch;
            continue;
        }
        if (c >= 0x0600 && c <= 0x06ff) {
            out += ch;
            continue;
        }
        if (c >= 0x0750 && c <= 0x077f) {
            out += ch;
            continue;
        }
    }
    return out.replace(/\s+/g, ' ').trim().slice(0, max);
}

function postJSON(url, payload) {
    return fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
        .then(async (r) => {
            const text = await r.text();
            const parsed = parseResponseJson(text);
            if (parsed.ok) {
                return parsed.data;
            }

            const status = r.status;
            let msg;
            if (parsed.reason === 'empty') {
                msg =
                    'رد السيرفر فارغ (HTTP ' +
                    status +
                    '). غالباً: مسار الملف غلط، أو PHP يتوقف قبل إرسال JSON، أو خطأ قاتل في السيرفر.';
            } else {
                msg =
                    'السيرفر لم يرد بـ JSON صالح (HTTP ' +
                    status +
                    '). غالباً: تحذير/خطأ PHP يظهر قبل JSON، أو مسافات/BOM قبل <?php في ملف الـ API، أو الملف محفوظ UTF-16.';
                const hint = readableSnippet(parsed.raw, 100);
                if (hint.length >= 10) {
                    msg += ' — مقتطف مقروء: ' + hint;
                }
                if (/\$pdo|require_once|INFORMATION_SCHEMA|declare\s*\(/.test(hint + (parsed.raw || ''))) {
                    msg +=
                        '\n\n[تشخيص] يبدو أن المتصفح يستقبل كود PHP كنص. ارفع الملفات UTF-8 بدون BOM، وجرب فتح: /admin/api/departments/env-check.php — يجب أن يظهر JSON فقط.';
                }
            }
            return { success: false, message: msg };
        })
        .catch((e) => ({
            success: false,
            message: e.message || 'تعذر الاتصال بالخادم'
        }));
}
