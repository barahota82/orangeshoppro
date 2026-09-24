-- ORANGE Control Rev5 grants (OWNER-REVIEW REPAIR RETRY)
-- Placeholders: {{TRUST_EXECUTOR}} {{IDENTITY_EXECUTOR}} {{CONTROL_RUNTIME}} {{SCHEMA_ADMIN}}
-- Structured parser required (do not skip comment-prefixed chunks).
SET NAMES utf8mb4;

-- ============================================================================
-- trust_executor: Trust-Core callable + R01–R27 EXECUTE + positive SELECT allowlist
-- ============================================================================
GRANT EXECUTE ON PROCEDURE `orange_ctrl_register_mapping` TO {{TRUST_EXECUTOR}}@'localhost';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_register_mapping` TO {{TRUST_EXECUTOR}}@'127.0.0.1';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_registry_trust_transition` TO {{TRUST_EXECUTOR}}@'localhost';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_registry_trust_transition` TO {{TRUST_EXECUTOR}}@'127.0.0.1';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_country_lifecycle_transition` TO {{TRUST_EXECUTOR}}@'localhost';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_country_lifecycle_transition` TO {{TRUST_EXECUTOR}}@'127.0.0.1';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_emit_telemetry` TO {{TRUST_EXECUTOR}}@'localhost';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_emit_telemetry` TO {{TRUST_EXECUTOR}}@'127.0.0.1';

GRANT EXECUTE ON PROCEDURE `orange_ctrl_domain_registry_upsert` TO {{TRUST_EXECUTOR}}@'localhost';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_domain_registry_upsert` TO {{TRUST_EXECUTOR}}@'127.0.0.1';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_domain_registry_status_transition` TO {{TRUST_EXECUTOR}}@'localhost';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_domain_registry_status_transition` TO {{TRUST_EXECUTOR}}@'127.0.0.1';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_domain_host_upsert` TO {{TRUST_EXECUTOR}}@'localhost';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_domain_host_upsert` TO {{TRUST_EXECUTOR}}@'127.0.0.1';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_domain_host_status_transition` TO {{TRUST_EXECUTOR}}@'localhost';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_domain_host_status_transition` TO {{TRUST_EXECUTOR}}@'127.0.0.1';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_domain_host_health_record` TO {{TRUST_EXECUTOR}}@'localhost';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_domain_host_health_record` TO {{TRUST_EXECUTOR}}@'127.0.0.1';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_domain_authority_set_mode` TO {{TRUST_EXECUTOR}}@'localhost';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_domain_authority_set_mode` TO {{TRUST_EXECUTOR}}@'127.0.0.1';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_domain_cutover_cas_begin` TO {{TRUST_EXECUTOR}}@'localhost';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_domain_cutover_cas_begin` TO {{TRUST_EXECUTOR}}@'127.0.0.1';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_domain_cutover_cas_commit` TO {{TRUST_EXECUTOR}}@'localhost';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_domain_cutover_cas_commit` TO {{TRUST_EXECUTOR}}@'127.0.0.1';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_domain_cutover_cas_abort` TO {{TRUST_EXECUTOR}}@'localhost';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_domain_cutover_cas_abort` TO {{TRUST_EXECUTOR}}@'127.0.0.1';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_domain_dns_challenge_issue` TO {{TRUST_EXECUTOR}}@'localhost';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_domain_dns_challenge_issue` TO {{TRUST_EXECUTOR}}@'127.0.0.1';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_domain_dns_challenge_verify` TO {{TRUST_EXECUTOR}}@'localhost';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_domain_dns_challenge_verify` TO {{TRUST_EXECUTOR}}@'127.0.0.1';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_domain_redirect_permanent_approve` TO {{TRUST_EXECUTOR}}@'localhost';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_domain_redirect_permanent_approve` TO {{TRUST_EXECUTOR}}@'127.0.0.1';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_domain_emergency_rollback` TO {{TRUST_EXECUTOR}}@'localhost';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_domain_emergency_rollback` TO {{TRUST_EXECUTOR}}@'127.0.0.1';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_country_geo_flag_set` TO {{TRUST_EXECUTOR}}@'localhost';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_country_geo_flag_set` TO {{TRUST_EXECUTOR}}@'127.0.0.1';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_public_channel_route_upsert` TO {{TRUST_EXECUTOR}}@'localhost';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_public_channel_route_upsert` TO {{TRUST_EXECUTOR}}@'127.0.0.1';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_public_channel_route_status_transition` TO {{TRUST_EXECUTOR}}@'localhost';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_public_channel_route_status_transition` TO {{TRUST_EXECUTOR}}@'127.0.0.1';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_market_entry_policy_upsert` TO {{TRUST_EXECUTOR}}@'localhost';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_market_entry_policy_upsert` TO {{TRUST_EXECUTOR}}@'127.0.0.1';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_market_entry_policy_status_transition` TO {{TRUST_EXECUTOR}}@'localhost';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_market_entry_policy_status_transition` TO {{TRUST_EXECUTOR}}@'127.0.0.1';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_market_content_upsert` TO {{TRUST_EXECUTOR}}@'localhost';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_market_content_upsert` TO {{TRUST_EXECUTOR}}@'127.0.0.1';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_market_content_lifecycle_transition` TO {{TRUST_EXECUTOR}}@'localhost';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_market_content_lifecycle_transition` TO {{TRUST_EXECUTOR}}@'127.0.0.1';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_market_content_english_review_set` TO {{TRUST_EXECUTOR}}@'localhost';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_market_content_english_review_set` TO {{TRUST_EXECUTOR}}@'127.0.0.1';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_market_content_i18n_upsert` TO {{TRUST_EXECUTOR}}@'localhost';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_market_content_i18n_upsert` TO {{TRUST_EXECUTOR}}@'127.0.0.1';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_market_content_i18n_status_transition` TO {{TRUST_EXECUTOR}}@'localhost';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_market_content_i18n_status_transition` TO {{TRUST_EXECUTOR}}@'127.0.0.1';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_wa_global_upsert` TO {{TRUST_EXECUTOR}}@'localhost';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_wa_global_upsert` TO {{TRUST_EXECUTOR}}@'127.0.0.1';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_wa_global_status_transition` TO {{TRUST_EXECUTOR}}@'localhost';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_wa_global_status_transition` TO {{TRUST_EXECUTOR}}@'127.0.0.1';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_wa_country_upsert` TO {{TRUST_EXECUTOR}}@'localhost';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_wa_country_upsert` TO {{TRUST_EXECUTOR}}@'127.0.0.1';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_wa_country_status_transition` TO {{TRUST_EXECUTOR}}@'localhost';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_wa_country_status_transition` TO {{TRUST_EXECUTOR}}@'127.0.0.1';

