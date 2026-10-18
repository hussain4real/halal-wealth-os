export type BudgetMoney = {
    currency: string;
    minor: string | null;
    amount: string | null;
    available: boolean;
    reason: string | null;
};
export type BudgetCategory = {
    id: string;
    family: string;
    version: string;
    name: string;
    direction: 'income' | 'expense';
    normal_budget: boolean;
    emergency_essential: boolean;
    supersedes_id: string | null;
    reason: string | null;
};
export type BudgetRule = {
    id: string;
    reference: string;
    family: string;
    version: string;
    name: string;
    category_id: string;
    category: BudgetCategory;
    currency: string;
    amount: BudgetMoney;
    cadence: 'once' | 'monthly' | 'term' | 'annual';
    interval_months: string;
    first_due_date: string;
    provision_start_date: string;
    end_date: string | null;
    cutover_date: string;
    salary: boolean;
    standing_order: boolean;
    supersedes_id: string | null;
    latest: boolean;
    reason: string | null;
};
export type BudgetAllocation = {
    id: string;
    reference: string;
    journal_id: string;
    journal_reference: string;
    journal_date: string;
    journal_status: string;
    released: boolean;
    release_reason: string | null;
    effective: boolean;
    amount: BudgetMoney;
};
export type BudgetOccurrence = {
    key: string;
    id: string | null;
    schedule_id: string;
    name: string;
    due_date: string;
    category: BudgetCategory;
    expected: BudgetMoney;
    matched: BudgetMoney;
    remaining: BudgetMoney;
    status: 'unpaid' | 'partial' | 'matched' | 'superseded';
    needs_review: boolean;
    overdue: boolean;
    superseded: boolean;
    standing_order: boolean;
    salary: boolean;
    allocations: BudgetAllocation[];
    allocation_count: string;
    history_more: boolean;
};
export type BudgetActual = {
    id: string;
    reference: string;
    date: string;
    kind: string;
    status: string;
    description: string;
    direction: 'income' | 'expense';
    category_id: string | null;
    category_name: string | null;
    category_version: string | null;
    family: string | null;
    normal_budget: boolean | null;
    value: BudgetMoney;
    period_value?: BudgetMoney;
    allocated: BudgetMoney;
    remaining: BudgetMoney;
};
export type BudgetMonth = {
    month: string;
    today: string;
    previous_month: string | null;
    next_month: string | null;
    categories: BudgetCategory[];
    rules: BudgetRule[];
    occurrences: BudgetOccurrence[];
    totals: {
        currency: string;
        planned_income: BudgetMoney;
        planned_expense: BudgetMoney;
        actual_income: BudgetMoney;
        actual_expense: BudgetMoney;
        unclassified_income: BudgetMoney;
        unclassified_expense: BudgetMoney;
        essential_monthly: BudgetMoney;
    }[];
    comparisons: {
        family: string;
        name: string;
        direction: string;
        planned: BudgetMoney;
        actual: BudgetMoney;
        variance: BudgetMoney;
    }[];
    provisions: {
        schedule_id: string;
        name: string;
        months: string;
        amount: BudgetMoney;
        essential: boolean;
    }[];
    activity: BudgetActual[];
    activity_before: string | null;
    activity_next_cursor: string | null;
};

export type BudgetReceiptPage = {
    receipts: BudgetActual[];
    selected: BudgetActual | null;
    next_cursor: string | null;
};
export type BudgetHistoryPage = {
    occurrence: BudgetOccurrence;
    month: string;
    before: string | null;
    next_cursor: string | null;
};
