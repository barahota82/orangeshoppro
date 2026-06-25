<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/delivery_areas.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_once __DIR__ . '/../../../includes/currency.php';
require_admin_api();

/**
 * @param mixed $v
 */
function da_str191($v): string
{
    $s = trim((string) $v);

    return function_exists('mb_substr') ? mb_substr($s, 0, 191, 'UTF-8') : substr($s, 0, 191);
}

/**
 * @param mixed $v
 */
function da_money_non_negative($v, int $decimals): ?float
{
    $s = trim((string) $v);
    if ($s === '') {
        return 0.0;
    }
    $s = str_replace(',', '.', $s);
    if (!is_numeric($s)) {
        return null;
    }
    $n = (float) $s;
    if (!is_finite($n) || $n < 0) {
        return null;
    }
    $d = max(0, min(4, $decimals));

    return round($n, $d);
}

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    $data = get_json_input();
    if (!is_array($data) || count($data) === 0) {
        $data = $_POST;
    }
    $action = trim((string) ($data['action'] ?? 'list'));

    if (!orange_table_exists($pdo, 'delivery_areas')) {
        json_response(['success' => false, 'message' => 'جدول delivery_areas غير جاهز'], 422);
    }

    if ($action === 'list_companies') {
        json_response([
            'success' => true,
            'data' => orange_delivery_companies_list($pdo, orange_delivery_areas_api_country_id($pdo, is_array($data) ? $data : [])),
        ]);
    }

    $countryId = orange_delivery_areas_api_country_id($pdo, is_array($data) ? $data : []);
    $countryMoneyDecimals = orange_currency_decimals_for_code(
        orange_country_functional_currency_code($pdo, $countryId)
    );

    if ($action === 'get_policy') {
        $policy = orange_delivery_country_policy_read($pdo, $countryId);
        json_response([
            'success' => true,
            'data' => [
                'default_delivery_fee' => (float) ($policy['default_delivery_fee'] ?? 0.0),
                'delivery_fee_policy' => (string) ($policy['delivery_fee_policy'] ?? 'paid_all'),
                'active_areas_count' => orange_delivery_areas_count_active($pdo, $countryId),
            ],
        ]);
    }

    if ($action === 'save_policy') {
        if ($countryId <= 0) {
            json_response(['success' => false, 'message' => 'الدولة غير محددة'], 422);
        }
        $rawPolicy = strtolower(trim((string) ($data['delivery_fee_policy'] ?? 'paid_all')));
        if (!isset(orange_delivery_fee_policy_values()[$rawPolicy])) {
            json_response(['success' => false, 'message' => 'اختر سياسة توصيل صحيحة'], 422);
        }
        $defaultFee = da_money_non_negative($data['default_delivery_fee'] ?? '', $countryMoneyDecimals);
        if ($defaultFee === null) {
            json_response(['success' => false, 'message' => 'قيمة التوصيل الافتراضية غير صحيحة'], 422);
        }
        $applyActiveAreas = !empty($data['apply_active_areas']);
        $appliedCount = 0;

        $pdo->beginTransaction();
        try {
            orange_delivery_country_policy_save($pdo, $countryId, (float) $defaultFee, $rawPolicy);
            if ($applyActiveAreas) {
                $appliedCount = orange_delivery_apply_default_fee_to_active_areas($pdo, $countryId, (float) $defaultFee);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $saved = orange_delivery_country_policy_read($pdo, $countryId);
        $msg = $applyActiveAreas
            ? ('تم حفظ السياسة وتطبيق القيمة على ' . $appliedCount . ' منطقة نشطة')
            : 'تم حفظ سياسة قيمة التوصيل';

        json_response([
            'success' => true,
            'message' => $msg,
            'data' => [
                'default_delivery_fee' => (float) ($saved['default_delivery_fee'] ?? 0.0),
                'delivery_fee_policy' => (string) ($saved['delivery_fee_policy'] ?? 'paid_all'),
                'active_areas_count' => orange_delivery_areas_count_active($pdo, $countryId),
                'applied_count' => $appliedCount,
            ],
        ]);
    }

    if ($action === 'list_governorates') {
        json_response([
            'success' => true,
            'data' => orange_delivery_governorates_admin_list($pdo, $countryId),
        ]);
    }

    if ($action === 'save_governorate') {
        if (!orange_delivery_governorates_table_exists($pdo)) {
            json_response(['success' => false, 'message' => 'جدول المحافظات غير جاهز'], 422);
        }
        $id = (int) ($data['id'] ?? 0);
        $nameAr = da_str191($data['name_ar'] ?? '');
        $nameEn = da_str191($data['name_en'] ?? '');
        $isActive = !empty($data['is_active']) ? 1 : 0;

        if ($nameAr === '') {
            json_response(['success' => false, 'message' => 'اسم المحافظة بالعربي مطلوب'], 422);
        }
        if ($countryId <= 0) {
            json_response(['success' => false, 'message' => 'الدولة غير محددة'], 422);
        }

        // سياسة المالك (Option B): قيمة وتكلفة التوصيل الافتراضيتان للمحافظة إلزاميتان وأكبر من صفر.
        // (التوصيل المجاني = صفر يُضبط من شاشة عروض التوصيل، لا من هنا.) تُتحقّق مبكراً قبل أي كتابة لتجنّب حفظ جزئي.
        $hasGovDefaults = orange_delivery_governorates_has_default_amounts_column($pdo);
        $defFee = null;
        $defCost = null;
        if ($hasGovDefaults) {
            $defFee = da_money_non_negative(trim((string) ($data['default_delivery_fee'] ?? '')), $countryMoneyDecimals);
            $defCost = da_money_non_negative(trim((string) ($data['default_company_delivery_cost'] ?? '')), $countryMoneyDecimals);
            if ($defFee === null || (float) $defFee <= 0.0) {
                json_response(['success' => false, 'message' => 'قيمة التوصيل الافتراضية للمحافظة مطلوبة ويجب أن تكون أكبر من صفر (التوصيل المجاني يُضبط من شاشة عروض التوصيل).'], 422);
            }
            if ($defCost === null || (float) $defCost <= 0.0) {
                json_response(['success' => false, 'message' => 'تكلفة التوصيل على الشركة الافتراضية للمحافظة مطلوبة ويجب أن تكون أكبر من صفر.'], 422);
            }
        }

        if ($id > 0) {
            try {
                orange_admin_assert_row_country($pdo, 'delivery_governorates', $id);
            } catch (RuntimeException $e) {
                json_response(['success' => false, 'message' => $e->getMessage()], 403);
            }
            $st = $pdo->prepare(
                'UPDATE delivery_governorates SET name_ar = ?, name_en = ?, is_active = ?, country_id = ? WHERE id = ?'
            );
            $st->execute([$nameAr, $nameEn, $isActive, $countryId, $id]);
        } else {
            $sortOrder = orange_delivery_governorates_next_sort_order($pdo, $countryId);
            $st = $pdo->prepare(
                'INSERT INTO delivery_governorates (country_id, name_ar, name_en, sort_order, is_active) VALUES (?, ?, ?, ?, ?)'
            );
            $st->execute([$countryId, $nameAr, $nameEn, $sortOrder, $isActive]);
            $id = (int) $pdo->lastInsertId();
        }

        if (orange_delivery_governorates_has_company_column($pdo)
            && array_key_exists('delivery_company_id', is_array($data) ? $data : [])
            && $id > 0
        ) {
            $companyId = (int) ($data['delivery_company_id'] ?? 0);
            if ($companyId > 0 && orange_table_exists($pdo, 'suppliers')) {
                $supSql = 'SELECT id FROM suppliers WHERE id = ?';
                $supParams = [$companyId];
                if (orange_table_has_column($pdo, 'suppliers', 'country_id')) {
                    $supSql .= ' AND (country_id = ? OR country_id IS NULL)';
                    $supParams[] = $countryId;
                }
                $supSql .= ' LIMIT 1';
                $chk = $pdo->prepare($supSql);
                $chk->execute($supParams);
                if (!$chk->fetch()) {
                    json_response(['success' => false, 'message' => 'شركة التوصيل (المورّد) غير موجودة لهذه الدولة'], 422);
                }
            }
            $u = $pdo->prepare('UPDATE delivery_governorates SET delivery_company_id = ? WHERE id = ?');
            $u->execute([$companyId > 0 ? $companyId : null, $id]);
        }

        if ($hasGovDefaults && $id > 0) {
            $feeVal = round(max(0.0, (float) $defFee), max(0, min(4, $countryMoneyDecimals)));
            $costVal = round(max(0.0, (float) $defCost), max(0, min(4, $countryMoneyDecimals)));
            $ud = $pdo->prepare('UPDATE delivery_governorates SET default_delivery_fee = ?, default_company_delivery_cost = ? WHERE id = ?');
            $ud->execute([$feeVal, $costVal, $id]);

            if (orange_delivery_areas_has_follow_flags_column($pdo)) {
                $feeApplyAll = !empty($data['fee_apply_all']);
                $costApplyAll = !empty($data['cost_apply_all']);
                $hasPend = orange_delivery_areas_has_pending_fee_column($pdo);
                $pendExpr = $hasPend ? ', delivery_fee_pending = 0' : '';
                // الترحيل يقتصر على المناطق المتاحة للتوصيل (النشطة) فقط — غير المتاحة تبقى صفراً بلا قيمة.
                if ($feeApplyAll) {
                    $pdo->prepare('UPDATE delivery_areas SET delivery_fee = ?, fee_follows_gov = 1' . $pendExpr . ' WHERE governorate_id = ? AND is_active = 1')
                        ->execute([$feeVal, $id]);
                } else {
                    $pdo->prepare('UPDATE delivery_areas SET delivery_fee = ?' . $pendExpr . ' WHERE governorate_id = ? AND is_active = 1 AND fee_follows_gov = 1')
                        ->execute([$feeVal, $id]);
                }

                if (orange_delivery_areas_has_company_cost_column($pdo)) {
                    if ($costApplyAll) {
                        $pdo->prepare('UPDATE delivery_areas SET company_delivery_cost = ?, cost_follows_gov = 1 WHERE governorate_id = ? AND is_active = 1')
                            ->execute([$costVal, $id]);
                    } else {
                        $pdo->prepare('UPDATE delivery_areas SET company_delivery_cost = ? WHERE governorate_id = ? AND is_active = 1 AND cost_follows_gov = 1')
                            ->execute([$costVal, $id]);
                    }
                }
            }
        }

        json_response(['success' => true, 'message' => 'تم حفظ المحافظة']);
    }

    if ($action === 'list') {
        json_response(['success' => true, 'data' => orange_delivery_areas_admin_list($pdo, $countryId)]);
    }

    if ($action === 'save') {
        $id = (int) ($data['id'] ?? 0);
        $governorateId = (int) ($data['governorate_id'] ?? 0);
        $nameAr = da_str191($data['name_ar'] ?? '');
        $nameEn = da_str191($data['name_en'] ?? '');
        $deliveryFeeRaw = trim((string) ($data['delivery_fee'] ?? ''));
        $isActive = !empty($data['is_active']) ? 1 : 0;
        $hasFeePendingCol = orange_delivery_areas_has_pending_fee_column($pdo);
        $hasGovCol = orange_delivery_areas_has_governorate_column($pdo);
        $hasCountryCol = orange_delivery_areas_has_country_column($pdo);
        $hasCompanyCostCol = orange_delivery_areas_has_company_cost_column($pdo);
        $hasFollowCols = orange_delivery_areas_has_follow_flags_column($pdo);
        $feeFollows = $hasFollowCols && !empty($data['fee_follows_gov']);
        $costFollows = $hasFollowCols && !empty($data['cost_follows_gov']);
        $moneyDec = max(0, min(4, $countryMoneyDecimals));

        if ($nameAr === '') {
            json_response(['success' => false, 'message' => 'اسم المنطقة بالعربي مطلوب'], 422);
        }
        if ($countryId <= 0) {
            json_response(['success' => false, 'message' => 'الدولة غير محددة'], 422);
        }
        if ($hasGovCol && $governorateId <= 0) {
            json_response(['success' => false, 'message' => 'اختر المحافظة'], 422);
        }
        if ($hasGovCol && $governorateId > 0) {
            $gv = $pdo->prepare('SELECT id FROM delivery_governorates WHERE id = ? AND country_id = ? LIMIT 1');
            $gv->execute([$governorateId, $countryId]);
            if (!$gv->fetch()) {
                json_response(['success' => false, 'message' => 'المحافظة غير موجودة لهذه الدولة'], 422);
            }
        }

        // افتراضيا المحافظة (مصدر الختم عند التتبّع)
        $govDefFee = null;
        $govDefCost = null;
        if ($hasGovCol && $governorateId > 0 && orange_delivery_governorates_has_default_amounts_column($pdo)) {
            $gd = $pdo->prepare('SELECT default_delivery_fee, default_company_delivery_cost FROM delivery_governorates WHERE id = ? LIMIT 1');
            $gd->execute([$governorateId]);
            $gdRow = $gd->fetch(PDO::FETCH_ASSOC) ?: [];
            $govDefFee = (($gdRow['default_delivery_fee'] ?? null) === null) ? null : (float) $gdRow['default_delivery_fee'];
            $govDefCost = (($gdRow['default_company_delivery_cost'] ?? null) === null) ? null : (float) $gdRow['default_company_delivery_cost'];
        }

        // القيمة النهائية: التتبّع يختم من المحافظة، وإلا من المُدخل (الفارغ = صفر؛ غير المتاحة تبقى صفراً)
        if ($feeFollows && $govDefFee !== null) {
            $deliveryFee = round(max(0.0, $govDefFee), $moneyDec);
        } elseif ($deliveryFeeRaw === '') {
            $deliveryFee = 0.0;
        } else {
            $deliveryFee = da_money_non_negative($deliveryFeeRaw, $countryMoneyDecimals);
        }
        if ($deliveryFee === null) {
            json_response(['success' => false, 'message' => 'قيمة التوصيل غير صحيحة'], 422);
        }

        if ($costFollows && $govDefCost !== null) {
            $companyCost = round(max(0.0, $govDefCost), $moneyDec);
        } else {
            $companyCost = da_money_non_negative($data['company_delivery_cost'] ?? '', $countryMoneyDecimals);
        }
        if ($companyCost === null) {
            json_response(['success' => false, 'message' => 'تكلفة التوصيل على الشركة غير صحيحة'], 422);
        }

        // سياسة المالك: أي منطقة متاحة للتوصيل (نشطة) يجب أن تحمل قيمة توصيل وتكلفة توصيل أكبر من صفر.
        if ($isActive === 1) {
            if ((float) $deliveryFee <= 0.0) {
                json_response([
                    'success' => false,
                    'message' => 'لا يمكن تفعيل التوصيل لهذه المنطقة بدون قيمة توصيل. أدخل قيمة أكبر من صفر، أو فعّل «تتبع المحافظة» مع وجود قيمة افتراضية للمحافظة.',
                ], 422);
            }
            if ($hasCompanyCostCol && (float) $companyCost <= 0.0) {
                json_response([
                    'success' => false,
                    'message' => 'لا يمكن تفعيل التوصيل لهذه المنطقة بدون تكلفة توصيل على الشركة. أدخل تكلفة أكبر من صفر، أو فعّل «تتبع المحافظة».',
                ], 422);
            }
        }

        $feePending = 0;

        if ($id > 0) {
            try {
                orange_admin_assert_row_country($pdo, 'delivery_areas', $id);
            } catch (RuntimeException $e) {
                json_response(['success' => false, 'message' => $e->getMessage()], 403);
            }
            if ($hasCountryCol && $hasGovCol) {
                if ($hasFeePendingCol) {
                    $st = $pdo->prepare(
                        'UPDATE delivery_areas
                         SET name_ar = ?, name_en = ?, delivery_fee = ?, delivery_fee_pending = ?, is_active = ?, country_id = ?, governorate_id = ?
                         WHERE id = ?'
                    );
                    $st->execute([$nameAr, $nameEn, $deliveryFee, $feePending, $isActive, $countryId, $governorateId, $id]);
                } else {
                    $st = $pdo->prepare(
                        'UPDATE delivery_areas
                         SET name_ar = ?, name_en = ?, delivery_fee = ?, is_active = ?, country_id = ?, governorate_id = ?
                         WHERE id = ?'
                    );
                    $st->execute([$nameAr, $nameEn, $deliveryFee, $isActive, $countryId, $governorateId, $id]);
                }
            } elseif ($hasCountryCol) {
                if ($hasFeePendingCol) {
                    $st = $pdo->prepare(
                        'UPDATE delivery_areas
                         SET name_ar = ?, name_en = ?, delivery_fee = ?, delivery_fee_pending = ?, is_active = ?, country_id = ?
                         WHERE id = ?'
                    );
                    $st->execute([$nameAr, $nameEn, $deliveryFee, $feePending, $isActive, $countryId, $id]);
                } else {
                    $st = $pdo->prepare(
                        'UPDATE delivery_areas
                         SET name_ar = ?, name_en = ?, delivery_fee = ?, is_active = ?, country_id = ?
                         WHERE id = ?'
                    );
                    $st->execute([$nameAr, $nameEn, $deliveryFee, $isActive, $countryId, $id]);
                }
            } else {
                if ($hasFeePendingCol) {
                    $st = $pdo->prepare(
                        'UPDATE delivery_areas
                         SET name_ar = ?, name_en = ?, delivery_fee = ?, delivery_fee_pending = ?, is_active = ?
                         WHERE id = ?'
                    );
                    $st->execute([$nameAr, $nameEn, $deliveryFee, $feePending, $isActive, $id]);
                } else {
                    $st = $pdo->prepare(
                        'UPDATE delivery_areas
                         SET name_ar = ?, name_en = ?, delivery_fee = ?, is_active = ?
                         WHERE id = ?'
                    );
                    $st->execute([$nameAr, $nameEn, $deliveryFee, $isActive, $id]);
                }
            }
        } else {
            $sortOrder = orange_delivery_areas_next_sort_order($pdo, $countryId, $governorateId);
            if ($hasCountryCol && $hasGovCol) {
                if ($hasFeePendingCol) {
                    $st = $pdo->prepare(
                        'INSERT INTO delivery_areas
                         (name_ar, name_en, delivery_fee, delivery_fee_pending, sort_order, is_active, country_id, governorate_id)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $st->execute([$nameAr, $nameEn, $deliveryFee, $feePending, $sortOrder, $isActive, $countryId, $governorateId]);
                } else {
                    $st = $pdo->prepare(
                        'INSERT INTO delivery_areas
                         (name_ar, name_en, delivery_fee, sort_order, is_active, country_id, governorate_id)
                         VALUES (?, ?, ?, ?, ?, ?, ?)'
                    );
                    $st->execute([$nameAr, $nameEn, $deliveryFee, $sortOrder, $isActive, $countryId, $governorateId]);
                }
            } elseif ($hasCountryCol) {
                if ($hasFeePendingCol) {
                    $st = $pdo->prepare(
                        'INSERT INTO delivery_areas
                         (name_ar, name_en, delivery_fee, delivery_fee_pending, sort_order, is_active, country_id)
                         VALUES (?, ?, ?, ?, ?, ?, ?)'
                    );
                    $st->execute([$nameAr, $nameEn, $deliveryFee, $feePending, $sortOrder, $isActive, $countryId]);
                } else {
                    $st = $pdo->prepare(
                        'INSERT INTO delivery_areas
                         (name_ar, name_en, delivery_fee, sort_order, is_active, country_id)
                         VALUES (?, ?, ?, ?, ?, ?)'
                    );
                    $st->execute([$nameAr, $nameEn, $deliveryFee, $sortOrder, $isActive, $countryId]);
                }
            } else {
                if ($hasFeePendingCol) {
                    $st = $pdo->prepare(
                        'INSERT INTO delivery_areas
                         (name_ar, name_en, delivery_fee, delivery_fee_pending, sort_order, is_active)
                         VALUES (?, ?, ?, ?, ?, ?)'
                    );
                    $st->execute([$nameAr, $nameEn, $deliveryFee, $feePending, $sortOrder, $isActive]);
                } else {
                    $st = $pdo->prepare(
                        'INSERT INTO delivery_areas (name_ar, name_en, delivery_fee, sort_order, is_active) VALUES (?, ?, ?, ?, ?)'
                    );
                    $st->execute([$nameAr, $nameEn, $deliveryFee, $sortOrder, $isActive]);
                }
            }
        }

        $areaId = $id > 0 ? $id : (int) $pdo->lastInsertId();

        if ($hasCompanyCostCol && $areaId > 0) {
            $u = $pdo->prepare('UPDATE delivery_areas SET company_delivery_cost = ? WHERE id = ?');
            $u->execute([$companyCost, $areaId]);
        }

        if ($hasFollowCols && $areaId > 0) {
            $uf = $pdo->prepare('UPDATE delivery_areas SET fee_follows_gov = ?, cost_follows_gov = ? WHERE id = ?');
            $uf->execute([$feeFollows ? 1 : 0, $costFollows ? 1 : 0, $areaId]);
        }

        json_response(['success' => true, 'message' => 'تم حفظ المنطقة']);
    }

    json_response(['success' => false, 'message' => 'إجراء غير معروف'], 422);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر حفظ بيانات المناطق');
}
