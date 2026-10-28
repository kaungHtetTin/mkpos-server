<?php

namespace App\Services\AgentSkills;

/** Rolls catalog validation back, including temporary skill/version/assignment/audit writes. */
class ProposalPreview extends \RuntimeException
{
    public function __construct(public readonly array $review) { parent::__construct('Proposal preview'); }
}
