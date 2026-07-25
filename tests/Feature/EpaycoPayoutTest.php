<?php

use Illuminate\Support\Facades\Http;
use Korbytes\Payments\DTOs\PayoutBeneficiaryData;
use Korbytes\Payments\DTOs\PayoutData;
use Korbytes\Payments\Enums\PayoutStatus;
use Korbytes\Payments\Facades\Payments;
use Korbytes\Payments\Models\Payout;
use Korbytes\Payments\Models\PayoutBeneficiary;

beforeEach(function () {
    $this->artisan('migrate', ['--database' => 'testing']);

    config([
        'payments.payouts.epayco' => [
            'public_key' => 'test_payouts_public_key',
            'private_key' => 'test_payouts_private_key',
            'id_epayco' => '99',
            'base_url' => 'https://apiflow.epayco.io/payouts/api/v2',
        ],
    ]);

    Http::fake([
        '*/login' => Http::response(['token' => 'fake-bearer-token'], 200),
    ]);
});

function makeEpaycoBeneficiary(string $category = 'providers'): PayoutBeneficiary
{
    Http::fake([
        '*/login' => Http::response(['token' => 'fake-bearer-token'], 200),
        '*/providers' => Http::response(['data' => ['id' => 'epayco-provider-1']], 201),
        '*/employees' => Http::response(['data' => ['id' => 'epayco-employee-1']], 201),
    ]);

    return Payments::payoutDriver('epayco')->registerBeneficiary(new PayoutBeneficiaryData(
        name: 'Proveedor Dos SAS',
        legalIdType: 'NIT',
        legalId: '900654321',
        personType: 'JURIDICA',
        bankCode: 'BANCOLOMBIA',
        accountType: 'AHORROS',
        accountNumber: '9876543210',
        category: $category,
        email: 'proveedor2@example.com',
    ))->beneficiary;
}

// registerBeneficiary()

it('registers an epayco provider beneficiary via the api', function () {
    $beneficiary = makeEpaycoBeneficiary('providers');

    expect($beneficiary->provider->value)->toBe('epayco');
    expect($beneficiary->provider_beneficiary_id)->toBe('epayco-provider-1');
});

it('registers an epayco payroll beneficiary via the employees endpoint', function () {
    $beneficiary = makeEpaycoBeneficiary('payroll');

    expect($beneficiary->provider_beneficiary_id)->toBe('epayco-employee-1');
});

it('fails to register an epayco beneficiary when the api call fails', function () {
    Http::fake([
        '*/login' => Http::response(['token' => 'fake-bearer-token'], 200),
        '*/providers' => Http::response(['message' => 'invalid bank code'], 422),
    ]);

    $result = Payments::payoutDriver('epayco')->registerBeneficiary(new PayoutBeneficiaryData(
        name: 'Proveedor Malo',
        legalIdType: 'NIT',
        legalId: '900000000',
        personType: 'JURIDICA',
        bankCode: 'INVALID',
        accountType: 'AHORROS',
        accountNumber: '000',
    ));

    expect($result->success)->toBeFalse();
    expect($result->errorCode)->toBe('API_ERROR');
});

it('fails to register an epayco beneficiary when the login call returns no token', function () {
    Http::fake([
        '*/login' => Http::response(['error' => 'invalid credentials'], 401),
    ]);

    $result = Payments::payoutDriver('epayco')->registerBeneficiary(new PayoutBeneficiaryData(
        name: 'Proveedor Sin Token',
        legalIdType: 'NIT',
        legalId: '900000001',
        personType: 'JURIDICA',
        bankCode: 'BANCOLOMBIA',
        accountType: 'AHORROS',
        accountNumber: '111',
    ));

    expect($result->success)->toBeFalse();
    expect($result->errorCode)->toBe('API_ERROR');
});

// createPayout()

it('fails to create an epayco payout when the beneficiary has no provider id', function () {
    $beneficiary = PayoutBeneficiary::create([
        'provider' => 'epayco',
        'name' => 'Sin Registrar',
        'legal_id_type' => 'NIT',
        'legal_id' => '900000002',
        'person_type' => 'JURIDICA',
        'bank_code' => 'BANCOLOMBIA',
        'account_type' => 'AHORROS',
        'account_number' => '222',
    ]);

    $result = Payments::payoutDriver('epayco')->createPayout(new PayoutData(
        beneficiary: $beneficiary,
        referenceId: 'PAYOUT-1',
        amount: 100000,
    ));

    expect($result->success)->toBeFalse();
    expect($result->errorCode)->toBe('MISSING_PROVIDER_BENEFICIARY');
});

