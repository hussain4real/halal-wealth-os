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

    public function meaning(): string
    {
        return match ($this) {
            self::CashOverview => 'All current cash measures, money.',
            self::CashAvailable => 'Deployable money: max(signed raw availability, zero).',
            self::CashRaw => 'Signed money after protected cash, unfunded requirements and buffer.',
            self::CashBuffer => 'Configured minimum buffer money.',
            self::CashShortfall => 'Money deficit when raw availability is negative; not unfunded commitments.',
            self::CashCommitments => 'Next-income-horizon required unpaid money minus explicit backed overlap.',
            self::EmergencyOverview => 'All measures for the selected emergency goal.',
            self::EmergencyTarget => 'Selected goal target money.',
            self::EmergencyBacked => 'Actual goal backing money; not a percentage.',
            self::EmergencyRemaining => 'Target money still unbacked.',
            self::EmergencyProgress => 'Backed/target percentage; not a money amount.',
            self::EmergencyCoverage => 'Months of essential expenses covered; may be unavailable.',
            self::NigeriaOverview => 'All selected saved geographic Nigeria exposure measures.',
            self::NigeriaAmount => 'Money explicitly tagged Nigeria/NG; not NGN currency exposure.',
            self::NigeriaShare => 'Nigeria percentage of the known covered denominator.',
            self::NigeriaDenominator => 'Known covered denominator money for geographic allocation.',
            self::NigeriaCoverage => 'Valuation completeness/partialness, not a share or money amount.',
            self::NigeriaComponents => 'Count of known Nigeria-tagged components.',
            self::ChangesOverview => 'All investment changes in the selected saved interval.',
            self::ChangesAmount => 'Saved interval value change money; not automatically return.',
            self::ChangesReturn => 'Supported no-external-flow value return percentage.',
            self::ChangesFlows => 'External-or-unsupported movement evidence; not necessarily proven external flows.',
            self::ChangesNative => 'Supported native-currency monetary value movement.',
            self::ChangesFx => 'Reporting-base money due to FX and settlement effects.',
            self::AssessmentOverview => 'Eligible current assessment records, attributed by provenance.',
            self::AssessmentClassification => 'Deterministic source-claim summary; may be pending_review for conflict or no claims.',
            self::AssessmentReview => 'Recorded review-due dates of eligible current records.',
            self::AssessmentProvider => 'Recorded provider labels; provenance is retained.',
            self::AssessmentMethodology => 'Recorded methodology versions; provenance is retained.',
            self::AssessmentCount => 'Count of current source_claim records only.',
            self::Refuse => 'Prohibited data/actions/claims; no tool facts.',
            self::Unsupported => 'Outside one supported topic/period; no tool facts.',
        };
    }

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
