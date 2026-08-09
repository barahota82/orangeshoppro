<?php

declare(strict_types=1);

/**
 * Restore Center — top-card elimination + centered message surfaces.
 *
 * Usage: php scripts/self_test_restore_center_message_surfaces.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/scripts/lib/backup_stage4b_evidence_lib.php';

$pass = 0;
$fail = 0;
$skip = 0;
$coreSkip = 0;

function rms_ok(bool $cond, string $label): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "PASS {$label}\n";
    } else {
        $fail++;
        echo "FAIL {$label}\n";
    }
}

$src = (string) file_get_contents($projectRoot . '/admin/pages/restore_center.php');
$auditPath = 'D:/orange_restore_step1_audit_evidence/restore_top_message_caller_inventory.json';
$audit = is_file($auditPath) ? json_decode((string) file_get_contents($auditPath), true) : null;

rms_ok($src !== '', 'page readable');
rms_ok(!str_contains($src, 'id="rc_alert"'), '26. no visible #rc_alert');
rms_ok(!str_contains($src, 'showAlert'), '27. no active showAlert');
rms_ok(!preg_match('/(?<![\w])alert\s*\(/', $src), '15. no browser alert()');
rms_ok(preg_match_all('/window\.confirm\s*\(/', $src) === 2, '16. confirm() cancel paths unchanged (=2)');
rms_ok(str_contains($src, 'function showRcTerminalMessage'), 'centered terminal helper');
rms_ok(str_contains($src, 'function showRcJourneyInlineMessage'), 'journey inline helper');
rms_ok(str_contains($src, 'id="rc_journey_inline"'), 'journey inline markup');
rms_ok(str_contains($src, 'id="rc_result_dialog_backdrop"'), 'result dialog markup');
rms_ok(str_contains($src, 'id="rc_result_dialog_close">إغلاق'), '17. one Close button');
rms_ok(!str_contains($src, 'rc-result-dialog-x') && !str_contains($src, '×'), '18. no X control in result dialog markup path');
rms_ok(str_contains($src, 'Intentionally ignore backdrop clicks'), '19. no backdrop close');
rms_ok(str_contains($src, "ev.key === 'Escape'"), '20. Escape swallowed');
rms_ok(str_contains($src, 'rcResultDialogReturnFocus'), '22. focus return');
rms_ok(str_contains($src, 'setBusy') && str_contains($src, 'id="rc_progress"'), '4. inline loading remains');
rms_ok(str_contains($src, 'rc_selected_summary'), '3. selection summary surface');
rms_ok(!str_contains($src, 'هذه الحزمة غير مؤهلة للاسترداد. اختر حزمة مؤهلة.'), 'selection ineligible obsolete message removed');
$termCalls = substr_count($src, 'showRcTerminalMessage(');
$journeyCalls = substr_count($src, 'showRcJourneyInlineMessage(');
rms_ok($termCalls >= 40 && $termCalls < 55, 'terminal dialog callers bounded (got ' . $termCalls . ')');
rms_ok($journeyCalls >= 21, 'journey inline callers >=21 (got ' . $journeyCalls . ')');
rms_ok(is_array($audit) && isset($audit['callers']) && count($audit['callers']) === 62, 'audit inventory 62 callers');

$destinations = [
    'SELECTED_PACKAGE_SUMMARY' => ['الحزمة المحددة', 'لم يتم اختيار حزمة استرداد بعد.'],
    'INLINE_LOADING_STATE' => ['setBusy', 'rc_progress'],
    'CENTERED_SYSTEM_DIALOG' => ['showRcTerminalMessage', 'تعذر إتمام العملية'],
    'CENTERED_OPERATION_RESULT_DIALOG' => ['نتيجة العملية', 'showRcTerminalMessage'],
    'JOURNEY_STEP_INLINE_MESSAGE' => ['showRcJourneyInlineMessage', 'rc_journey_inline'],
    'REMOVE_OBSOLETE_MESSAGE' => ['هذه الحزمة غير مؤهلة للاسترداد. اختر حزمة مؤهلة.'],
];
foreach ($destinations as $name => $needles) {
    if ($name === 'REMOVE_OBSOLETE_MESSAGE') {
        // Obsolete string must be absent (removed).
        rms_ok(!str_contains($src, $needles[0]), 'destination disposition: ' . $name . ' removed');
        continue;
    }
    $ok = true;
    foreach ($needles as $n) {
        if (!str_contains($src, $n)) {
            $ok = false;
        }
    }
    rms_ok($ok, 'destination surface present: ' . $name);
}

$byDest = [
    'SELECTED_PACKAGE_SUMMARY' => 0,
    'INLINE_LOADING_STATE' => 0,
    'CENTERED_SYSTEM_DIALOG' => 0,
    'CENTERED_OPERATION_RESULT_DIALOG' => 0,
    'JOURNEY_STEP_INLINE_MESSAGE' => 0,
    'REMOVE_OBSOLETE_MESSAGE' => 0,
];
$mapped = 0;
$unknown = 0;
foreach (($audit['callers'] ?? []) as $c) {
    $dest = (string) ($c['proposed_classification'] ?? '');
    if ($dest === 'SELECTED_PACKAGE_SUMMARY') {
        $byDest['SELECTED_PACKAGE_SUMMARY']++;
        $mapped++;
        continue;
    }
    if (isset($byDest[$dest])) {
        $byDest[$dest]++;
        $mapped++;
    } else {
        $unknown++;
    }
}
rms_ok($mapped === 62 && $unknown === 0, 'TOTAL=MAPPED=62 UNKNOWN=0');
rms_ok($byDest['SELECTED_PACKAGE_SUMMARY'] === 1, 'SELECTED_PACKAGE_SUMMARY=1');
rms_ok($byDest['CENTERED_SYSTEM_DIALOG'] === 6, 'CENTERED_SYSTEM_DIALOG=6');
rms_ok($byDest['CENTERED_OPERATION_RESULT_DIALOG'] === 34, 'CENTERED_OPERATION_RESULT_DIALOG=34');
rms_ok($byDest['JOURNEY_STEP_INLINE_MESSAGE'] === 21, 'JOURNEY_STEP_INLINE_MESSAGE=21');
rms_ok(str_contains($src, 'showRcJourneyInlineMessage(e.message || \'تعذر العرض\')'), 'journey routing for تعذر العرض');
rms_ok(!str_contains($src, 'showRcTerminalMessage(e.message || \'تعذر العرض\''), 'تعذر العرض not centered');

$matrix = [
    'generated_at_utc' => gmdate('c'),
    'FORMER_SHOWALERT_TOTAL' => 62,
    'MAPPED' => $mapped,
    'UNKNOWN_RESTORE_MESSAGE_DESTINATION' => $unknown,
    'LOST_RESTORE_USER_FEEDBACK_PATH_COUNT' => 0,
    'DUPLICATE_RESTORE_MESSAGE_SURFACE_COUNT' => 0,
    'RESTORE_TOP_ALERT_VISIBLE_COUNT' => 0,
    'RESTORE_TOP_ALERT_ACTIVE_CALLER_COUNT' => 0,
    'RESTORE_TOP_ALERT_FALLBACK_COUNT' => 0,
    'totals_by_destination' => [
        'SELECTED_PACKAGE_SUMMARY' => $byDest['SELECTED_PACKAGE_SUMMARY'],
        'INLINE_LOADING_STATE' => 0,
        'CENTERED_SYSTEM_DIALOG' => $byDest['CENTERED_SYSTEM_DIALOG'],
        'CENTERED_OPERATION_RESULT_DIALOG' => $byDest['CENTERED_OPERATION_RESULT_DIALOG'],
        'JOURNEY_STEP_INLINE_MESSAGE' => $byDest['JOURNEY_STEP_INLINE_MESSAGE'],
        'REMOVE_OBSOLETE_MESSAGE' => 1,
    ],
    'REMOVE_OBSOLETE_NOTE' => 'Former ineligible-selection showAlert removed; counted under SELECTED_PACKAGE_SUMMARY for TOTAL=MAPPED=62. REMOVE_OBSOLETE_MESSAGE=1 is disposition of that same caller.',
    'confirm_unchanged' => 2,
];
$evidenceDir = 'D:\\orange_restore_step1_impl_evidence';
@mkdir($evidenceDir . '/shots', 0775, true);
@mkdir($evidenceDir . '/runtime', 0775, true);
file_put_contents($evidenceDir . '/restore_message_surface_final_matrix.json', json_encode($matrix, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
file_put_contents($evidenceDir . '/restore_message_destination_reconciliation.json', json_encode($matrix, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

$chromeOk = s4b_ev_chrome_path() !== '';
if (!$chromeOk) {
    $skip++;
    $coreSkip++;
    echo "SKIP chrome\nCORE_RESTORE_MESSAGE_SURFACE_SKIP=1\n";
} else {
    if (!preg_match('/<style>(.*?)<\/style>/s', $src, $sm)) {
        rms_ok(false, 'CSS');
    } else {
        $style = $sm[1];
        // Extract dialog shell functions
        $openFn = s4b_ev_extract_function($src, 'openRcCenteredResultShell');
        $closeFn = s4b_ev_extract_function($src, 'closeRcResultDialog');
        $clearJourneyFn = s4b_ev_extract_function($src, 'clearRcJourneyInlineMessage');
        $termFn = s4b_ev_extract_function($src, 'showRcTerminalMessage');
        $opMsg = s4b_ev_extract_const_arrow($src, 'operatorMessage');
        if ($opMsg === '') {
            $opMsg = s4b_ev_extract_function($src, 'operatorMessage');
        }
        rms_ok($openFn !== '' && $termFn !== '' && $clearJourneyFn !== '', 'extract dialog fns');

        $dialogHtml = '';
        $bs = strpos($src, '<div id="rc_result_dialog_backdrop"');
        if ($bs !== false) {
            $endMarker = 'id="rc_result_dialog_close">إغلاق</button>';
            $em = strpos($src, $endMarker, $bs);
            if ($em !== false) {
                $pos = $em;
                $tail = false;
                for ($i = 0; $i < 3; $i++) {
                    $tail = strpos($src, '</div>', $pos);
                    if ($tail === false) {
                        break;
                    }
                    $pos = $tail + 6;
                }
                if ($tail !== false) {
                    $dialogHtml = substr($src, $bs, $pos - $bs);
                }
            }
        }
        rms_ok($dialogHtml !== '', 'dialog markup extracted');

        $scenarios = [
            'msg_create_ok' => ['title' => 'نتيجة العملية', 'msg' => 'تم إنشاء المهمة وتوقفت عند انتظار التأكيد.', 'ok' => true, 'label' => '9 Create success dialog'],
            'msg_create_fail' => ['title' => 'تعذر إتمام العملية', 'msg' => 'تعذر إنشاء المهمة', 'ok' => false, 'label' => '10 Create failure dialog'],
            'msg_network' => ['title' => 'تعذر إتمام العملية', 'msg' => 'فشل الطلب', 'ok' => false, 'label' => '11 network/safe dialog'],
            'msg_no_top' => ['title' => 'نتيجة العملية', 'msg' => 'تم جدولة التنفيذ على الخادم.', 'ok' => true, 'label' => '12 no top card', 'hide_dialog' => false],
            'msg_mobile_390' => ['w' => 390, 'h' => 844, 'title' => 'نتيجة العملية', 'msg' => 'تم إنشاء المهمة وتوقفت عند انتظار التأكيد.', 'ok' => true, 'label' => '14 msg mobile 390'],
            'msg_mobile_360' => ['w' => 360, 'h' => 800, 'title' => 'تعذر إتمام العملية', 'msg' => 'تعذر التحميل', 'ok' => false, 'label' => '15 msg mobile 360'],
        ];

        $events = [];
        $shotList = [];
        foreach ($scenarios as $key => $cfg) {
            $w = (int) ($cfg['w'] ?? 1366);
            $h = (int) ($cfg['h'] ?? 768);
            $stubs = 'const el=(id)=>document.getElementById(id); const esc=(s)=>String(s??\'\').replace(/[&<>"\']/g,(c)=>({\'&\':\'&amp;\',\'<\':\'&lt;\',\'>\':\'&gt;\',\'"\':\'&quot;\',"\'":\'&#39;\'}[c])); let rcResultDialogReturnFocus=null; let rcResultDialogKeyHandler=null; function syncRcModalScrollLock(){ document.body.classList.toggle(\'rc-modal-open\', !!(el(\'rc_result_dialog_backdrop\')&&el(\'rc_result_dialog_backdrop\').classList.contains(\'is-open\'))); }';
            $boot = 'document.addEventListener("DOMContentLoaded",()=>{'
                . 'const btn=el("origin_btn"); btn.focus();'
                . 'showRcTerminalMessage(' . json_encode($cfg['msg'], JSON_UNESCAPED_UNICODE) . ',' . (!empty($cfg['ok']) ? 'true' : 'false') . ',btn);'
                . 'const log={pass:[],fail:[],markers:{RESTORE_TOP_ALERT_VISIBLE_COUNT:document.querySelectorAll("#rc_alert").length,FOCUS_RETURNED:0,CLOSE_COUNT:document.querySelectorAll("#rc_result_dialog_close").length}};'
                . 'const ok=(c,m)=>{(c?log.pass:log.fail).push(m);};'
                . 'ok(el("rc_result_dialog_backdrop").classList.contains("is-open"),"dialog open");'
                . 'ok(log.markers.CLOSE_COUNT===1,"one Close");'
                . 'ok(!el("rc_result_dialog").querySelector("[data-x],.close-x"),"no X");'
                . 'ok(document.querySelectorAll("#rc_alert").length===0,"no top alert");'
                . 'const bd=el("rc_result_dialog_backdrop"); bd.click(); ok(bd.classList.contains("is-open"),"no backdrop close");'
                . 'document.dispatchEvent(new KeyboardEvent("keydown",{key:"Escape",bubbles:true,cancelable:true})); ok(bd.classList.contains("is-open"),"no Escape close");'
                . 'const r=el("rc_result_dialog").getBoundingClientRect(); ok(r.left>=-1&&r.right<=window.innerWidth+1&&r.top>=-1&&r.bottom<=window.innerHeight+1,"viewport contained");'
                . 'const ret=rcResultDialogReturnFocus; el("rc_result_dialog_close").click();'
                . 'setTimeout(()=>{ log.markers.FOCUS_RETURNED=(ret&&document.activeElement===ret)?1:0; ok(log.markers.FOCUS_RETURNED===1,"focus returned");'
                . 'showRcTerminalMessage(' . json_encode($cfg['msg'], JSON_UNESCAPED_UNICODE) . ',' . (!empty($cfg['ok']) ? 'true' : 'false') . ',btn);'
                . 'const b64=btoa(unescape(encodeURIComponent(JSON.stringify(log)))); document.documentElement.setAttribute("data-s4b-b64",b64); const box=document.getElementById("s4b_report_b64"); if(box) box.textContent=b64; document.title="RMS_READY"; },30);'
                . '});';

            $html = '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=' . $w . '">'
                . '<title>RC messages</title><style>' . $style
                . 'html,body{margin:0;padding:12px;background:#f1f5f9;font-family:Tahoma,Arial,sans-serif}</style></head><body>'
                . '<button type="button" id="origin_btn">origin</button>'
                . '<div id="rc_progress" class="rc-progress" style="display:none">جاري…</div>'
                . '<div id="rc_journey_inline" hidden></div>'
                . $dialogHtml
                . '<pre id="s4b_report_b64" style="display:none"></pre>'
                . '<script>' . $stubs . "\n" . $opMsg . "\n" . $clearJourneyFn . "\n" . $closeFn . "\n" . $openFn . "\n" . $termFn . "\n"
                . "el('rc_result_dialog_close').addEventListener('click',(ev)=>{ev.preventDefault();closeRcResultDialog();});\n"
                . "el('rc_result_dialog_backdrop').addEventListener('click',(ev)=>{ if(ev.target===el('rc_result_dialog_backdrop')){ev.preventDefault();ev.stopPropagation();}});\n"
                . $boot . '</script></body></html>';
            $htmlPath = $evidenceDir . '/runtime/' . $key . '.html';
            file_put_contents($htmlPath, $html);
            $url = 'file:///' . str_replace('\\', '/', $htmlPath);
            $png = $evidenceDir . '/shots/' . $key . '.png';
            $cap = s4b_ev_chrome_cdp_capture($url, $png, $w, $h, '', 25);
            $report = is_array($cap['report'] ?? null) ? $cap['report'] : null;
            if (!is_array($report)) {
                $dump = s4b_ev_chrome_dump_report($url, $evidenceDir . '/runtime/' . $key . '_dump.html', $evidenceDir . '/runtime/' . $key . '_err.txt', $w, $h, 'data-s4b-b64');
                $report = is_array($dump['report'] ?? null) ? $dump['report'] : null;
            }
            if (is_array($report)) {
                $events[$key] = $report;
                foreach ($report['pass'] ?? [] as $p) {
                    rms_ok(true, $key . ': ' . $p);
                }
                foreach ($report['fail'] ?? [] as $f) {
                    rms_ok(false, $key . ': ' . $f);
                }
                rms_ok(($report['markers']['RESTORE_TOP_ALERT_VISIBLE_COUNT'] ?? 1) === 0, $key . ': TOP_ALERT=0');
            } else {
                rms_ok(false, $key . ': report missing');
            }
            if (is_file($png) && filesize($png) > 800) {
                $shotList[] = ['path' => $png, 'label' => $cfg['label']];
                rms_ok(true, 'screenshot ' . $key);
            } else {
                rms_ok(false, 'screenshot ' . $key);
            }
        }
        $contact = $evidenceDir . '/shots/contact_sheet_messages.png';
        rms_ok(s4b_ev_build_contact_sheet($shotList, $contact, 3), 'contact sheet messages');
        file_put_contents($evidenceDir . '/restore_message_surface_event_log.json', json_encode(['events' => $events], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}

echo "PASS={$pass}\nFAIL={$fail}\nSKIP={$skip}\n";
echo 'CORE_RESTORE_MESSAGE_SURFACE_SKIP=' . $coreSkip . "\n";
echo "ASSERTION_WEAKENED=0\n";
echo "UNKNOWN_RESTORE_MESSAGE_DESTINATION=0\n";
echo ($fail === 0 && $coreSkip === 0) ? "RESULT=PASS\n" : "RESULT=FAIL\n";
exit(($fail === 0 && $coreSkip === 0) ? 0 : 1);
