SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------------------------
-- 1. TABLA: users (Usuarios del sistema / Tenancy Auth)
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `users`;

CREATE TABLE
  `users` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `name` varchar(255) NOT NULL,
    `email` varchar(255) NOT NULL,
    `email_verified_at` timestamp NULL DEFAULT NULL,
    `password` varchar(255) NOT NULL,
    `remember_token` varchar(100) DEFAULT NULL,
    `created_at` timestamp NULL DEFAULT NULL,
    `updated_at` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY (`email`)
  );

-- ------------------------------------------------------------------------------
-- 2. TABLA: password_reset_tokens
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `password_reset_tokens`;

CREATE TABLE
  `password_reset_tokens` (
    `email` varchar(255) NOT NULL,
    `token` varchar(255) NOT NULL,
    `created_at` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`email`)
  );

-- ------------------------------------------------------------------------------
-- 3. TABLA: sessions
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `sessions`;

CREATE TABLE
  `sessions` (
    `id` varchar(255) NOT NULL,
    `user_id` bigint unsigned DEFAULT NULL,
    `ip_address` varchar(45) DEFAULT NULL,
    `user_agent` text,
    `payload` longtext NOT NULL,
    `last_activity` int NOT NULL,
    PRIMARY KEY (`id`),
    KEY (`user_id`),
    KEY (`last_activity`)
  );

-- ------------------------------------------------------------------------------
-- 4. TABLA: cache
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `cache`;

CREATE TABLE
  `cache` (
    `key` varchar(255) NOT NULL,
    `value` mediumtext NOT NULL,
    `expiration` bigint NOT NULL,
    PRIMARY KEY (`key`),
    KEY (`expiration`)
  );

-- ------------------------------------------------------------------------------
-- 5. TABLA: cache_locks
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `cache_locks`;

CREATE TABLE
  `cache_locks` (
    `key` varchar(255) NOT NULL,
    `owner` varchar(255) NOT NULL,
    `expiration` bigint NOT NULL,
    PRIMARY KEY (`key`),
    KEY (`expiration`)
  );

-- ------------------------------------------------------------------------------
-- 6. TABLA: jobs (Cola de procesamiento asíncrono para SUNAT)
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `jobs`;

CREATE TABLE
  `jobs` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `queue` varchar(255) NOT NULL,
    `payload` longtext NOT NULL,
    `attempts` tinyint unsigned NOT NULL,
    `reserved_at` int unsigned DEFAULT NULL,
    `available_at` int unsigned NOT NULL,
    `created_at` int unsigned NOT NULL,
    PRIMARY KEY (`id`),
    KEY (`queue`)
  );

-- ------------------------------------------------------------------------------
-- 7. TABLA: job_batches
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `job_batches`;

CREATE TABLE
  `job_batches` (
    `id` varchar(255) NOT NULL,
    `name` varchar(255) NOT NULL,
    `total_jobs` int NOT NULL,
    `pending_jobs` int NOT NULL,
    `failed_jobs` int NOT NULL,
    `failed_job_ids` longtext NOT NULL,
    `options` mediumtext,
    `cancelled_at` int DEFAULT NULL,
    `created_at` int NOT NULL,
    `finished_at` int DEFAULT NULL,
    PRIMARY KEY (`id`)
  );

-- ------------------------------------------------------------------------------
-- 8. TABLA: failed_jobs
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `failed_jobs`;

