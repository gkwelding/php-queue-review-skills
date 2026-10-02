<?php

namespace Tests\Eval;

use App\Jobs\ChargeOrder;
use App\Models\Order;
use App\Services\GatewayUnavailable;
use App\Services\OrderPlacement;
use App\Services\PaymentDeclined;
use App\Services\PaymentGateway;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

// Hidden scoring suite for the write task: copied into the app only after the job is written.
final class ChargeOrderTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakePaymentGateway();
        $this->app->instance(PaymentGateway::class, $this->gateway);
    }

    #[Test]
    public function it_charges_the_order_and_marks_it_paid(): void
    {
        $order = $this->order();

        $this->handle(new ChargeOrder($order->id));

        $this->assertSame([['ann@example.com', 4200]], array_values($this->gateway->charges));
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(reset($this->gateway->ids), $order->fresh()->charge_id);
    }

    #[Test]
    public function running_the_job_twice_charges_once(): void
    {
        $order = $this->order();

        $this->handle(new ChargeOrder($order->id));
        $this->handle(new ChargeOrder($order->id));

        $this->assertCount(1, $this->gateway->charges);
    }

    #[Test]
    public function a_retry_after_a_lost_gateway_response_does_not_charge_again(): void
    {
        $order = $this->order();
        $this->gateway->loseNextResponse = true; // the charge goes through, then the call times out

        try {
            $this->handle(new ChargeOrder($order->id));
        } catch (Throwable) {
            // the retry policy decides what happens next
        }
        $this->handle(new ChargeOrder($order->id));

        $this->assertCount(1, $this->gateway->charges);
        $this->assertSame('paid', $order->fresh()->status);
    }

    #[Test]
    public function a_deleted_order_is_not_charged_and_the_job_does_not_fail(): void
    {
        $order = $this->order();
        $order->delete();

        $job = $this->handle(new ChargeOrder($order->id));

        $this->assertSame([], $this->gateway->calls);
        $job->assertNotFailed();
    }

    #[Test]
    public function a_declined_card_marks_the_order_failed_and_is_not_retried(): void
    {
        $order = $this->order();
        $this->gateway->throw = new PaymentDeclined('Card declined');
        $job = (new ChargeOrder($order->id))->withFakeQueueInteractions();

        try {
            app()->call([$job, 'handle']);
            $thrown = false;
        } catch (PaymentDeclined) {
            $thrown = true;
        }

        $this->assertSame('payment_failed', $order->fresh()->status);
        $this->assertFalse($job->job->isReleased(), 'A declined card must not be released for a retry.');
        $this->assertTrue(! $thrown || $job->job->hasFailed(), 'Rethrowing PaymentDeclined without failing the job retries it.');
    }

    #[Test]
    public function a_gateway_outage_is_left_to_retry(): void
    {
        $order = $this->order();
        $this->gateway->throw = new GatewayUnavailable('Timed out');
        $job = (new ChargeOrder($order->id))->withFakeQueueInteractions();

        try {
            app()->call([$job, 'handle']);
            $thrown = false;
        } catch (GatewayUnavailable) {
            $thrown = true;
        }

        $this->assertTrue($thrown || $job->job->isReleased(), 'An outage must be rethrown or released so it is retried.');
        $this->assertFalse($job->job->hasFailed(), 'An outage must not fail the job.');
        $this->assertSame('pending', $order->fresh()->status);
    }

    #[Test]
    public function outages_are_retried_with_backoff_for_about_an_hour(): void
    {
        $job = new ChargeOrder(1);

        $backoff = method_exists($job, 'backoff') ? $job->backoff() : ($job->backoff ?? null);
        $this->assertNotEmpty(array_filter((array) $backoff), 'Expected a non-zero backoff.');

        $until = method_exists($job, 'retryUntil') ? $job->retryUntil() : ($job->retryUntil ?? null);
        if ($until === null) {
            $this->assertGreaterThan(1, $job->tries ?? 1, 'Expected retryUntil() or more than one try.');

            return;
        }
        $seconds = ($until instanceof DateTimeInterface ? $until->getTimestamp() : (int) $until) - time();
        $this->assertGreaterThanOrEqual(30 * 60, $seconds);
        $this->assertLessThanOrEqual(2 * 3600, $seconds);
    }

    #[Test]
    public function placing_an_order_dispatches_the_charge_after_commit(): void
    {
        Queue::fake();

        $order = app(OrderPlacement::class)->place('ann@example.com', 4200);

        Queue::assertPushed(ChargeOrder::class, function (ChargeOrder $job) use ($order) {
            $connection = $job->connection ?? config('queue.default');

            return $job->orderId === $order->id
                && ($job instanceof ShouldQueueAfterCommit
                    || $job->afterCommit === true
                    || ($job->afterCommit === null && config("queue.connections.{$connection}.after_commit") === true));
        });
    }

    private function order(): Order
    {
        return Order::create(['customer_email' => 'ann@example.com', 'amount_cents' => 4200]);
    }

    private function handle(ChargeOrder $job): ChargeOrder
    {
        $job->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        return $job;
    }
}

// Honours idempotency keys as the PaymentGateway contract says: a repeated key returns the original charge.
final class FakePaymentGateway implements PaymentGateway
{
    /** @var array<string, array{string, int}> charges actually taken, by idempotency key */
    public array $charges = [];

    /** @var array<string, string> charge id by idempotency key */
    public array $ids = [];

    /** @var list<string> idempotency key of every call */
    public array $calls = [];

    public ?Throwable $throw = null;

    public bool $loseNextResponse = false;

    public function charge(string $customerEmail, int $amountCents, string $idempotencyKey): string
    {
        $this->calls[] = $idempotencyKey;

        if ($this->throw !== null) {
            throw $this->throw;
        }

        $this->charges[$idempotencyKey] ??= [$customerEmail, $amountCents];
        $this->ids[$idempotencyKey] ??= 'ch_'.count($this->ids);

        if ($this->loseNextResponse) {
            $this->loseNextResponse = false;

            throw new GatewayUnavailable('Timed out waiting for the response');
        }

        return $this->ids[$idempotencyKey];
    }
}
