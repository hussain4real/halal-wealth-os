export type Assessment = {
    id: string;
    reference: string;
    series_id: string;
    version: string;
    supersedes_id: string | null;
    asset_id: string;
    asset_family: string;
    provenance: string;
    provider: string;
    methodology_version: string;
    classification: string;
    effective_on: string;
    review_due_on: string | null;
    overdue: boolean;
    recorded_at: string;
    evidence_refs: string[];
    source_url: string | null;
    business_findings: string | null;
    financial_findings: string | null;
    notes: string | null;
    reason: string;
    actor_id: string;
    actor_is_admin: boolean;
};
export type AssessmentSummary = {
    classification: string;
    conflict: boolean;
    overdue: boolean;
    review_date_missing: boolean;
    other_reference_evidence: boolean;
    source_count: number;
    guidance_state: string;
    guidance: string;
    factual_recording: string;
};
export const classifications: Record<string, string> = {
    pending_review: 'Pending review',
    compliant: 'Compliant',
    non_compliant: 'Non-compliant',
    questionable: 'Questionable',
};
export const provenanceLabels: Record<string, string> = {
    source_claim: 'Attributed source claim',
    personal_note: 'Personal note',
    ai_suggestion: 'Retained AI suggestion',
};
