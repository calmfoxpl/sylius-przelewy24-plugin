<?php

declare(strict_types=1);

namespace Tests\Calmfox\SyliusPrzelewy24Plugin\Support;

use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Payment\PaymentRequestTransitions;
use Sylius\Component\Payment\PaymentTransitions;

/** The two graphs the gateway moves along, reduced to the transitions it uses. */
final class StateMachine implements StateMachineInterface
{
    private const GRAPHS = [
        PaymentTransitions::GRAPH => [
            'complete' => [['new', 'processing', 'authorized'], 'completed'],
            'process' => [['new'], 'processing'],
            'fail' => [['new', 'processing'], 'failed'],
        ],
        PaymentRequestTransitions::GRAPH => [
            'process' => [['new'], 'processing'],
            'complete' => [['new', 'processing'], 'completed'],
            'fail' => [['new', 'processing'], 'failed'],
        ],
    ];

    /** @var list<string> */
    public array $applied = [];

    public function can(object $subject, string $graphName, string $transition): bool
    {
        $rule = self::GRAPHS[$graphName][$transition] ?? null;

        return null !== $rule && \in_array($subject->getState(), $rule[0], true);
    }

    public function apply(object $subject, string $graphName, string $transition, array $context = []): void
    {
        if (!$this->can($subject, $graphName, $transition)) {
            throw new \LogicException(sprintf('Cannot %s from %s in %s.', $transition, $subject->getState(), $graphName));
        }
        $subject->setState(self::GRAPHS[$graphName][$transition][1]);
        $this->applied[] = $graphName . ':' . $transition;
    }

    public function getEnabledTransitions(object $subject, string $graphName): array
    {
        return [];
    }

    public function getTransitionFromState(object $subject, string $graphName, string $fromState): ?string
    {
        return null;
    }

    public function getTransitionToState(object $subject, string $graphName, string $toState): ?string
    {
        return null;
    }
}
