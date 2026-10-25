<?php

namespace App\Domain\Intelligence\Answers;

use App\Domain\Intelligence\UntrustedInput;
use App\Domain\Reporting\Copilot\Answers\AnswerPlan;

interface AnswerPlanner
{
    public function available(): bool;

    /** Only question/document plus fixed schema; never trusted owner records or IDs. */
    public function plan(UntrustedInput $input): AnswerPlan;
}
