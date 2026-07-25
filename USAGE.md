# Guía de Uso - Korozcolt Payments

## Conceptos Básicos

### PaymentData DTO

El DTO `PaymentData` es la forma de pasar información de pago al package sin acoplarse a tu dominio:

```php
use Korbytes\Payments\DTOs\PaymentData;

$paymentData = new PaymentData(
    referenceId: 'ORDER-12345',           // ID único de tu orden/compra
    amount: 150000,                        // Monto en centavos (1500.00 COP)
    currency: 'COP',                       // Código ISO 4217
    customer: [
        'name' => 'Juan Pérez',
        'email' => 'juan@ejemplo.com',
        'phone' => '+573001234567',
    ],
    returnUrl: 'https://tuapp.com/pagos/completado',
    webhookUrl: 'https://tuapp.com/payments/webhooks/wompi',
    description: 'Compra de productos',
    metadata: [                            // Datos adicionales para tu app
        'order_id' => 123,
        'user_id' => 456,
        'items_count' => 3,
    ],
    items: [                               // Opcional: líneas de detalle
        [
            'id' => 'PROD-001',
            'title' => 'Producto A',
            'description' => 'Descripción del producto',
            'quantity' => 2,
            'unit_price' => 50000,         // En centavos
        ],
        [
            'id' => 'PROD-002',
            'title' => 'Producto B',
            'quantity' => 1,
            'unit_price' => 50000,
        ],
    ],
);
```

### Crear desde Array

```php
$paymentData = PaymentData::fromArray([
    'reference_id' => 'ORDER-12345',
    'amount' => 150000,
    'currency' => 'COP',
    'customer' => [
        'name' => 'Juan Pérez',
        'email' => 'juan@ejemplo.com',
    ],
    'return_url' => 'https://tuapp.com/pagos/completado',
    'description' => 'Compra de productos',
]);
```

## Crear un Pago

### Usando el Driver por Defecto

```php
use Korbytes\Payments\Facades\Payments;
use Korbytes\Payments\DTOs\PaymentData;

$paymentData = new PaymentData(
    referenceId: $order->ulid,
    amount: $order->total,  // En centavos
    currency: 'COP',
    customer: [
        'name' => $order->customer_name,
        'email' => $order->customer_email,
        'phone' => $order->customer_phone,
    ],
    returnUrl: route('payments.callback', ['order' => $order->ulid]),
    description: "Orden #{$order->order_number}",
    metadata: [
        'order_id' => $order->id,
    ],
);

$result = Payments::charge($paymentData);
```

### Usando un Driver Específico

```php
// Wompi
$result = Payments::driver('wompi')->charge($paymentData);

// MercadoPago
$result = Payments::driver('mercadopago')->charge($paymentData);

// ePayco
$result = Payments::driver('epayco')->charge($paymentData);
```

## Manejar el Resultado

```php
use Korbytes\Payments\DTOs\PaymentResult;

$result = Payments::charge($paymentData);

if ($result->success) {
    // Guardar la transacción si necesitas relacionarla
    $order->update([
        'payment_transaction_id' => $result->transaction->id,
    ]);

    // Retornar datos para el frontend
    return response()->json([
        'success' => true,
        'widget_url' => $result->widgetUrl,
        'public_key' => $result->publicKey,
        'reference' => $result->reference,
        'signature' => $result->signature,
        'amount' => $result->amountInCents,
        'currency' => $result->currency,
        'redirect_url' => $result->redirectUrl,
        'extra' => $result->extra,
    ]);
} else {
    return response()->json([
        'success' => false,
        'error' => $result->errorMessage,
        'error_code' => $result->errorCode,
    ], 400);
}
```

## Integración Frontend

### Wompi Widget

```html
<!-- Cargar el widget -->
<script src="{{ $widgetUrl }}"></script>

<button id="pay-button">Pagar ${{ number_format($amount / 100, 0, ',', '.') }}</button>

<script>
document.getElementById('pay-button').addEventListener('click', function() {
    var checkout = new WidgetCheckout({
        currency: '{{ $currency }}',
        amountInCents: {{ $amount }},
        reference: '{{ $reference }}',
        publicKey: '{{ $publicKey }}',
        signature: {
            integrity: '{{ $signature }}'
        },
        redirectUrl: '{{ $redirectUrl }}',
        customerData: {
            email: '{{ $extra["customer_email"] }}',
            fullName: '{{ $extra["customer_name"] }}',
            phoneNumber: '{{ $extra["customer_phone"] ?? "" }}'
        }
    });

    checkout.open(function(result) {
        var transaction = result.transaction;
        console.log('Transaction ID:', transaction.id);
        console.log('Status:', transaction.status);

        if (transaction.status === 'APPROVED') {
            window.location.href = '{{ $redirectUrl }}?status=approved';
        }
    });
});
</script>
```

