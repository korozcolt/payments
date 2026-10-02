-- korozcolt/payments-core schema (sqlite).
-- Same tables/columns as the Laravel package migrations.

CREATE TABLE payment_transactions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ulid VARCHAR(26) NOT NULL UNIQUE,
  payable_type VARCHAR(255) NULL,
  payable_id BIGINT NULL,
  subscription_id BIGINT NULL,
  reference_id VARCHAR(255) NOT NULL,
  provider VARCHAR(255) NOT NULL,
  provider_transaction_id VARCHAR(255) NULL,
  provider_refund_id VARCHAR(255) NULL,
  provider_reference VARCHAR(255) NULL,
  amount INTEGER NOT NULL,
  refunded_amount INTEGER NULL,
  currency VARCHAR(3) NOT NULL DEFAULT 'COP',
  status VARCHAR(255) NOT NULL DEFAULT 'pending',
  idempotency_key VARCHAR(255) NOT NULL UNIQUE,
  webhook_received_at DATETIME NULL,
  webhook_attempts INTEGER NOT NULL DEFAULT 0,
  provider_request TEXT NULL,
  provider_response TEXT NULL,
  webhook_payload TEXT NULL,
  error_code VARCHAR(255) NULL,
  error_message TEXT NULL,
  metadata TEXT NULL,
  initiated_at DATETIME NULL,
  completed_at DATETIME NULL,
  refunded_at DATETIME NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL
);

CREATE TABLE subscription_plans (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ulid VARCHAR(26) NOT NULL UNIQUE,
  provider VARCHAR(255) NOT NULL,
  provider_plan_id VARCHAR(255) NULL,
  name VARCHAR(255) NOT NULL,
  amount INTEGER NOT NULL,
  currency VARCHAR(3) NOT NULL DEFAULT 'COP',
  interval VARCHAR(255) NOT NULL DEFAULT 'month',
  interval_count INTEGER NOT NULL DEFAULT 1,
  trial_days INTEGER NULL,
  metadata TEXT NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL
);

CREATE TABLE subscriptions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ulid VARCHAR(26) NOT NULL UNIQUE,
  subscription_plan_id BIGINT NOT NULL REFERENCES subscription_plans (id),
  reference_id VARCHAR(255) NOT NULL,
  provider VARCHAR(255) NOT NULL,
  provider_subscription_id VARCHAR(255) NULL,
  provider_payment_source_id VARCHAR(255) NULL,
  customer_email VARCHAR(255) NULL,
  customer_name VARCHAR(255) NULL,
  customer_phone VARCHAR(255) NULL,
  status VARCHAR(255) NOT NULL DEFAULT 'active',
  trial_ends_at DATETIME NULL,
  next_billing_date DATETIME NULL,
  started_at DATETIME NULL,
  cancelled_at DATETIME NULL,
  last_charged_at DATETIME NULL,
  failed_charge_attempts INTEGER NOT NULL DEFAULT 0,
  provider_response TEXT NULL,
  metadata TEXT NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL
);

CREATE TABLE payout_beneficiaries (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ulid VARCHAR(26) NOT NULL UNIQUE,
  provider VARCHAR(255) NOT NULL,
  provider_beneficiary_id VARCHAR(255) NULL,
  name VARCHAR(255) NOT NULL,
  legal_id_type VARCHAR(10) NOT NULL,
  legal_id VARCHAR(255) NOT NULL,
  person_type VARCHAR(10) NOT NULL,
  bank_code VARCHAR(255) NOT NULL,
  account_type VARCHAR(30) NOT NULL,
  account_number VARCHAR(255) NOT NULL,
  category VARCHAR(255) NOT NULL DEFAULT 'providers',
  email VARCHAR(255) NULL,
  phone VARCHAR(255) NULL,
  metadata TEXT NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL
);

CREATE TABLE payouts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ulid VARCHAR(26) NOT NULL UNIQUE,
  payout_beneficiary_id BIGINT NOT NULL REFERENCES payout_beneficiaries (id),
  reference_id VARCHAR(255) NOT NULL,
  provider VARCHAR(255) NOT NULL,
  provider_payout_id VARCHAR(255) NULL,
  amount INTEGER NOT NULL,
  currency VARCHAR(3) NOT NULL DEFAULT 'COP',
  status VARCHAR(255) NOT NULL DEFAULT 'pending',
  description TEXT NULL,
  provider_response TEXT NULL,
  metadata TEXT NULL,
  processed_at DATETIME NULL,
  created_at DATETIME NULL,
  updated_at DATETIME NULL
);

CREATE INDEX payment_transactions_reference_id_index ON payment_transactions (reference_id);
CREATE INDEX payment_transactions_provider_transaction_id_index ON payment_transactions (provider_transaction_id);
CREATE INDEX payment_transactions_status_index ON payment_transactions (status);
CREATE INDEX subscriptions_reference_id_index ON subscriptions (reference_id);
CREATE INDEX subscriptions_provider_subscription_id_index ON subscriptions (provider_subscription_id);
CREATE INDEX subscriptions_next_billing_date_index ON subscriptions (next_billing_date);
CREATE INDEX payouts_reference_id_index ON payouts (reference_id);
CREATE INDEX payouts_provider_payout_id_index ON payouts (provider_payout_id);
