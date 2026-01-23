# Guía de Instalación - Korbytes Payments

Esta guía te ayudará a instalar y configurar el package de pagos en tu proyecto Laravel.

## Requisitos

- PHP 8.2 o superior
- Laravel 10, 11 o 12
- Extensiones PHP: `json`, `openssl`, `mbstring`

## Métodos de Instalación

### Opción 1: Desde Packagist (Recomendado para producción)

```bash
composer require korbytes/payments
```

### Opción 2: Desde repositorio Git

Agrega el repositorio a tu `composer.json`:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/korbytes/payments.git"
        }
    ],
    "require": {
        "korbytes/payments": "^1.0"
    }
}
```

Luego ejecuta:

```bash
composer update korbytes/payments
```

### Opción 3: Desarrollo local (Path Repository)

Para desarrollo local, coloca el package en una carpeta y referéncialo:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "./packages/korbytes/payments",
            "options": {
                "symlink": true
            }
        }
    ],
    "require": {
        "korbytes/payments": "@dev"
    }
}
```

## Configuración Post-Instalación

### 1. Publicar la Configuración

```bash
php artisan vendor:publish --tag=payments-config
```

Esto creará el archivo `config/payments.php`.

### 2. Publicar las Migraciones

```bash
php artisan vendor:publish --tag=payments-migrations
```

### 3. Ejecutar las Migraciones

```bash
php artisan migrate
```

### 4. Configurar Variables de Entorno

Agrega las siguientes variables a tu archivo `.env`:

```env
# ============================================
# CONFIGURACIÓN GENERAL DE PAGOS
# ============================================

# Driver por defecto (wompi, mercadopago, epayco)
PAYMENTS_DEFAULT=wompi

# Drivers habilitados (separados por coma)
PAYMENTS_ENABLED=wompi,mercadopago,epayco

# Usar base de datos para credenciales (true) o archivo de config (false)
PAYMENTS_USE_DATABASE=true

# URLs por defecto
PAYMENTS_RETURN_URL=https://tuapp.com/pagos/completado
PAYMENTS_WEBHOOK_URL=https://tuapp.com/payments/webhooks

# Logging
PAYMENTS_LOGGING_ENABLED=true
PAYMENTS_LOG_CHANNEL=stack

# ============================================
# WOMPI (Colombia)
# ============================================
WOMPI_SANDBOX=true
WOMPI_PUBLIC_KEY=pub_test_xxxxxxxxxx
WOMPI_PRIVATE_KEY=prv_test_xxxxxxxxxx
WOMPI_INTEGRITY_KEY=test_integrity_xxxxxxxxxx
WOMPI_EVENTS_SECRET=test_events_xxxxxxxxxx

# ============================================
# MERCADOPAGO (Latinoamérica)
# ============================================
MERCADOPAGO_SANDBOX=true
MERCADOPAGO_ACCESS_TOKEN=TEST-xxxxxxxxxx
MERCADOPAGO_PUBLIC_KEY=TEST-xxxxxxxxxx
MERCADOPAGO_WEBHOOK_SECRET=xxxxxxxxxx

# ============================================
# EPAYCO (Colombia)
# ============================================
EPAYCO_SANDBOX=true
EPAYCO_PUBLIC_KEY=xxxxxxxxxx
EPAYCO_PRIVATE_KEY=xxxxxxxxxx
EPAYCO_P_CUST_ID=xxxxxxxxxx
EPAYCO_P_KEY=xxxxxxxxxx
```

## Configuración de Webhooks

### URLs de Webhook por Proveedor

Configura estas URLs en el panel de cada proveedor:

| Proveedor | URL de Webhook |
|-----------|----------------|
| Wompi | `https://tuapp.com/payments/webhooks/wompi` |
| MercadoPago | `https://tuapp.com/payments/webhooks/mercadopago` |
| ePayco | `https://tuapp.com/payments/webhooks/epayco` |

### Verificar Rutas Registradas

```bash
php artisan route:list --name=payments
```

