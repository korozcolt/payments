<?php

use Korbytes\Payments\Enums\PaymentStatus;

it('has correct status values', function () {
    expect(PaymentStatus::Pending->value)->toBe('pending');
    expect(PaymentStatus::Processing->value)->toBe('processing');
    expect(PaymentStatus::Approved->value)->toBe('approved');
    expect(PaymentStatus::Rejected->value)->toBe('rejected');
    expect(PaymentStatus::Expired->value)->toBe('expired');
    expect(PaymentStatus::Refunded->value)->toBe('refunded');
    expect(PaymentStatus::Voided->value)->toBe('voided');
});

it('identifies successful status', function () {
    expect(PaymentStatus::Approved->isSuccessful())->toBeTrue();
    expect(PaymentStatus::Pending->isSuccessful())->toBeFalse();
    expect(PaymentStatus::Rejected->isSuccessful())->toBeFalse();
});

it('identifies final statuses', function () {
    expect(PaymentStatus::Approved->isFinal())->toBeTrue();
    expect(PaymentStatus::Rejected->isFinal())->toBeTrue();
    expect(PaymentStatus::Expired->isFinal())->toBeTrue();
    expect(PaymentStatus::Refunded->isFinal())->toBeTrue();
    expect(PaymentStatus::Voided->isFinal())->toBeTrue();

    expect(PaymentStatus::Pending->isFinal())->toBeFalse();
    expect(PaymentStatus::Processing->isFinal())->toBeFalse();
});

it('has labels for all statuses', function () {
    foreach (PaymentStatus::cases() as $status) {
        expect($status->getLabel())->toBeString()->not->toBeEmpty();
    }
});