CREATE TABLE
  `failed_jobs` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `uuid` varchar(255) NOT NULL,
    `connection` text NOT NULL,
    `queue` text NOT NULL,
    `payload` longtext NOT NULL,
    `exception` longtext NOT NULL,
    `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY (`uuid`)
  );

-- ------------------------------------------------------------------------------
-- 9. TABLA: personal_access_tokens (Laravel Sanctum API Tokens)
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `personal_access_tokens`;

CREATE TABLE
  `personal_access_tokens` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `tokenable_type` varchar(255) NOT NULL,
    `tokenable_id` bigint unsigned NOT NULL,
    `name` varchar(255) NOT NULL,
    `token` varchar(64) NOT NULL,
    `abilities` text,
    `last_used_at` timestamp NULL DEFAULT NULL,
    `expires_at` timestamp NULL DEFAULT NULL,
    `created_at` timestamp NULL DEFAULT NULL,
    `updated_at` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY (`token`),
    KEY (`tokenable_type`, `tokenable_id`)
  );

-- ------------------------------------------------------------------------------
-- 10. TABLA: companies (Empresas emisoras / Tenants)
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `companies`;

CREATE TABLE
  `companies` (
    `id` char(36) NOT NULL,
    `user_id` bigint unsigned NOT NULL,
    `ruc` varchar(11) NOT NULL,
    `business_name` varchar(255) NOT NULL,
    `trademark_name` varchar(255) DEFAULT NULL,
    `address` varchar(255) DEFAULT NULL,
    `ubigeo` varchar(6) DEFAULT NULL,
    `department` varchar(100) DEFAULT NULL,
    `province` varchar(100) DEFAULT NULL,
    `district` varchar(100) DEFAULT NULL,
    `establishment_code` varchar(4) NOT NULL DEFAULT '0000',
    `sol_user` varchar(50) NOT NULL,
    `sol_pass` text NOT NULL,
    `client_id` varchar(100) DEFAULT NULL,
    `client_secret` text,
    `certificate_path` varchar(500) NOT NULL,
    `certificate_pass` text NOT NULL,
    `webhook_url` varchar(500) DEFAULT NULL,
    `webhook_secret` varchar(100) DEFAULT NULL,
    `is_production` tinyint (1) NOT NULL DEFAULT '0',
    `is_active` tinyint (1) NOT NULL DEFAULT '1',
    `email_notifications_active` tinyint (1) NOT NULL DEFAULT '0',
    `company_copy_emails` json DEFAULT NULL,
    `send_to_client_email` tinyint (1) NOT NULL DEFAULT '0',
    `email_template_settings` json DEFAULT NULL,
    `mail_host` varchar(100) DEFAULT 'smtp.gmail.com',
    `mail_port` smallint unsigned DEFAULT '587',
    `mail_username` varchar(255) DEFAULT NULL,
    `mail_password` text,
    `mail_encryption` varchar(10) DEFAULT 'tls',
    `mail_from_address` varchar(255) DEFAULT NULL,
    `mail_from_name` varchar(255) DEFAULT NULL,
    `created_at` timestamp NULL DEFAULT NULL,
    `updated_at` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY (`ruc`),
    KEY (`user_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
  );

-- ------------------------------------------------------------------------------
-- 11. TABLA: documents (Comprobantes de Pago: Facturas, Boletas, NC, ND)
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `documents`;

CREATE TABLE
  `documents` (
    `id` char(36) NOT NULL,
    `company_id` char(36) NOT NULL,
    `external_id` varchar(100) DEFAULT NULL,
    `type_code` char(2) NOT NULL DEFAULT '01',
    `operation_type` varchar(4) NOT NULL DEFAULT '0101',
    `series` char(4) NOT NULL,
    `establishment_code` varchar(4) NOT NULL DEFAULT '0000',
    `correlative` int unsigned NOT NULL,
    `issue_date` date NOT NULL,
    `issue_time` time NOT NULL,
    `due_date` date DEFAULT NULL,
    `currency` char(3) NOT NULL DEFAULT 'PEN',
    `payment_method` varchar(30) NOT NULL DEFAULT 'contado',
    `installments` json DEFAULT NULL,
    `detraction` json DEFAULT NULL,
    `retention` json DEFAULT NULL,
    `prepayments` json DEFAULT NULL,
    `related_documents` json DEFAULT NULL,
    `purchase_order` varchar(50) DEFAULT NULL,
    `plate_number` varchar(20) DEFAULT NULL,
    `note_data` json DEFAULT NULL,
    `extra_fields` json DEFAULT NULL,
    `client_doc_type` char(1) NOT NULL,
    `client_doc_number` varchar(15) NOT NULL,
    `client_name` varchar(255) NOT NULL,
    `client_address` varchar(255) DEFAULT NULL,
    `client_email` varchar(255) DEFAULT NULL,
    `total_taxable` decimal(12, 2) NOT NULL DEFAULT '0.00',
    `total_unaffected` decimal(12, 2) NOT NULL DEFAULT '0.00',
    `total_exonerated` decimal(12, 2) NOT NULL DEFAULT '0.00',
    `total_free` decimal(12, 2) NOT NULL DEFAULT '0.00',
    `total_exportation` decimal(12, 2) NOT NULL DEFAULT '0.00',
    `total_igv` decimal(12, 2) NOT NULL DEFAULT '0.00',
    `total_icbper` decimal(12, 2) NOT NULL DEFAULT '0.00',
    `total_discount` decimal(12, 2) NOT NULL DEFAULT '0.00',
    `total` decimal(12, 2) NOT NULL DEFAULT '0.00',
    `status` varchar(20) NOT NULL DEFAULT 'pending',
    `sunat_code` varchar(10) DEFAULT NULL,
    `sunat_description` text,
    `sunat_notes` json DEFAULT NULL,
    `hash` varchar(255) DEFAULT NULL,
    `xml_path` varchar(500) DEFAULT NULL,
    `cdr_path` varchar(500) DEFAULT NULL,
    `pdf_path` varchar(500) DEFAULT NULL,
    `retry_count` int unsigned NOT NULL DEFAULT '0',
    `void_ticket` varchar(100) DEFAULT NULL,
    `void_reason` varchar(255) DEFAULT NULL,
    `void_xml_path` varchar(500) DEFAULT NULL,
    `void_cdr_path` varchar(500) DEFAULT NULL,
    `void_sunat_code` varchar(10) DEFAULT NULL,
    `void_sunat_description` text,
    `voided_at` timestamp NULL DEFAULT NULL,
    `next_retry_at` timestamp NULL DEFAULT NULL,
    `created_at` timestamp NULL DEFAULT NULL,
    `updated_at` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY (
      `company_id`,
      `type_code`,
      `series`,
      `correlative`
    ),
    KEY (`external_id`),
    KEY (`status`),
    KEY (`next_retry_at`),
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
  );

