<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\User;

/** Stack-only allowance. A stored reservation cannot reconstruct its random secret. */
final class OperationPermit
{
    private int $remaining;

    private int $started;

    private bool $finished = false;

    private bool $sent = false;

    public function __construct(public readonly User $owner, public readonly Channel $channel,
        public readonly string $reference, public readonly int $generation, int $slots,
        #[\SensitiveParameter] private readonly string $secret, private readonly ?\Closure $committed = null)
    {
        $this->remaining = $slots;
        $this->started = hrtime(true);
    }

    public function hash(): string
    {
        return hash('sha256', $this->secret);
    }

    public function hasSent(): bool
    {
        return $this->sent;
    }

    public function assertCurrent(): void
    {
        app(Operations::class)->assertCurrent($this);
    }

    public function assertCommitted(): void
    {
        if ($this->committed === null || ! ($this->committed)()) {
            throw new OperationBlocked('uncommitted_allowance');
        }
    }

    /** @template T
     * @param callable():T $send
     * @return T */
    public function call(callable $send): mixed
    {
        return app(Operations::class)->send($this, function () use ($send) {
            $this->sent = true;

            return $send();
        });
    }

    public function consume(): void
    {
        if ($this->finished || $this->remaining < 1) {
            throw new OperationBlocked('allowance_exhausted');
        }
        $this->remaining--;
    }

    public function finish(string $outcome): void
    {
        $this->assertCommitted();
        if (! $this->finished) {
            app(Operations::class)->finish($this, $outcome, intdiv(hrtime(true) - $this->started, 1000000));
            $this->finished = true;
        }
    }

    /** @return array<never,never> */
    public function __serialize(): array
    {
        throw new \LogicException('Operational handoff permits cannot be retained.');
    }

    public function __clone(): void
    {
        throw new \LogicException('Operational handoff permits cannot be copied.');
    }
}