GRANT SELECT (id, username, display_name, is_active, is_superuser, password_changed_at, created_at, updated_at)
ON TABLE ctrl_admins TO {{TRUST_EXECUTOR}}@'localhost';
GRANT SELECT (id, username, display_name, is_active, is_superuser, password_changed_at, created_at, updated_at)
ON TABLE ctrl_admins TO {{TRUST_EXECUTOR}}@'127.0.0.1';
GRANT SELECT (id, admin_id, session_token_hash, created_at, expires_at, last_seen_at, idle_timeout_sec, revoked_at)
ON TABLE ctrl_admin_sessions TO {{TRUST_EXECUTOR}}@'localhost';
GRANT SELECT (id, admin_id, session_token_hash, created_at, expires_at, last_seen_at, idle_timeout_sec, revoked_at)
ON TABLE ctrl_admin_sessions TO {{TRUST_EXECUTOR}}@'127.0.0.1';
GRANT SELECT (admin_id, resource_key, can_edit)
ON TABLE ctrl_admin_permissions TO {{TRUST_EXECUTOR}}@'localhost';
GRANT SELECT (admin_id, resource_key, can_edit)
ON TABLE ctrl_admin_permissions TO {{TRUST_EXECUTOR}}@'127.0.0.1';
GRANT SELECT (id, code, slug, geo_iso2_code, flag_emoji, lifecycle_status, row_version, name_en, name_ar)
ON TABLE ctrl_countries TO {{TRUST_EXECUTOR}}@'localhost';
GRANT SELECT (id, code, slug, geo_iso2_code, flag_emoji, lifecycle_status, row_version, name_en, name_ar)
ON TABLE ctrl_countries TO {{TRUST_EXECUTOR}}@'127.0.0.1';

