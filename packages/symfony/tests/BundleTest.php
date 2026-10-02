<?php

declare(strict_types=1);

use Korbytes\Payments\Core\Events\PaymentApproved;
use Korbytes\Payments\Core\Standalone;
use Korbytes\Payments\DTOs\PaymentData;
use Korbytes\Payments\Symfony\Tests\App\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Tester\CommandTester;

function bootKernel(array $payments = []): array
{
    $db = sys_get_temp_dir().'/payments-symfony-'.bin2hex(random_bytes(4)).'.sqlite';
    if (file_exists($db)) {
        unlink($db);
    }

    $kernel = new Kernel($db, $payments);
    $kernel->boot();

    $application = new Application($kernel);
    $application->setAutoExit(false);

    $tester = new CommandTester($application->find('payments:schema'));
    $tester->execute([]);

    return [$kernel, $db, $application];
}

function signedWebhook(array $tx, bool $valid = true): string
{
    return json_encode([
        'data' => ['transaction' => $tx],
        'signature' => [
            'properties' => ['id', 'status', 'amount_in_cents'],
            'timestamp' => '1',
            'checksum' => $valid ? hash('sha256', $tx['id'].$tx['status'].$tx['amount_in_cents'].'1test_events_xxx') : 'bad',
        ],
    ]);
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/payments-symfony-*.sqlite') ?: [] as $file) {
        if (file_exists($file)) {
            unlink($file);
        }
    }
});

it('registers the payments service from bundle configuration', function () {
    [$kernel] = bootKernel();

    $payments = $kernel->getContainer()->get('payments');

    expect($payments)->toBeInstanceOf(Standalone::class)
        ->and($payments->driver('wompi')->isConfigured())->toBeTrue()
        ->and($payments->manager()->isAvailable('epayco'))->toBeFalse();
});

it('creates the schema and can dump it as SQL', function () {
    [$kernel, $db, $application] = bootKernel();

    $tables = (new PDO('sqlite:'.$db))->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    expect($tables)->toContain('payment_transactions', 'subscriptions', 'payouts');

    $dump = new CommandTester($application->find('payments:schema'));
    $dump->execute(['--dump' => true]);
    expect($dump->getDisplay())->toContain('CREATE TABLE payment_transactions');
});

it('answers webhooks over the HTTP kernel: 400, 401 and 200 with events', function () {
    [$kernel, $db] = bootKernel();
    $client = new KernelBrowser($kernel);
    $client->disableReboot(); // keep one container so the listener below survives between requests
    $payments = $kernel->getContainer()->get('payments');

    $approved = [];
    // The bundle hands Symfony's event_dispatcher to the core, so subscribe the Symfony way.
    $kernel->getContainer()->get('event_dispatcher')->addListener(PaymentApproved::class, function ($e) use (&$approved) {
        $approved[] = $e->transaction->reference_id;
    });

    $charge = $payments->driver('wompi')->charge(new PaymentData(referenceId: 'SYMFONY-1', amount: 120000));
    $tx = ['id' => 'w-sf', 'status' => 'APPROVED', 'amount_in_cents' => 120000, 'reference' => $charge->reference];

    $client->request('POST', '/payments/webhooks/nope', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
    expect($client->getResponse()->getStatusCode())->toBe(400);

    $client->request('POST', '/payments/webhooks/wompi', server: ['CONTENT_TYPE' => 'application/json'], content: signedWebhook($tx, false));
    expect($client->getResponse()->getStatusCode())->toBe(401);

    $client->request('POST', '/payments/webhooks/wompi', server: ['CONTENT_TYPE' => 'application/json'], content: signedWebhook($tx));
    expect($client->getResponse()->getStatusCode())->toBe(200)
        ->and(json_decode($client->getResponse()->getContent(), true))->toBe(['success' => true, 'transaction_id' => 1, 'status' => 'approved'])
        ->and($approved)->toBe(['SYMFONY-1'])
        ->and((new PDO('sqlite:'.$db))->query('SELECT status FROM payment_transactions')->fetchColumn())->toBe('approved');

    $client->request('GET', '/payments/webhooks/wompi');
    expect($client->getResponse()->getStatusCode())->toBe(405);
});

it('runs the subscription command', function () {
    [, , $application] = bootKernel();

    $tester = new CommandTester($application->find('payments:process-subscriptions'));
    $tester->execute([]);

    expect($tester->getStatusCode())->toBe(0)
        ->and($tester->getDisplay())->toContain('No due subscriptions found.');
});

it('rejects a configuration without any database', function () {
    $kernel = new Kernel('/tmp/unused.sqlite', ['dsn' => null]);
    $kernel->boot();
})->throws(LogicException::class, 'payments.pdo');