-- ------------------------------------------------------------------------------
-- 12. TABLA: document_items (Ítems de Facturas / Boletas / Notas)
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `document_items`;

CREATE TABLE
  `document_items` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `document_id` char(36) NOT NULL,
    `internal_code` varchar(50) DEFAULT NULL,
    `description` varchar(500) NOT NULL,
    `unit_code` char(3) NOT NULL DEFAULT 'NIU',
    `quantity` decimal(12, 4) NOT NULL,
    `unit_value` decimal(12, 4) NOT NULL,
    `unit_price` decimal(12, 4) NOT NULL,
    `igv_type` char(2) NOT NULL DEFAULT '10',
    `igv_amount` decimal(12, 2) NOT NULL DEFAULT '0.00',
    `total` decimal(12, 2) NOT NULL DEFAULT '0.00',
    `attributes` json DEFAULT NULL,
    `created_at` timestamp NULL DEFAULT NULL,
    `updated_at` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY (`document_id`),
    FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE
  );

-- ------------------------------------------------------------------------------
-- 13. TABLA: despatches (Guías de Remisión Electrónicas - GRE Remitente 2022)
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `despatches`;

CREATE TABLE
  `despatches` (
    `id` char(36) NOT NULL,
    `company_id` char(36) NOT NULL,
    `external_id` varchar(100) DEFAULT NULL,
    `type_code` char(2) NOT NULL DEFAULT '09',
    `series` char(4) NOT NULL,
    `establishment_code` varchar(4) NOT NULL DEFAULT '0000',
    `correlative` int unsigned NOT NULL,
    `issue_date` date NOT NULL,
    `issue_time` time NOT NULL,
    `transfer_date` date NOT NULL,
    `delivery_date` date DEFAULT NULL,
    `transport_mode` char(2) NOT NULL,
    `transfer_reason` char(2) NOT NULL,
    `transfer_description` varchar(255) DEFAULT NULL,
    `total_weight` decimal(12, 3) NOT NULL,
    `weight_unit` char(3) NOT NULL DEFAULT 'KGM',
    `packages_count` int unsigned NOT NULL DEFAULT '1',
    `recipient_doc_type` char(1) NOT NULL,
    `recipient_doc_number` varchar(15) NOT NULL,
    `recipient_name` varchar(255) NOT NULL,
    `recipient_address` varchar(255) DEFAULT NULL,
    `recipient_email` varchar(255) DEFAULT NULL,
    `origin_ubigeo` varchar(6) NOT NULL,
    `origin_address` varchar(255) NOT NULL,
    `destination_ubigeo` varchar(6) NOT NULL,
    `destination_address` varchar(255) NOT NULL,
    `carrier_doc_type` char(1) DEFAULT NULL,
    `carrier_doc_number` varchar(15) DEFAULT NULL,
    `carrier_name` varchar(255) DEFAULT NULL,
    `carrier_mtc` varchar(50) DEFAULT NULL,
    `driver_doc_type` char(1) DEFAULT NULL,
    `driver_doc_number` varchar(15) DEFAULT NULL,
    `driver_name` varchar(255) DEFAULT NULL,
    `driver_license` varchar(50) DEFAULT NULL,
    `vehicle_plate` varchar(20) DEFAULT NULL,
    `secondary_vehicle_plate` varchar(20) DEFAULT NULL,
    `related_documents` json DEFAULT NULL,
    `status` varchar(20) NOT NULL DEFAULT 'pending',
    `ticket` varchar(100) DEFAULT NULL,
    `sunat_ticket` varchar(100) DEFAULT NULL,
    `sunat_code` varchar(10) DEFAULT NULL,
    `sunat_description` text,
    `sunat_notes` json DEFAULT NULL,
    `hash` varchar(255) DEFAULT NULL,
    `xml_path` varchar(500) DEFAULT NULL,
    `cdr_path` varchar(500) DEFAULT NULL,
    `pdf_path` varchar(500) DEFAULT NULL,
    `retry_count` int unsigned NOT NULL DEFAULT '0',
    `next_retry_at` timestamp NULL DEFAULT NULL,
    `void_ticket` varchar(100) DEFAULT NULL,
    `void_reason` varchar(255) DEFAULT NULL,
    `void_xml_path` varchar(500) DEFAULT NULL,
    `void_cdr_path` varchar(500) DEFAULT NULL,
    `void_sunat_code` varchar(10) DEFAULT NULL,
    `void_sunat_description` text,
    `voided_at` timestamp NULL DEFAULT NULL,
    `created_at` timestamp NULL DEFAULT NULL,
    `updated_at` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY (
      `company_id`,
      `type_code`,
      `series`,
      `correlative`
    ),
    KEY (`external_id`),
    KEY (`status`),
    KEY (`next_retry_at`),
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
  );