### MercadoPago (Redirect)

Para MercadoPago, generalmente rediriges al `init_point`:

```php
// En tu controlador
$result = Payments::driver('mercadopago')->charge($paymentData);

if ($result->success) {
    // Redirigir al checkout de MercadoPago
    return redirect($result->extra['init_point']);
}
```

O usar el Checkout Bricks:

```html
<script src="{{ $widgetUrl }}"></script>
<div id="wallet_container"></div>

<script>
const mp = new MercadoPago('{{ $publicKey }}');
const bricksBuilder = mp.bricks();

mp.bricks().create("wallet", "wallet_container", {
    initialization: {
        preferenceId: "{{ $extra['preference_id'] }}",
    },
});
</script>
```

### ePayco Widget

```html
<script src="{{ $widgetUrl }}"></script>

<button id="pay-button">Pagar</button>

<script>
document.getElementById('pay-button').addEventListener('click', function() {
    var handler = ePayco.checkout.configure({
        key: '{{ $publicKey }}',
        test: {{ $extra['test'] ? 'true' : 'false' }}
    });

    handler.open({
        name: '{{ $extra["name"] }}',
        description: '{{ $extra["description"] }}',
        invoice: '{{ $extra["invoice"] }}',
        currency: '{{ $currency }}',
        amount: '{{ $amount / 100 }}',
        tax_base: '{{ $extra["tax_base"] }}',
        tax: '{{ $extra["tax"] }}',
        country: '{{ $extra["country"] }}',
        lang: '{{ $extra["lang"] }}',
        external: '{{ $extra["external"] }}',
        response: '{{ $extra["response"] }}',
        confirmation: '{{ $extra["confirmation"] }}',
        email_billing: '{{ $extra["customer_email"] }}',
        name_billing: '{{ $extra["customer_name"] }}'
    });
});
</script>
```

## Escuchar Eventos

El package emite eventos que puedes escuchar para ejecutar lógica de negocio.

### Registrar Listeners

```php
// app/Providers/EventServiceProvider.php
use Korbytes\Payments\Events\PaymentApproved;
use Korbytes\Payments\Events\PaymentRejected;
use Korbytes\Payments\Events\PaymentCreated;
use Korbytes\Payments\Events\WebhookReceived;

protected $listen = [
    PaymentApproved::class => [
        \App\Listeners\HandlePaymentApproved::class,
    ],
    PaymentRejected::class => [
        \App\Listeners\HandlePaymentRejected::class,
    ],
    PaymentCreated::class => [
        \App\Listeners\HandlePaymentCreated::class,
    ],
];
```

### Listener para Pago Aprobado

```php
// app/Listeners/HandlePaymentApproved.php
namespace App\Listeners;

use App\Models\Order;
use App\Services\TicketAssignmentService;
use Korbytes\Payments\Events\PaymentApproved;
use Illuminate\Contracts\Queue\ShouldQueue;

class HandlePaymentApproved implements ShouldQueue
{
    public function __construct(
        private TicketAssignmentService $ticketService,
    ) {}

    public function handle(PaymentApproved $event): void
    {
        $transaction = $event->transaction;

        // Buscar la orden usando el reference_id
        $order = Order::where('ulid', $transaction->reference_id)->first();

        if (!$order) {
            return;
        }

        // Actualizar estado de la orden
        $order->update([
            'status' => 'paid',
            'paid_at' => now(),
            'payment_transaction_id' => $transaction->id,
        ]);

        // Asignar tickets/productos
        $this->ticketService->assignForOrder($order);

        // Enviar email de confirmación
        $order->customer->notify(new PaymentConfirmedNotification($order));
    }
}
```

### Listener para Pago Rechazado