-- ============================================================================
-- identity_executor: Rev4 identity/locale positive surface (NO domain mutation EXECUTE)
-- ============================================================================
GRANT SELECT (id, username, display_name, is_active, is_superuser, password_changed_at, created_at, updated_at)
ON TABLE ctrl_admins TO {{IDENTITY_EXECUTOR}}@'localhost';
GRANT SELECT (id, username, display_name, is_active, is_superuser, password_changed_at, created_at, updated_at)
ON TABLE ctrl_admins TO {{IDENTITY_EXECUTOR}}@'127.0.0.1';
GRANT SELECT (id, admin_id, created_at, expires_at, last_seen_at, idle_timeout_sec, revoked_at)
ON TABLE ctrl_admin_sessions TO {{IDENTITY_EXECUTOR}}@'localhost';
GRANT SELECT (id, admin_id, created_at, expires_at, last_seen_at, idle_timeout_sec, revoked_at)
ON TABLE ctrl_admin_sessions TO {{IDENTITY_EXECUTOR}}@'127.0.0.1';
GRANT SELECT (admin_id, resource_key, can_edit)
ON TABLE ctrl_admin_permissions TO {{IDENTITY_EXECUTOR}}@'localhost';
GRANT SELECT (admin_id, resource_key, can_edit)
ON TABLE ctrl_admin_permissions TO {{IDENTITY_EXECUTOR}}@'127.0.0.1';
GRANT SELECT (locale_code, display_name_en, display_name_native, dir, numbering_system, is_active_global, sort_order)
ON TABLE ctrl_locales TO {{IDENTITY_EXECUTOR}}@'localhost';
GRANT SELECT (locale_code, display_name_en, display_name_native, dir, numbering_system, is_active_global, sort_order)
ON TABLE ctrl_locales TO {{IDENTITY_EXECUTOR}}@'127.0.0.1';
GRANT SELECT (id, code, slug, lifecycle_status, name_en, name_ar, row_version)
ON TABLE ctrl_countries TO {{IDENTITY_EXECUTOR}}@'localhost';
GRANT SELECT (id, code, slug, lifecycle_status, name_en, name_ar, row_version)
ON TABLE ctrl_countries TO {{IDENTITY_EXECUTOR}}@'127.0.0.1';
GRANT SELECT (country_id, locale_code, is_enabled_admin, is_enabled_storefront, is_enabled_document, is_default_admin, is_default_storefront, is_default_document)
ON TABLE ctrl_country_locales TO {{IDENTITY_EXECUTOR}}@'localhost';
GRANT SELECT (country_id, locale_code, is_enabled_admin, is_enabled_storefront, is_enabled_document, is_default_admin, is_default_storefront, is_default_document)
ON TABLE ctrl_country_locales TO {{IDENTITY_EXECUTOR}}@'127.0.0.1';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_register_mapping` TO {{IDENTITY_EXECUTOR}}@'localhost';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_register_mapping` TO {{IDENTITY_EXECUTOR}}@'127.0.0.1';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_emit_telemetry` TO {{IDENTITY_EXECUTOR}}@'localhost';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_emit_telemetry` TO {{IDENTITY_EXECUTOR}}@'127.0.0.1';

