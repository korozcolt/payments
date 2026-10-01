<?php

declare(strict_types=1);

namespace Korbytes\Payments\Enums;

/**
 * Payment provider enum.
 */
enum PaymentProvider: string
{
    case Wompi = 'wompi';
    case MercadoPago = 'mercadopago';
    case Epayco = 'epayco';

    public function getLabel(): string
    {
        return match ($this) {
            self::Wompi => 'Wompi',
            self::MercadoPago => 'MercadoPago',
            self::Epayco => 'ePayco',
        };
    }
}
