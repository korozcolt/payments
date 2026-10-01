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
        'payments.payouts.wompi' => [
            'sandbox' => true,
            'api_key' => 'test_payouts_api_key',
            'user_principal_id' => 'test_user_principal_id',
            'account_id' => 'account-123',
            'base_url' => [
                'sandbox' => 'https://api.sandbox.payouts.wompi.co/v1',
                'production' => 'https://api.payouts.wompi.co/v1',
            ],
        ],
    ]);
});

function makeWompiBeneficiary(): PayoutBeneficiary
{
    return Payments::payoutDriver('wompi')->registerBeneficiary(new PayoutBeneficiaryData(
        name: 'Proveedor Uno SAS',
        legalIdType: 'NIT',
        legalId: '900123456',
        personType: 'JURIDICA',
        bankCode: 'BANCOLOMBIA',
        accountType: 'AHORROS',
        accountNumber: '1234567890',
        category: 'providers',
        email: 'proveedor@example.com',
    ))->beneficiary;
}

// registerBeneficiary()

it('registers a wompi payout beneficiary locally without calling the api', function () {
    $result = Payments::payoutDriver('wompi')->registerBeneficiary(new PayoutBeneficiaryData(
        name: 'Proveedor Uno SAS',
        legalIdType: 'NIT',
        legalId: '900123456',
        personType: 'JURIDICA',
        bankCode: 'BANCOLOMBIA',
        accountType: 'AHORROS',
        accountNumber: '1234567890',
    ));

    expect($result->success)->toBeTrue();
    expect($result->beneficiary->provider->value)->toBe('wompi');
    expect($result->beneficiary->provider_beneficiary_id)->toBeNull();
});

// createPayout()

it('fails to create a wompi payout without a configured funding account_id', function () {
    config(['payments.payouts.wompi.account_id' => null]);

    $beneficiary = makeWompiBeneficiary();

    $result = Payments::payoutDriver('wompi')->createPayout(new PayoutData(
        beneficiary: $beneficiary,
        referenceId: 'PAYOUT-1',
        amount: 100000,
    ));

    expect($result->success)->toBeFalse();
    expect($result->errorCode)->toBe('MISSING_ACCOUNT_ID');
});

it('creates a wompi payout successfully', function () {
    Http::fake([
        '*/payouts' => Http::response([
            'status' => 201,
            'code' => 'OK',
            'message' => 'Solicitud ejecutada correctamente.',
            'data' => ['payoutId' => 'payout-uuid-1', 'transactions' => 1, 'success' => 1, 'failed' => 0],
        ], 201),
    ]);

    $beneficiary = makeWompiBeneficiary();

    $result = Payments::payoutDriver('wompi')->createPayout(new PayoutData(
        beneficiary: $beneficiary,
        referenceId: 'PAYOUT-2',
        amount: 100000,
        description: 'Pago factura #123',
    ));

    expect($result->success)->toBeTrue();
    expect($result->payout->provider_payout_id)->toBe('payout-uuid-1');
    expect($result->payout->status)->toBe(PayoutStatus::Processing);
    expect($result->payout->beneficiary->id)->toBe($beneficiary->id);
});

it('fails to create a wompi payout when the api call fails', function () {
    Http::fake([
        '*/payouts' => Http::response(['message' => 'Insufficient funds'], 422),
    ]);

    $beneficiary = makeWompiBeneficiary();

    $result = Payments::payoutDriver('wompi')->createPayout(new PayoutData(
        beneficiary: $beneficiary,
        referenceId: 'PAYOUT-3',
        amount: 100000,
    ));

    expect($result->success)->toBeFalse();
    expect($result->errorCode)->toBe('API_ERROR');
    expect($result->payout->status)->toBe(PayoutStatus::Failed);
});

// queryPayoutStatus()

it('queries a wompi payout status and maps it to completed', function () {
    Http::fake([
        '*/payouts' => Http::response(['data' => ['payoutId' => 'payout-uuid-4']], 201),
        '*/payouts/payout-uuid-4' => Http::response(['data' => ['status' => 'APPROVED']], 200),
    ]);

    $beneficiary = makeWompiBeneficiary();
    $created = Payments::payoutDriver('wompi')->createPayout(new PayoutData(
        beneficiary: $beneficiary,
        referenceId: 'PAYOUT-4',
        amount: 100000,
    ));

    $result = Payments::payoutDriver('wompi')->queryPayoutStatus($created->payout->fresh());

    expect($result->success)->toBeTrue();
    expect($result->payout->status)->toBe(PayoutStatus::Completed);
    expect($result->payout->processed_at)->not->toBeNull();
});

it('fails to query wompi payout status without a provider payout id', function () {
    $beneficiary = makeWompiBeneficiary();
    $payout = Payout::create([
        'payout_beneficiary_id' => $beneficiary->id,
        'reference_id' => 'PAYOUT-5',
        'provider' => 'wompi',
        'amount' => 100000,
        'status' => PayoutStatus::Pending,
    ]);

    $result = Payments::payoutDriver('wompi')->queryPayoutStatus($payout);

    expect($result->success)->toBeFalse();
    expect($result->errorCode)->toBe('NO_PROVIDER_ID');
});