```php
// app/Listeners/HandlePaymentRejected.php
namespace App\Listeners;

use App\Models\Order;
use Korbytes\Payments\Events\PaymentRejected;

class HandlePaymentRejected
{
    public function handle(PaymentRejected $event): void
    {
        $transaction = $event->transaction;

        $order = Order::where('ulid', $transaction->reference_id)->first();

        if ($order) {
            $order->update([
                'status' => 'payment_failed',
                'payment_error' => $event->webhookResult->errorMessage,
            ]);

            // Notificar al usuario
            // Liberar inventario reservado
            // etc.
        }
    }
}
```

## Consultar Estado de Pago

```php
use Korbytes\Payments\Facades\Payments;

// Consultar directamente a la API del proveedor
$result = Payments::driver('wompi')->queryStatus($transactionId);

if ($result->success) {
    echo "Estado: " . $result->status->value; // approved, rejected, pending, etc.
    echo "ID Proveedor: " . $result->providerTransactionId;
}
```

## Reembolsos (Refunds)

**El soporte de reembolsos vía API varía por proveedor — no asumas que todos funcionan igual.**

| Proveedor    | Vía API                                                             | Manual                                    |
|--------------|----------------------------------------------------------------------|--------------------------------------------|
| MercadoPago  | Total y parcial, cualquier método de pago aprobado                  | —                                          |
| Wompi        | Solo `void` de tarjeta **antes** de liquidarse (todo o nada, sin parcial) | Cualquier transacción ya liquidada |
| ePayco       | **Ninguno implementado en este paquete** (ver nota abajo)            | Siempre — TC, PSE y efectivo             |

```php
use Korbytes\Payments\Facades\Payments;

$transaction = PaymentTransaction::find($id);

// Reembolso total
$result = Payments::driver($transaction->provider->value)->refund($transaction);

// Reembolso parcial (en centavos)
$result = Payments::driver($transaction->provider->value)->refund($transaction, amountInCents: 20000);

if ($result->success) {
    echo "Reembolsado: " . $result->refundedAmountInCents;
} elseif ($result->errorCode === 'REFUND_NOT_SUPPORTED') {
    // El proveedor no expone (o no soporta) reembolso automático para este caso.
    // $result->errorMessage explica por qué y qué hacer manualmente.
} else {
    // Falló la llamada a la API del proveedor (ver $result->errorMessage).
}
```

`refund()` **nunca lanza excepción** para el caso "no soportado" — devuelve `RefundResult::notSupported()` (o `::failed()`) para que puedas tratarlo como un flujo normal en tu aplicación, no como un error inesperado.

### MercadoPago

Soporte completo vía `PaymentRefundClient` del SDK oficial. Requiere que la transacción esté en estado `Approved` y tenga `provider_transaction_id` (el ID de pago real, no el de preferencia). Actualiza la transacción a `Refunded`, guarda `refunded_amount`/`refunded_at`/`provider_refund_id` y dispara `PaymentRefunded`.

### Wompi

Wompi **no expone una API de reembolso post-liquidación**. Lo único disponible es `POST /transactions/{id}/void`, que cancela una transacción de tarjeta **antes** de que se liquide (ciertos estados previos únicamente), y es todo-o-nada — no admite montos parciales.

- Si el `void` es aceptado: la transacción queda `Voided` (no `Refunded` — no se llegó a capturar dinero).
- Si Wompi lo rechaza (lo más común: la transacción ya se liquidó): `refund()` devuelve `errorCode = 'MANUAL_REFUND_REQUIRED'`. Debes gestionar la devolución desde el dashboard de Wompi.
- Si pides un monto parcial: siempre devuelve `REFUND_NOT_SUPPORTED` (Wompi no lo permite).

### ePayco

**No implementado en este paquete.** ePayco solo permite reversión automática vía API para pagos con **Tarjeta de Crédito (TC)**; PSE y efectivo son siempre manuales. Pero el endpoint técnico de reversión de ePayco está documentado detrás de un portal que requiere sesión autenticada (`api.epayco.co`), y no pudimos verificar su contrato (URL exacta, payload, respuesta) al momento de escribir este código.

`EpaycoDriver::refund()` siempre devuelve `RefundResult::notSupported()` — para **cualquier** método de pago, incluyendo TC — con un mensaje indicando que la reversión debe hacerse desde el dashboard de ePayco o su centro de soporte.

