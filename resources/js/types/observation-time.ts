export type ProviderDateTime = {
    precision: 'date';
    source_date: string;
    source_timezone: string;
    received_at: string;
    eligible_after: string;
    age_from: string;
    policy: 'daily-closed-v1';
};
export type ProviderInstantTime = {
    precision: 'instant';
    source_date: null;
    source_timezone: null;
    received_at: string;
    eligible_after: string;
    age_from: string;
    policy: 'provider-instant-v1';
};
export type ObservationTiming =
    | {
          observed_at: string;
          effective_at: string;
          provider_time?: ProviderInstantTime;
      }
    | {
          observed_at: null;
          effective_at: null;
          provider_time: ProviderDateTime;
      };
export type OpeningTiming = {
    observed_at?: undefined;
    effective_at?: undefined;
    provider_time?: undefined;
    effective_date: string;
    opening_id: string;
};
