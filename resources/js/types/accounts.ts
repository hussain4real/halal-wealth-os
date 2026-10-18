export type ExactMoney = { currency: string; minor: string; amount: string };

export type Account = {
    id: string;
    name: string;
    type: string;
    currency: string;
    archived: boolean;
    included_in_net_worth: boolean;
    balance: ExactMoney | null;
    balance_status: 'unavailable' | 'derived';
    net_worth_contribution: ExactMoney | null;
    opening_request: { status: 'awaiting_posting'; amount: ExactMoney } | null;
};
