export type ImportChoice = { id: string; name: string; currency: string };
export type ImportReference = { id: string; label: string };
export type ImportMapping = {
    version: number;
    delimiter: string;
    date_format: string;
    decimal_separator: string;
    columns: Record<string, string>;
    type_map: { value: string; target: string }[];
    currency_map: { value: string; target: string }[];
    default_asset_id: string;
};
export type ImportData = {
    kind: string;
    effective_date: string;
    currency: string;
    amount_minor: string;
    amount: string;
    description: string;
    provider_id: string;
    quantity: string;
    operation_reference: string;
};
export type ImportRow = {
    resolution_status:
        | 'ready'
        | 'invalid'
        | 'ambiguous'
        | 'identity_conflict'
        | 'retained';
    number: string;
    cells: string[];
    data: ImportData | null;
    errors: string[];
    base_errors: string[];
    ready: boolean;
    existing: { receipt_id: string; token: string; type: string } | null;
};
export type ImportPreview = {
    id: string;
    reference: string;
    source_reference: string;
    source_digest: string;
    namespace: string;
    account_id: string;
    currency: string;
    mapping: ImportMapping;
    rows: ImportRow[];
    resolutions: Record<string, Record<string, string>>;
    receipts: { id: string; reference: string }[];
};
export type ImportTarget = {
    journal_id: string | null;
    event_id: string | null;
    portfolio_id: string | null;
    currency: string;
};
export type ImportOutcome = {
    token: string;
    row: string;
    type: 'created' | 'matched' | 'duplicate' | 'replacement' | 'cancelled';
    target: ImportTarget;
    original_target?: ImportTarget;
    data: ImportData;
    origin: { receipt_id: string | null; token: string } | null;
    correction_receipt_id: string | null;
    correctable: boolean;
};
export type ImportReceipt = {
    id: string;
    reference: string;
    snapshot_id: string;
    reason: string | null;
    corrects_receipt_id: string | null;
    outcomes: ImportOutcome[];
};
