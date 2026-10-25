<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Copilot\Answers;

use App\Domain\Reporting\Copilot\Tool;

enum AnswerClaim: string
{
    case CashOverview = 'cash.overview';
    case CashAvailable = 'cash.available';
    case CashRaw = 'cash.raw';
    case CashBuffer = 'cash.buffer';
    case CashShortfall = 'cash.shortfall';
    case CashCommitments = 'cash.commitments';
    case EmergencyOverview = 'emergency.overview';
    case EmergencyTarget = 'emergency.target';
    case EmergencyBacked = 'emergency.backed';
    case EmergencyRemaining = 'emergency.remaining';
    case EmergencyProgress = 'emergency.progress';
    case EmergencyCoverage = 'emergency.coverage';
    case NigeriaOverview = 'nigeria.overview';
    case NigeriaAmount = 'nigeria.amount';
    case NigeriaShare = 'nigeria.share';
    case NigeriaDenominator = 'nigeria.denominator';
    case NigeriaCoverage = 'nigeria.coverage';
    case NigeriaComponents = 'nigeria.components';
    case ChangesOverview = 'changes.overview';
    case ChangesAmount = 'changes.amount';
    case ChangesReturn = 'changes.return';
    case ChangesFlows = 'changes.flows';
    case ChangesNative = 'changes.native';
    case ChangesFx = 'changes.fx';
    case AssessmentOverview = 'assessments.overview';
    case AssessmentClassification = 'assessments.classification';
    case AssessmentReview = 'assessments.review';
    case AssessmentProvider = 'assessments.provider';
    case AssessmentMethodology = 'assessments.methodology';
    case AssessmentCount = 'assessments.count';
    case Refuse = 'refuse';
    case Unsupported = 'unsupported';

    public function topic(): AnswerTopic
    {
        return AnswerTopic::from(explode('.', $this->value)[0]);
    }

    public function field(): string
    {
        return explode('.', $this->value)[1] ?? 'overview';
    }

    public function tool(): ?Tool
    {
        return match ($this->topic()) {
            AnswerTopic::Cash => Tool::Cash,
            AnswerTopic::Emergency => Tool::Goals,
            AnswerTopic::Nigeria, AnswerTopic::Changes => Tool::Valuation,
            AnswerTopic::Assessments => Tool::Assessments,
            AnswerTopic::Refuse, AnswerTopic::Unsupported => null,
        };
    }
}