Si en el futuro se confirma el spec del endpoint de reversión de ePayco, se puede implementar soporte real para TC siguiendo el mismo patrón que `WompiDriver::refund()` o `MercadoPagoDriver::refund()`.

## Suscripciones (Recurring Payments)

**Solo Wompi y MercadoPago tienen soporte real de suscripciones en este paquete. ePayco no está implementado — ver más abajo por qué.**

| Proveedor    | Motor de cobro recurrente                                    | Nuestro scheduler lo cobra |
|--------------|---------------------------------------------------------------|------------------------------|
| MercadoPago  | Propio (`PreApproval`/`PreApprovalPlan`), cobra automático     | No — MercadoPago se auto-cobra |
| Wompi        | Ninguno — solo tokenización (`payment_sources`)               | Sí — vía `payments:process-subscriptions` |
| ePayco       | **No implementado** (ver nota abajo)                          | N/A |

Nunca se maneja tarjeta cruda en este paquete: `$paymentToken` siempre debe venir de una tokenización hecha en el frontend (widget de Wompi, formulario de tarjeta de MercadoPago).

### Crear un plan y suscribir un cliente

```php
use Korbytes\Payments\DTOs\PlanData;
use Korbytes\Payments\DTOs\SubscriptionData;
use Korbytes\Payments\Enums\BillingInterval;
use Korbytes\Payments\Facades\Payments;

$planResult = Payments::driver('mercadopago')->createPlan(new PlanData(
    name: 'Plan Pro Mensual',
    amount: 50000, // en centavos
    interval: BillingInterval::Month,
    trialDays: 7,
));

$subscriptionResult = Payments::driver('mercadopago')->createSubscription(new SubscriptionData(
    plan: $planResult->plan,
    referenceId: $order->ulid,
    paymentToken: $cardTokenFromFrontend,
    customer: ['name' => 'Juan Pérez', 'email' => 'juan@test.com'],
));

if ($subscriptionResult->success) {
    $subscription = $subscriptionResult->subscription;
} else {
    // $subscriptionResult->errorCode / errorMessage
}
```

### Cancelar

```php
Payments::driver($subscription->provider->value)->cancelSubscription($subscription);
```

### MercadoPago

Soporte completo vía su motor nativo de suscripciones (`PreApprovalPlanClient` + `PreApprovalClient`). MercadoPago cobra cada ciclo **automáticamente** — no llames `chargeSubscriptionCycle()` para MercadoPago, siempre devuelve `errorCode = 'NOT_APPLICABLE'`. El resultado de cada cobro recurrente llega vía webhook (`type = 'subscription_authorized_payment'`), que `processWebhook()` ya maneja: crea una `PaymentTransaction` ligada a la suscripción y dispara `SubscriptionChargeSucceeded`/`SubscriptionChargeFailed`.

⚠️ La forma exacta de este webhook (tipo, payload) no se verificó contra un sandbox real de MercadoPago en esta sesión — pruébalo de punta a punta antes de confiar en él en producción.

### Wompi

Wompi no tiene motor de suscripciones — solo tokenización real vía **"payment sources"** (`POST /v1/payment_sources`). `createSubscription()` requiere un `acceptance_token` en `providerOptions` (obtenido de `GET /merchants/{public_key}`, mostrado al cliente al aceptar términos):

```php
Payments::driver('wompi')->createSubscription(new SubscriptionData(
    plan: $plan,
    referenceId: $order->ulid,
    paymentToken: $cardTokenFromWompiWidget,
    customer: ['name' => 'Juan Pérez', 'email' => 'juan@test.com'],
    providerOptions: ['acceptance_token' => $acceptanceToken],
));
```

Como Wompi no tiene motor propio, **este paquete debe cobrar cada ciclo con su propio scheduler**. Trae un comando Artisan (`payments:process-subscriptions`) que no hace nada por sí solo — debes agregarlo al scheduler de tu proyecto:

```php
// routes/console.php (Laravel 11+)
use Illuminate\Support\Facades\Schedule;

Schedule::command('payments:process-subscriptions')->hourly();
```

Controla qué proveedores cobra este comando con `payments.subscriptions.scheduled_providers` (`config/payments.php`, default `['wompi']`). **No agregues `'mercadopago'` a esa lista** — MercadoPago ya se cobra solo, y hacerlo generaría cobro doble.

