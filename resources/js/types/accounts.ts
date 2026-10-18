export type ExactMoney = { currency: string; minor: string; amount: string };

export type Account = {
    id: string;
    name: string;
    type: string;
    currency: string;
    archived: boolean;
    system: boolean;
    included_in_net_worth: boolean;
    balance: ExactMoney | null;
    balance_status: 'unavailable' | 'derived';
    balance_reason: string | null;
    valuation_mode: string;
    portfolio_id: string | null;
    net_worth_reason: string | null;
    net_worth_contribution: ExactMoney | null;
    opening_request: {
        status: 'awaiting_posting' | 'posted' | 'reversed';
        amount: ExactMoney;
        journal_id: string | null;
    } | null;
};