-- RETRY_2 identity/locale callable surface (B06)
GRANT EXECUTE ON PROCEDURE `orange_ctrl_identity_emit_event` TO {{IDENTITY_EXECUTOR}}@'localhost';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_identity_emit_event` TO {{IDENTITY_EXECUTOR}}@'127.0.0.1';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_identity_admin_upsert` TO {{IDENTITY_EXECUTOR}}@'localhost';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_identity_admin_upsert` TO {{IDENTITY_EXECUTOR}}@'127.0.0.1';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_identity_set_superuser` TO {{IDENTITY_EXECUTOR}}@'localhost';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_identity_set_superuser` TO {{IDENTITY_EXECUTOR}}@'127.0.0.1';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_identity_set_global_access` TO {{IDENTITY_EXECUTOR}}@'localhost';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_identity_set_global_access` TO {{IDENTITY_EXECUTOR}}@'127.0.0.1';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_identity_set_password` TO {{IDENTITY_EXECUTOR}}@'localhost';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_identity_set_password` TO {{IDENTITY_EXECUTOR}}@'127.0.0.1';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_identity_permissions_replace` TO {{IDENTITY_EXECUTOR}}@'localhost';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_identity_permissions_replace` TO {{IDENTITY_EXECUTOR}}@'127.0.0.1';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_identity_country_access_set` TO {{IDENTITY_EXECUTOR}}@'localhost';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_identity_country_access_set` TO {{IDENTITY_EXECUTOR}}@'127.0.0.1';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_identity_session_issue` TO {{IDENTITY_EXECUTOR}}@'localhost';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_identity_session_issue` TO {{IDENTITY_EXECUTOR}}@'127.0.0.1';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_identity_session_revoke` TO {{IDENTITY_EXECUTOR}}@'localhost';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_identity_session_revoke` TO {{IDENTITY_EXECUTOR}}@'127.0.0.1';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_identity_login_throttle_apply` TO {{IDENTITY_EXECUTOR}}@'localhost';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_identity_login_throttle_apply` TO {{IDENTITY_EXECUTOR}}@'127.0.0.1';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_locale_global_upsert` TO {{IDENTITY_EXECUTOR}}@'localhost';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_locale_global_upsert` TO {{IDENTITY_EXECUTOR}}@'127.0.0.1';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_locale_country_locale_set` TO {{IDENTITY_EXECUTOR}}@'localhost';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_locale_country_locale_set` TO {{IDENTITY_EXECUTOR}}@'127.0.0.1';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_locale_admin_locale_preference_set` TO {{IDENTITY_EXECUTOR}}@'localhost';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_locale_admin_locale_preference_set` TO {{IDENTITY_EXECUTOR}}@'127.0.0.1';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_locale_session_locale_override_set` TO {{IDENTITY_EXECUTOR}}@'localhost';
GRANT EXECUTE ON PROCEDURE `orange_ctrl_locale_session_locale_override_set` TO {{IDENTITY_EXECUTOR}}@'127.0.0.1';


-- ============================================================================
-- control_runtime: resolver-safe reads only (complete content columns)
-- ============================================================================
GRANT SELECT (
  id, domain_uuid, environment, https_policy, registry_status, display_label, row_version, created_at, updated_at
) ON TABLE ctrl_domain_registry TO {{CONTROL_RUNTIME}}@'localhost';
GRANT SELECT (
  id, domain_uuid, environment, https_policy, registry_status, display_label, row_version, created_at, updated_at
) ON TABLE ctrl_domain_registry TO {{CONTROL_RUNTIME}}@'127.0.0.1';

GRANT SELECT (
  id, host_uuid, registry_id, environment, normalized_host, host_role, host_status, redirect_policy,
  verification_method, verified_at, activated_at, suspended_at, health_status, last_health_at,
  is_active, row_version, created_at, updated_at
) ON TABLE ctrl_domain_hosts TO {{CONTROL_RUNTIME}}@'localhost';
GRANT SELECT (
  id, host_uuid, registry_id, environment, normalized_host, host_role, host_status, redirect_policy,
  verification_method, verified_at, activated_at, suspended_at, health_status, last_health_at,
  is_active, row_version, created_at, updated_at
) ON TABLE ctrl_domain_hosts TO {{CONTROL_RUNTIME}}@'127.0.0.1';

GRANT SELECT (
  environment, host_authority_mode, active_canonical_host_id, previous_canonical_host_id,
  monitoring_until, activated_at, last_cutover_at, row_version, updated_at
) ON TABLE ctrl_domain_authority TO {{CONTROL_RUNTIME}}@'localhost';
GRANT SELECT (
  environment, host_authority_mode, active_canonical_host_id, previous_canonical_host_id,
  monitoring_until, activated_at, last_cutover_at, row_version, updated_at
) ON TABLE ctrl_domain_authority TO {{CONTROL_RUNTIME}}@'127.0.0.1';