`cancelSubscription()` para Wompi solo marca la suscripción como cancelada localmente (detiene que el scheduler la cobre) — no existe un endpoint confirmado para eliminar el `payment_source` del lado de Wompi.

### ePayco

**No implementado en este paquete**, deliberadamente. ePayco sí tiene un producto de recurrencia completo (Plan + Customer + Subscription vía el SDK oficial [`epayco/epayco-php`](https://github.com/epayco/epayco-php)), pero no pudimos confirmar, agotando fuentes públicas (documentación, código fuente del SDK, e incluso una colección de Postman compartida), **si ePayco cobra automático cada ciclo o si el backend debe llamar `subscriptions->charge()` manualmente**. Implementar cualquiera de las dos suposiciones mal tiene riesgo real: desde ingresos no cobrados silenciosamente hasta cobro doble a un cliente.

`createPlan()`, `createSubscription()`, `cancelSubscription()` y `chargeSubscriptionCycle()` de `EpaycoDriver` siempre devuelven `notSupported()` con este mismo motivo. Para implementarlo de verdad, primero hay que confirmar ese comportamiento contra una cuenta/sandbox real de ePayco.

## Payouts (Pagos a Terceros)

**Solo Wompi y ePayco soportan payouts en este paquete. MercadoPago no tiene API de payouts — su driver ni siquiera implementa `PayoutDriverInterface`.**

Payouts es enviar dinero a un tercero (proveedor, empleado), lo opuesto a `charge()` (cobrarle a un cliente). Usa credenciales **completamente separadas** de las del gateway de pagos — no reutiliza `payments.drivers.*` — y en Wompi requiere activar un módulo aparte en el dashboard del comercio.

```php
use Korbytes\Payments\DTOs\PayoutBeneficiaryData;
use Korbytes\Payments\DTOs\PayoutData;
use Korbytes\Payments\Facades\Payments;

// 1. Registrar el beneficiario (proveedor/empleado)
$beneficiaryResult = Payments::payoutDriver('wompi')->registerBeneficiary(new PayoutBeneficiaryData(
    name: 'Proveedor SAS',
    legalIdType: 'NIT',
    legalId: '900123456',
    personType: 'JURIDICA',
    bankCode: 'BANCOLOMBIA', // ver GET /banks de cada proveedor
    accountType: 'AHORROS',
    accountNumber: '1234567890',
    category: 'providers', // 'providers' | 'payroll'
    email: 'proveedor@example.com',
));

// 2. Enviar el pago
$payoutResult = Payments::payoutDriver('wompi')->createPayout(new PayoutData(
    beneficiary: $beneficiaryResult->beneficiary,
    referenceId: 'FACTURA-123',
    amount: 100000, // en centavos
    description: 'Pago factura #123',
));

if ($payoutResult->success) {
    $payout = $payoutResult->payout;
}

// 3. Consultar estado
Payments::payoutDriver('wompi')->queryPayoutStatus($payoutResult->payout);
```

`Payments::payoutDriver($provider)` lanza `PaymentException` (`errorCode = 'PAYOUTS_NOT_SUPPORTED'`) si el proveedor no soporta payouts — por ejemplo, `Payments::payoutDriver('mercadopago')`.

### Configuración

```env
# Wompi Payouts — credenciales separadas del gateway, requiere módulo activado
WOMPI_PAYOUTS_API_KEY=
WOMPI_PAYOUTS_USER_PRINCIPAL_ID=
WOMPI_PAYOUTS_ACCOUNT_ID=       # cuenta de fondeo, ver GET /accounts

# ePayco Payouts — también credenciales separadas
EPAYCO_PAYOUTS_PUBLIC_KEY=
EPAYCO_PAYOUTS_PRIVATE_KEY=
EPAYCO_PAYOUTS_ID_EPAYCO=
```

### Wompi

Soporte real y confirmado directamente contra el spec OpenAPI público de Wompi ([SwaggerHub](https://app.swaggerhub.com/apis-docs/wompi/Payouts/1.0.0)): `POST /payouts` (crear lote), `GET /payouts/{id}` (consultar), `GET /banks`, `GET /accounts`. Autenticación vía headers `x-api-key` + `user-principal-id` (no Bearer).

- Wompi **no tiene un endpoint de registro de beneficiario** — los datos bancarios van inline en cada transacción del payout. `registerBeneficiary()` para Wompi solo crea un registro local reutilizable (no llama a la API).
- Requiere `payments.payouts.wompi.account_id` configurado (la cuenta de fondeo desde la que sale el dinero) — sin eso, `createPayout()` devuelve `errorCode = 'MISSING_ACCOUNT_ID'`.
- Requiere que el módulo "Pagos a Terceros" esté activado en tu cuenta comercial de Wompi — es un producto separado del gateway de pagos normal.

### ePayco

Basado en la documentación oficial confirmada (`flujo-de-pago-de-proveedores`, `flujo-de-pago-de-nómina`, `pagos-programados`, `ciclos-ach`): `POST /providers` o `POST /employees` (registrar beneficiario según `category`), `POST /payments/bulk` (crear pago), `POST /payments/generatePayment` (disparar dispersión), `POST /payments/findone` (consultar estado). Base: `apiflow.epayco.io/payouts/api/v2`.

⚠️ **Una pieza no se pudo verificar: el mecanismo exacto de autenticación de esta API específica.** Se implementó reutilizando el mismo patrón confirmado que usa el resto de la plataforma de ePayco (login con `public_key`/`private_key` → token Bearer, igual que el SDK oficial `epayco-php`), pero no se confirmó que `apiflow.epayco.io` use exactamente ese mismo endpoint de login. Prueba esto en sandbox antes de producción — si el login falla, `registerBeneficiary()`/`createPayout()`/`queryPayoutStatus()` devuelven `errorCode = 'API_ERROR'` (fallo seguro, no hay riesgo de pago fantasma).

Ten en cuenta además los **ciclos ACH**: transferencias a bancos distintos de Davivienda/Daviplata pasan por ventanas de proceso fijas (cortes a las 3pm significan que se reflejan hasta el siguiente día hábil) — no asumas que un payout se refleja de inmediato.

## Verificar Disponibilidad

```php
use Korbytes\Payments\Facades\Payments;

// ¿Hay algún driver disponible?
if (Payments::hasAvailableDriver()) {
    // Mostrar opciones de pago
}

// ¿Está disponible un driver específico?
if (Payments::isAvailable('wompi')) {
    // Mostrar opción de Wompi
}

// Obtener todos los gateways activos (para mostrar en checkout)
$gateways = Payments::activeGateways();

foreach ($gateways as $gateway) {
    echo $gateway->display_name;
    echo $gateway->logo_url;
    echo $gateway->description;
}
```

## Ejemplo Completo: Controlador de Pagos

```php
// app/Http/Controllers/PaymentController.php
namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use Korbytes\Payments\DTOs\PaymentData;
use Korbytes\Payments\Facades\Payments;

class PaymentController extends Controller
{
    public function showCheckout(Order $order)
    {
        // Verificar que la orden puede ser pagada
        if ($order->isPaid()) {
            return redirect()->route('orders.show', $order)
                ->with('info', 'Esta orden ya fue pagada.');
        }

        // Obtener gateways disponibles
        $gateways = Payments::activeGateways();

        return view('payments.checkout', [
            'order' => $order,
            'gateways' => $gateways,
        ]);
    }

    public function initiatePayment(Request $request, Order $order)
    {
        $request->validate([
            'provider' => 'required|in:wompi,mercadopago,epayco',
        ]);

        $paymentData = new PaymentData(
            referenceId: $order->ulid,
            amount: $order->total,
            currency: 'COP',
            customer: [
                'name' => $order->customer_name,
                'email' => $order->customer_email,
                'phone' => $order->customer_phone,
            ],
            returnUrl: route('payments.callback', [
                'provider' => $request->provider,
                'order' => $order->ulid,
            ]),
            webhookUrl: route('payments.webhooks.handle', [
                'provider' => $request->provider,
            ]),
            description: "Orden #{$order->order_number}",
            metadata: [
                'order_id' => $order->id,
                'order_ulid' => $order->ulid,
            ],
        );

        $result = Payments::driver($request->provider)->charge($paymentData);

        if (!$result->success) {
            return back()->withErrors([
                'payment' => $result->errorMessage,
            ]);
        }

        // Para MercadoPago, redirigir directamente
        if ($request->provider === 'mercadopago') {
            return redirect($result->extra['init_point']);
        }

        // Para otros, mostrar el widget
        return view('payments.widget', [
            'order' => $order,
            'result' => $result,
        ]);
    }

    public function handleCallback(Request $request, string $provider, Order $order)
    {
        // Verificar el estado actual de la orden
        $order->refresh();

        if ($order->isPaid()) {
            return redirect()->route('orders.confirmation', $order)
                ->with('success', '¡Pago completado exitosamente!');
        }

        // El pago puede estar pendiente de confirmación por webhook
        if ($request->get('status') === 'pending') {
            return redirect()->route('orders.show', $order)
                ->with('info', 'Tu pago está siendo procesado. Te notificaremos cuando se confirme.');
        }

        // Pago rechazado
        return redirect()->route('payments.checkout', $order)
            ->with('error', 'El pago fue rechazado. Por favor intenta con otro método de pago.');
    }
}
```

## Ejemplo Completo: Componente Livewire

```php
// app/Livewire/PaymentCheckout.php
namespace App\Livewire;

use App\Models\Order;
use Korbytes\Payments\DTOs\PaymentData;
use Korbytes\Payments\DTOs\PaymentResult;
use Korbytes\Payments\Facades\Payments;
use Livewire\Component;

class PaymentCheckout extends Component
{
    public Order $order;
    public ?string $selectedProvider = null;
    public ?PaymentResult $paymentResult = null;
    public bool $processing = false;

    public function mount(Order $order)
    {
        $this->order = $order;

        if ($order->isPaid()) {
            return redirect()->route('orders.confirmation', $order);
        }
    }

    public function selectProvider(string $provider)
    {
        $this->selectedProvider = $provider;
        $this->paymentResult = null;
    }

    public function initiatePayment()
    {
        if (!$this->selectedProvider) {
            return;
        }

        $this->processing = true;

        $paymentData = new PaymentData(
            referenceId: $this->order->ulid,
            amount: $this->order->total,
            currency: 'COP',
            customer: [
                'name' => $this->order->customer_name,
                'email' => $this->order->customer_email,
                'phone' => $this->order->customer_phone,
            ],
            returnUrl: route('payments.callback', [
                'provider' => $this->selectedProvider,
                'order' => $this->order->ulid,
            ]),
            description: "Orden #{$this->order->order_number}",
        );

        $this->paymentResult = Payments::driver($this->selectedProvider)
            ->charge($paymentData);

        $this->processing = false;

        if (!$this->paymentResult->success) {
            session()->flash('error', $this->paymentResult->errorMessage);
            return;
        }

        // Para MercadoPago, redirigir
        if ($this->selectedProvider === 'mercadopago') {
            return redirect($this->paymentResult->extra['init_point']);
        }
    }

    public function render()
    {
        return view('livewire.payment-checkout', [
            'gateways' => Payments::activeGateways(),
        ]);
    }
}
```

## Extender con Drivers Personalizados

```php
// app/Payments/Drivers/PayUDriver.php
namespace App\Payments\Drivers;

use Korbytes\Payments\Contracts\PaymentDriverInterface;
use Korbytes\Payments\Drivers\AbstractDriver;
use Korbytes\Payments\DTOs\PaymentData;
use Korbytes\Payments\DTOs\PaymentResult;
use Korbytes\Payments\DTOs\WebhookResult;

class PayUDriver extends AbstractDriver
{
    public function getName(): string
    {
        return 'payu';
    }

    public function getWidgetUrl(): string
    {
        return 'https://gateway.payulatam.com/ppp-web-gateway/';
    }

    public function getPublicKey(): ?string
    {
        return $this->getConfig('api_key');
    }

    public function getBaseUrl(): string
    {
        return $this->isSandbox()
            ? 'https://sandbox.api.payulatam.com'
            : 'https://api.payulatam.com';
    }

    public function charge(PaymentData $paymentData): PaymentResult
    {
        // Implementar lógica de PayU
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        // Implementar verificación
    }

    public function processWebhook(Request $request): WebhookResult
    {
        // Implementar procesamiento
    }

    public function queryStatus(string $transactionId): WebhookResult
    {
        // Implementar consulta
    }
}
```

Registrar el driver:

```php
// app/Providers/AppServiceProvider.php
use App\Payments\Drivers\PayUDriver;
use Korbytes\Payments\Facades\Payments;

public function boot(): void
{
    Payments::extend('payu', PayUDriver::class);
}
```

Usar el driver:

```php
$result = Payments::driver('payu')->charge($paymentData);
```
