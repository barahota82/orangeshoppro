/**
 * Orange admin password policy — client mirror (server validates on save).
 */
(function (global) {
    'use strict';

    var MIN_LEN = 12;
    var GEN_LEN = 16;
    var SPECIAL = '!@#$%^&*()_-+=?';
    var WEAK = [
        'password', 'password1', 'password123', 'admin', 'admin123', 'administrator',
        '123456', '12345678', '123456789', '1234567890', 'qwerty', 'qwerty123',
        'letmein', 'welcome', 'orange', 'orange123', 'changeme', 'master', 'root', 'test', 'test123'
    ];

    function isAscTriplet(t) {
        if (t.length < 3 || !/^\d+$/.test(t)) return false;
        for (var i = 1; i < t.length; i++) {
            if (parseInt(t.charAt(i), 10) !== parseInt(t.charAt(i - 1), 10) + 1) return false;
        }
        return true;
    }

    function isDescTriplet(t) {
        if (t.length < 3 || !/^\d+$/.test(t)) return false;
        for (var i = 1; i < t.length; i++) {
            if (parseInt(t.charAt(i), 10) !== parseInt(t.charAt(i - 1), 10) - 1) return false;
        }
        return true;
    }

    function hasBadDigitPatterns(password) {
        var re = /\d+/g;
        var m;
        while ((m = re.exec(password)) !== null) {
            var run = m[0];
            if (run.length >= 3 && /^(\d)\1+$/.test(run)) return true;
            if (run.length < 3) continue;
            for (var i = 0; i <= run.length - 3; i++) {
                var tri = run.slice(i, i + 3);
                if (isAscTriplet(tri) || isDescTriplet(tri) || /^(\d)\1\1$/.test(tri)) return true;
            }
        }
        return false;
    }

    function validate(password, username) {
        username = (username || '').trim();
        password = password == null ? '' : String(password);
        if (!password) return 'كلمة المرور مطلوبة';
        if (password !== password.trim()) return 'كلمة المرور لا يجب أن تبدأ أو تنتهي بمسافة';
        if (password.length < MIN_LEN) return 'كلمة المرور قصيرة — الحد الأدنى ' + MIN_LEN + ' حرفاً';
        if (password.length > 128) return 'كلمة المرور طويلة جداً (128 حرفاً كحد أقصى)';
        if (!/[A-Z]/.test(password)) return 'يجب أن تحتوي كلمة المرور على حرف كبير (Capital) واحد على الأقل';
        if (!/[a-z]/.test(password)) return 'يجب أن تحتوي كلمة المرور على حرف صغير (Small) واحد على الأقل';
        if (!/\d/.test(password)) return 'يجب أن تحتوي كلمة المرور على رقم واحد على الأقل';
        var specialRe = new RegExp('[' + SPECIAL.replace(/[-[\]{}()*+?.,\\^$|#\s]/g, '\\$&') + ']');
        if (!specialRe.test(password)) {
            return 'يجب أن تحتوي كلمة المرور على رمز خاص واحد على الأقل (' + SPECIAL + ')';
        }
        if (/^(.)\1+$/u.test(password)) return 'كلمة المرور لا يمكن أن تكون مكررة بنفس الحرف';
        if (username && password.toLowerCase() === username.toLowerCase()) {
            return 'كلمة المرور يجب أن تختلف عن اسم الدخول';
        }
        if (WEAK.indexOf(password.toLowerCase()) >= 0) {
            return 'كلمة المرور ضعيفة أو شائعة — اختر كلمة أقوى';
        }
        if (hasBadDigitPatterns(password)) {
            return 'تجنّب تسلسلات الأرقام (مثل 123 أو 987) أو تكرار نفس الرقم (111)';
        }
        return null;
    }

    function randChar(pool) {
        if (global.crypto && global.crypto.getRandomValues) {
            var arr = new Uint32Array(1);
            global.crypto.getRandomValues(arr);
            return pool.charAt(arr[0] % pool.length);
        }
        return pool.charAt(Math.floor(Math.random() * pool.length));
    }

    function shuffle(arr) {
        for (var i = arr.length - 1; i > 0; i--) {
            var j = randChar('0123456789');
            j = parseInt(j, 10) % (i + 1);
            var t = arr[i];
            arr[i] = arr[j];
            arr[j] = t;
        }
        return arr;
    }

    function generate(username, length) {
        length = length || GEN_LEN;
        length = Math.max(MIN_LEN, Math.min(64, length));
        var upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        var lower = 'abcdefghjkmnpqrstuvwxyz';
        var digits = '23456789';
        var all = upper + lower + digits + SPECIAL;
        for (var attempt = 0; attempt < 80; attempt++) {
            var chars = [randChar(upper), randChar(lower), randChar(digits), randChar(SPECIAL)];
            while (chars.length < length) {
                chars.push(randChar(all));
            }
            shuffle(chars);
            var candidate = chars.join('');
            if (!validate(candidate, username)) return candidate;
        }
        return 'K9#mPx2$vLqN8@wR';
    }

    function fillInput(inputEl, username) {
        if (!inputEl) return '';
        var pwd = generate(username || '', GEN_LEN);
        inputEl.value = pwd;
        inputEl.type = 'text';
        try {
            inputEl.focus();
            inputEl.select();
        } catch (e) { /* ignore */ }
        return pwd;
    }

    function copyToClipboard(text) {
        if (!text) return Promise.reject(new Error('empty'));
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text);
        }
        return new Promise(function (resolve, reject) {
            try {
                var ta = document.createElement('textarea');
                ta.value = text;
                ta.setAttribute('readonly', '');
                ta.style.position = 'fixed';
                ta.style.left = '-9999px';
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
                resolve();
            } catch (err) {
                reject(err);
            }
        });
    }

    function attachToolbar(opts) {
        opts = opts || {};
        var inputId = opts.inputId;
        var usernameInputId = opts.usernameInputId || '';
        var wrapId = opts.wrapId;
        var inputEl = document.getElementById(inputId);
        if (!inputEl) return;

        var wrap = wrapId ? document.getElementById(wrapId) : inputEl.parentElement;
        if (!wrap) return;

        var row = document.createElement('div');
        row.className = 'admin-password-toolbar';
        row.style.cssText = 'display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:6px;';

        var genBtn = document.createElement('button');
        genBtn.type = 'button';
        genBtn.className = 'btn-secondary';
        genBtn.textContent = opts.generateLabel || 'توليد باسورد قوي';
        genBtn.addEventListener('click', function () {
            var uname = usernameInputId ? (document.getElementById(usernameInputId) || {}).value || '' : '';
            var pwd = fillInput(inputEl, uname.trim());
            if (opts.onGenerated) opts.onGenerated(pwd);
        });

        var copyBtn = document.createElement('button');
        copyBtn.type = 'button';
        copyBtn.className = 'btn-secondary';
        copyBtn.textContent = opts.copyLabel || 'نسخ';
        copyBtn.addEventListener('click', function () {
            var val = inputEl.value;
            if (!val) {
                alert('لا يوجد باسورد للنسخ');
                return;
            }
            copyToClipboard(val).then(function () {
                alert(opts.copyOkMessage || 'تم النسخ');
            }).catch(function () {
                alert('تعذّر النسخ — انسخ يدوياً');
            });
        });

        var showBtn = document.createElement('button');
        showBtn.type = 'button';
        showBtn.className = 'btn-secondary';
        showBtn.textContent = opts.showLabel || 'إظهار/إخفاء';
        showBtn.addEventListener('click', function () {
            inputEl.type = inputEl.type === 'password' ? 'text' : 'password';
        });

        row.appendChild(genBtn);
        row.appendChild(copyBtn);
        row.appendChild(showBtn);
        wrap.appendChild(row);
    }

    global.OrangeAdminPasswordPolicy = {
        minLength: MIN_LEN,
        specialChars: SPECIAL,
        validate: validate,
        generate: generate,
        fillInput: fillInput,
        attachToolbar: attachToolbar
    };
})(typeof window !== 'undefined' ? window : this);
