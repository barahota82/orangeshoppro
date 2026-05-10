-- Advisory sizing library: reusable bundles (source family) + map consumer family -> bundle.
-- Safe to re-run: IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS advisory_sizing_library_bundles (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name_ar VARCHAR(191) NOT NULL DEFAULT '',
    name_en VARCHAR(191) NOT NULL DEFAULT '',
    commercial_kind_key VARCHAR(32) NOT NULL DEFAULT '',
    source_size_family_id INT NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_aslb_source_family (source_size_family_id),
    KEY idx_aslb_commercial (commercial_kind_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS size_family_advisory_library_map (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    consumer_size_family_id INT NOT NULL,
    library_bundle_id INT UNSIGNED NOT NULL,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sfalm_consumer (consumer_size_family_id),
    KEY idx_sfalm_bundle (library_bundle_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