-- ------------------------------------------------------------------------------
-- 14. TABLA: despatch_items (Ítems de Guías de Remisión)
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `despatch_items`;

CREATE TABLE
  `despatch_items` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `despatch_id` char(36) NOT NULL,
    `internal_code` varchar(50) DEFAULT NULL,
    `description` varchar(500) NOT NULL,
    `unit_code` char(3) NOT NULL DEFAULT 'NIU',
    `quantity` decimal(12, 4) NOT NULL,
    `created_at` timestamp NULL DEFAULT NULL,
    `updated_at` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY (`despatch_id`),
    FOREIGN KEY (`despatch_id`) REFERENCES `despatches` (`id`) ON DELETE CASCADE
  );

-- ------------------------------------------------------------------------------
-- 15. TABLA: webhook_deliveries (Notificaciones hacia sistemas clientes)
-- ------------------------------------------------------------------------------
DROP TABLE IF EXISTS `webhook_deliveries`;

CREATE TABLE
  `webhook_deliveries` (
    `id` char(36) NOT NULL,
    `company_id` char(36) NOT NULL,
    `document_id` char(36) DEFAULT NULL,
    `despatch_id` char(36) DEFAULT NULL,
    `event` varchar(50) NOT NULL,
    `payload` json NOT NULL,
    `response_code` smallint DEFAULT NULL,
    `response_body` text,
    `attempts` tinyint unsigned NOT NULL DEFAULT '0',
    `status` varchar(20) NOT NULL DEFAULT 'pending',
    `created_at` timestamp NULL DEFAULT NULL,
    `updated_at` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY (`company_id`),
    KEY (`document_id`),
    KEY (`despatch_id`),
    KEY (`status`),
    FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
    FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE SET NULL,
    FOREIGN KEY (`despatch_id`) REFERENCES `despatches` (`id`) ON DELETE SET NULL
  );

SET FOREIGN_KEY_CHECKS = 1;

-- ==============================================================================
-- INSERTS SEMILLA (DATOS INICIALES PARA TESTING CON SUNAT BETA)
-- ==============================================================================
-- 1. Insertar Usuario Administrador Principal (email: admin@factos.pe, password: password) 
INSERT INTO
  `users` (
    `id`,
    `name`,
    `email`,
    `email_verified_at`,
    `password`,
    `created_at`,
    `updated_at`
  )
VALUES
  (
    1,
    'Administrador Factos',
    'admin@factos.pe',
    NOW(),
    '$2y$12$bn2gooC6ml1NNOZBfQETr.Xbu4dkviXIPNfpXc0t8mFg9lB2bJ42e',
    NOW(),
    NOW()
  ) ON DUPLICATE KEY