it('creates an epayco payout successfully through the bulk and dispersal steps', function () {
    $beneficiary = makeEpaycoBeneficiary('providers');

    Http::fake([
        '*/login' => Http::response(['token' => 'fake-bearer-token'], 200),
        '*/payments/bulk' => Http::response(['data' => [['id_payment' => 8815]]], 201),
        '*/payments/generatePayment' => Http::response(['success' => true], 200),
    ]);

    $result = Payments::payoutDriver('epayco')->createPayout(new PayoutData(
        beneficiary: $beneficiary,
        referenceId: 'PAYOUT-2',
        amount: 100000,
        description: 'Pago factura #456',
    ));

    expect($result->success)->toBeTrue();
    expect($result->payout->provider_payout_id)->toBe('8815');
    expect($result->payout->status)->toBe(PayoutStatus::Processing);
});

it('fails to create an epayco payout when the bulk call fails', function () {
    $beneficiary = makeEpaycoBeneficiary('providers');

    Http::fake([
        '*/login' => Http::response(['token' => 'fake-bearer-token'], 200),
        '*/payments/bulk' => Http::response(['message' => 'insufficient pocket balance'], 422),
    ]);

    $result = Payments::payoutDriver('epayco')->createPayout(new PayoutData(
        beneficiary: $beneficiary,
        referenceId: 'PAYOUT-3',
        amount: 100000,
    ));

    expect($result->success)->toBeFalse();
    expect($result->errorCode)->toBe('API_ERROR');
    expect($result->payout->status)->toBe(PayoutStatus::Failed);
});

it('fails to create an epayco payout when the dispersal call fails', function () {
    $beneficiary = makeEpaycoBeneficiary('providers');

    Http::fake([
        '*/login' => Http::response(['token' => 'fake-bearer-token'], 200),
        '*/payments/bulk' => Http::response(['data' => [['id_payment' => 8816]]], 201),
        '*/payments/generatePayment' => Http::response(['message' => 'dispersal window closed'], 422),
    ]);

    $result = Payments::payoutDriver('epayco')->createPayout(new PayoutData(
        beneficiary: $beneficiary,
        referenceId: 'PAYOUT-4',
        amount: 100000,
    ));

    expect($result->success)->toBeFalse();
    expect($result->errorCode)->toBe('API_ERROR');
    expect($result->payout->status)->toBe(PayoutStatus::Failed);
    expect($result->payout->provider_payout_id)->toBe('8816');
});

// queryPayoutStatus()

it('queries an epayco payout status and maps it to completed', function () {
    $payout = Payout::create([
        'payout_beneficiary_id' => makeEpaycoBeneficiary('providers')->id,
        'reference_id' => 'PAYOUT-5',
        'provider' => 'epayco',
        'provider_payout_id' => '8817',
        'amount' => 100000,
        'status' => PayoutStatus::Processing,
    ]);

    Http::fake([
        '*/login' => Http::response(['token' => 'fake-bearer-token'], 200),
        '*/payments/findone' => Http::response(['data' => ['status' => 'ACEPTADO']], 200),
    ]);

    $result = Payments::payoutDriver('epayco')->queryPayoutStatus($payout);

    expect($result->success)->toBeTrue();
    expect($result->payout->status)->toBe(PayoutStatus::Completed);
    expect($result->payout->processed_at)->not->toBeNull();
});

it('fails to query epayco payout status without a provider payout id', function () {
    $beneficiary = makeEpaycoBeneficiary('providers');
    $payout = Payout::create([
        'payout_beneficiary_id' => $beneficiary->id,
        'reference_id' => 'PAYOUT-6',
        'provider' => 'epayco',
        'amount' => 100000,
        'status' => PayoutStatus::Pending,
    ]);

    $result = Payments::payoutDriver('epayco')->queryPayoutStatus($payout);

    expect($result->success)->toBeFalse();
    expect($result->errorCode)->toBe('NO_PROVIDER_ID');
});
