import type { ExactMoney } from './accounts';
export type InvestmentEvent = {
    id: string;
    reference: string;
    kind: string;
    effective_date: string;
    sort_id: string;
    asset_id: string;
    asset_name: string;
    asset_version: string;
    asset_family: string;
    currency: string;
    quantity: string;
    unit_price: string;
    amount: ExactMoney;
    fee: ExactMoney;
    tax: ExactMoney;
    cash_effect: ExactMoney;
    split_numerator: string;
    split_denominator: string;
    subscription_id: string;
    action_reference: string;
    source: string;
    reason: string;
    journal_id: string | null;
    reverses_id: string | null;
    replaces_id: string | null;
    reversed_by: string | null;
    replacement_id: string | null;
};
export type IpoApplication = {
    id: string;
    reference: string;
    asset_id: string;
    name: string;
    cancelled: boolean;
    planned: ExactMoney;
    paid: ExactMoney;
    reserved: ExactMoney;
    receivable: ExactMoney;
    allotted: ExactMoney;
    refunded: ExactMoney;
};
export const eventLabels: Record<string, string> = {
    buy: 'Purchase',
    sell: 'Sale',
    dividend: 'Dividend',
    fee: 'Fee',
    split: 'Split',
    ipo_intent: 'IPO intention',
    ipo_reserve: 'IPO earmark',
    ipo_release: 'Earmark release',
    ipo_pay: 'Application payment',
    ipo_allot: 'Confirmed allotment',
    ipo_refund: 'Recorded refund',
    ipo_cancel: 'Application cancellation',
    reversal: 'Linked cancellation',
};