UPDATE `name` = VALUES(`name`);

-- 2. Insertar Empresa de Prueba Oficial SUNAT (BETA)
INSERT INTO
  `companies` (
    `id`,
    `user_id`,
    `ruc`,
    `business_name`,
    `trademark_name`,
    `address`,
    `ubigeo`,
    `department`,
    `province`,
    `district`,
    `establishment_code`,
    `sol_user`,
    `sol_pass`,
    `client_id`,
    `client_secret`,
    `certificate_path`,
    `certificate_pass`,
    `webhook_url`,
    `webhook_secret`,
    `is_production`,
    `is_active`,
    `email_notifications_active`,
    `company_copy_emails`,
    `send_to_client_email`,
    `email_template_settings`,
    `mail_host`,
    `mail_port`,
    `mail_username`,
    `mail_password`,
    `mail_encryption`,
    `mail_from_address`,
    `mail_from_name`,
    `created_at`,
    `updated_at`
  )
VALUES
  (
    'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
    1,
    '20000000001',
    'EMPRESA DE PRUEBA SUNAT S.A.C.',
    'FACTOS BETA TEST',
    'AV. LOS TESTERS 123 - URB. INDUSTRIAL',
    '150101',
    'LIMA',
    'LIMA',
    'LIMA',
    '0000',
    'MODDATOS',
    'eyJpdiI6ImZ3YURoQ0xhT0l0bXE4YjNaelJVMXc9PSIsInZhbHVlIjoiYitQMmJiY1YrTE5BVldnSWJMcGVoQT09IiwibWFjIjoiNDA4ZmM1N2Y2ZjNmZDY1MzFkNjFlMTdkMWVmNGMzNjg5Mjc5OWM4YzQzYWJlZDY5NWViODhmYmRlODY2Njc3OSIsInRhZyI6IiJ9',
    'test-85e5b0ae-255c-4891-a595-0b98c65c9854',
    'eyJpdiI6IkNaTGowOXdIMUdsWXN3cTdoYTJ5UUE9PSIsInZhbHVlIjoiRElCZHAvenhXVnpKeTVvY2MzbTNqRjgwNmhRREI5U2MwQmh1cHJCSGtrTT0iLCJtYWMiOiIwYzQyZmI3YmE4N2E2OGRjNzhmYmY5NDNhNzQzODJmYjg0ODE1ZmIxZTExNjVmZjM0M2ZlNjllZDRiNzkxNzJkIiwidGFnIjoiIn0=',
    'cert.pem',
    'eyJpdiI6InNydXJmSG5tRExBdEl3SE1NdytHbnc9PSIsInZhbHVlIjoiYWlSU3p6Zi9yTitZaEJjZkp0Q3FKQT09IiwibWFjIjoiNDcwZGQ4YmMwZDMzMjE4NzEwN2MyZjFlYmM2ZjIxZDEzOThmYmRmMTkzZTRiOTdlNGNkOTBiYzczNmIwZjMzZCIsInRhZyI6IiJ9',
    'https://webhook.site/demo-factos-receipt',
    'secret_webhook_factos_test_key_123',
    0,
    1,
    1,
    '["contabilidad@empresa-prueba.pe", "gerencia@empresa-prueba.pe"]',
    1,
    '{"color": "#1E40AF", "footer_text": "Gracias por su preferencia - Comprobante electrónico emitido con Factos API"}',
    'smtp.gmail.com',
    587,
    'facturacion.empresa.prueba@gmail.com',
    'eyJpdiI6IjB2N3NNZFlFUFE0QXF4Vm1RWFJtd3c9PSIsInZhbHVlIjoiQUxtZzgvM1ZoOG5GMEVmS2Z4STRQaE5CZ0MxK2xqcjM0RElKL3JPUmpVTT0iLCJtYWMiOiJmYzJjMjQwYjExMjE0ZTU4YWU1NjUzZWNhOTlmMTRjZmE4YjA0ZGQxOTdjYmRhZTI4ZjE0ZDc5YWI2NDA5MjYzIiwidGFnIjoiIn0=',
    'tls',
    'facturacion.empresa.prueba@gmail.com',
    'Facturación - Empresa de Prueba S.A.C.',
    NOW(),
    NOW()
  ) ON DUPLICATE KEY
UPDATE `business_name` = VALUES(`business_name`);