GRANT SELECT (
  id, route_uuid, country_id, country_code_snapshot, match_kind, match_value, canonical_channel_slug,
  source_channel_id_snapshot, route_status, redirect_policy, row_version
) ON TABLE ctrl_public_channel_route_registry TO {{CONTROL_RUNTIME}}@'localhost';
GRANT SELECT (
  id, route_uuid, country_id, country_code_snapshot, match_kind, match_value, canonical_channel_slug,
  source_channel_id_snapshot, route_status, redirect_policy, row_version
) ON TABLE ctrl_public_channel_route_registry TO {{CONTROL_RUNTIME}}@'127.0.0.1';

GRANT SELECT (
  country_id, market_status, unavailable_message_mode, market_content_id, allow_global_wa_fallback,
  international_shipping_mode, is_market_open, row_version
) ON TABLE ctrl_market_entry_policy TO {{CONTROL_RUNTIME}}@'localhost';
GRANT SELECT (
  country_id, market_status, unavailable_message_mode, market_content_id, allow_global_wa_fallback,
  international_shipping_mode, is_market_open, row_version
) ON TABLE ctrl_market_entry_policy TO {{CONTROL_RUNTIME}}@'127.0.0.1';

GRANT SELECT (
  id, content_key, mother_locale, mother_title_utf8, mother_body_utf8,
  source_revision, source_hash, approved_en_title_utf8, approved_en_body_utf8,
  approved_en_revision, approved_en_hash, english_review_status,
  lifecycle_status, row_version
) ON TABLE ctrl_market_content TO {{CONTROL_RUNTIME}}@'localhost';
GRANT SELECT (
  id, content_key, mother_locale, mother_title_utf8, mother_body_utf8,
  source_revision, source_hash, approved_en_title_utf8, approved_en_body_utf8,
  approved_en_revision, approved_en_hash, english_review_status,
  lifecycle_status, row_version
) ON TABLE ctrl_market_content TO {{CONTROL_RUNTIME}}@'127.0.0.1';

GRANT SELECT (
  content_id, locale_code, title_utf8, body_utf8,
  translated_from_en_revision, translated_from_en_hash,
  translation_status, review_status, row_version
) ON TABLE ctrl_market_content_i18n TO {{CONTROL_RUNTIME}}@'localhost';
GRANT SELECT (
  content_id, locale_code, title_utf8, body_utf8,
  translated_from_en_revision, translated_from_en_hash,
  translation_status, review_status, row_version
) ON TABLE ctrl_market_content_i18n TO {{CONTROL_RUNTIME}}@'127.0.0.1';

GRANT SELECT (id, wa_uuid, display_label, prefilled_message_content_id, normalized_e164, contact_status, is_active_slot, row_version)
ON TABLE ctrl_contact_whatsapp_global TO {{CONTROL_RUNTIME}}@'localhost';
GRANT SELECT (id, wa_uuid, display_label, prefilled_message_content_id, normalized_e164, contact_status, is_active_slot, row_version)
ON TABLE ctrl_contact_whatsapp_global TO {{CONTROL_RUNTIME}}@'127.0.0.1';

GRANT SELECT (id, wa_uuid, country_id, display_label, prefilled_message_content_id, normalized_e164, contact_status, is_active_slot, row_version)
ON TABLE ctrl_contact_whatsapp_country TO {{CONTROL_RUNTIME}}@'localhost';
GRANT SELECT (id, wa_uuid, country_id, display_label, prefilled_message_content_id, normalized_e164, contact_status, is_active_slot, row_version)
ON TABLE ctrl_contact_whatsapp_country TO {{CONTROL_RUNTIME}}@'127.0.0.1';

GRANT SELECT (id, code, slug, geo_iso2_code, flag_emoji, lifecycle_status, row_version, name_en, name_ar)
ON TABLE ctrl_countries TO {{CONTROL_RUNTIME}}@'localhost';
GRANT SELECT (id, code, slug, geo_iso2_code, flag_emoji, lifecycle_status, row_version, name_en, name_ar)
ON TABLE ctrl_countries TO {{CONTROL_RUNTIME}}@'127.0.0.1';

FLUSH PRIVILEGES;