Deberías ver:
```
POST payments/webhooks/{provider} payments.webhooks.handle
```

## Configuración con Base de Datos

Si prefieres almacenar las credenciales en la base de datos (recomendado para multi-tenant):

### Crear Gateway de Pago

```php
use Korbytes\Payments\Models\PaymentGateway;

PaymentGateway::create([
    'provider' => 'wompi',
    'display_name' => 'Wompi - Tarjetas y PSE',
    'is_active' => true,
    'is_sandbox' => true,
    'credentials' => [
        'public_key' => 'pub_test_xxx',
        'private_key' => 'prv_test_xxx',
        'integrity_secret' => 'test_integrity_xxx',
        'events_secret' => 'test_events_xxx',
    ],
    'logo_url' => '/images/wompi-logo.png',
    'description' => 'Paga con tarjeta de crédito, débito o PSE',
    'sort_order' => 1,
]);
```

### Seeder de Ejemplo

```php
// database/seeders/PaymentGatewaySeeder.php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Korbytes\Payments\Models\PaymentGateway;

class PaymentGatewaySeeder extends Seeder
{
    public function run(): void
    {
        PaymentGateway::create([
            'provider' => 'wompi',
            'display_name' => 'Wompi',
            'is_active' => true,
            'is_sandbox' => config('app.env') !== 'production',
            'credentials' => [
                'public_key' => env('WOMPI_PUBLIC_KEY'),
                'private_key' => env('WOMPI_PRIVATE_KEY'),
                'integrity_secret' => env('WOMPI_INTEGRITY_KEY'),
                'events_secret' => env('WOMPI_EVENTS_SECRET'),
            ],
            'sort_order' => 1,
        ]);

        PaymentGateway::create([
            'provider' => 'mercadopago',
            'display_name' => 'MercadoPago',
            'is_active' => true,
            'is_sandbox' => config('app.env') !== 'production',
            'credentials' => [
                'access_token' => env('MERCADOPAGO_ACCESS_TOKEN'),
                'public_key' => env('MERCADOPAGO_PUBLIC_KEY'),
                'webhook_secret' => env('MERCADOPAGO_WEBHOOK_SECRET'),
            ],
            'sort_order' => 2,
        ]);

        PaymentGateway::create([
            'provider' => 'epayco',
            'display_name' => 'ePayco',
            'is_active' => true,
            'is_sandbox' => config('app.env') !== 'production',
            'credentials' => [
                'public_key' => env('EPAYCO_PUBLIC_KEY'),
                'private_key' => env('EPAYCO_PRIVATE_KEY'),
                'p_cust_id_cliente' => env('EPAYCO_P_CUST_ID'),
                'p_key' => env('EPAYCO_P_KEY'),
            ],
            'sort_order' => 3,
        ]);
    }
}
```

## Verificar Instalación

Ejecuta en tinker para verificar:

```bash
php artisan tinker
```

```php
use Korbytes\Payments\Facades\Payments;

// Verificar que el facade funciona
Payments::hasAvailableDriver(); // true si hay al menos un driver configurado

// Verificar driver específico
Payments::isAvailable('wompi'); // true si Wompi está configurado

// Ver drivers habilitados
Payments::enabledDrivers()->keys(); // ['wompi', 'mercadopago', 'epayco']
```

## Solución de Problemas

### Error: "Class Payments not found"

Ejecuta:
```bash
composer dump-autoload
php artisan config:clear
php artisan cache:clear
```

### Error: "Driver not enabled"

Verifica que el driver esté en `PAYMENTS_ENABLED`:
```env
PAYMENTS_ENABLED=wompi,mercadopago,epayco
```

### Error: "Provider not configured"

Verifica las credenciales en `.env` o en la tabla `payment_gateways`.

### Webhooks no funcionan

1. Verifica que la URL sea accesible públicamente
2. Verifica que el middleware no bloquee las peticiones
3. Revisa los logs: `tail -f storage/logs/laravel.log`

## Siguiente Paso

Consulta [USAGE.md](USAGE.md) para aprender a usar el package en tu aplicación.
