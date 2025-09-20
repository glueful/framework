<?php

declare(strict_types=1);

namespace Glueful\Permissions;

use Glueful\Auth\UserIdentity;
use Glueful\Permissions\Vote;

final class Gate
{
    /** @var VoterInterface[] */
    private array $voters = [];
    private string $strategy;
    private bool $allowDenyOverride;

    public function __construct(string $strategy = 'affirmative', bool $allowDenyOverride = false)
    {
        $this->strategy = $strategy;
        $this->allowDenyOverride = $allowDenyOverride;
    }

    public function registerVoter(VoterInterface $voter): void
    {
        $this->voters[] = $voter;
        usort($this->voters, fn($a, $b) => $a->priority() <=> $b->priority());
    }

    /**
     * @param callable|null $providerDecide Optional provider callback: fn(): string Vote::*
     */
    public function decide(
        UserIdentity $user,
        string $permission,
        mixed $resource = null,
        ?Context $ctx = null,
        ?callable $providerDecide = null
    ): string {
        $ctx ??= new Context();

        // 1) Provider first if present (combine mode): provider returns grant|deny|abstain
        $initial = $providerDecide !== null ? (string) $providerDecide() : Vote::ABSTAIN;

        $grants = 0;
        $denies = 0;

        if ($initial === Vote::GRANT) {
            $grants++;
        } elseif ($initial === Vote::DENY) {
            $denies++;
        }

        foreach ($this->voters as $v) {
            if (!$v->supports($permission, $resource, $ctx)) {
                continue;
            }
            $vote = $v->vote($user, $permission, $resource, $ctx)->result;

            if ($vote === Vote::DENY) {
                $denies++;
                if (!$this->allowDenyOverride) {
                    // Hard deny early-out
                    return Vote::DENY;
                }
            } elseif ($vote === Vote::GRANT) {
                $grants++;
                if ($this->strategy === 'affirmative' && $denies === 0) {
                    return Vote::GRANT;
                }
            }
        }

        // Aggregate by strategy
        return match ($this->strategy) {
            'unanimous' => ($denies === 0 && $grants > 0) ? Vote::GRANT : Vote::DENY,
            'consensus' => ($grants > $denies) ? Vote::GRANT : Vote::DENY,
            default      => ($grants > 0 && $denies === 0) ? Vote::GRANT : Vote::DENY, // affirmative fallback
        };
    }
}
