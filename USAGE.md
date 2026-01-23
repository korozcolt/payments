# Guía de Uso - Korbytes Payments

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
