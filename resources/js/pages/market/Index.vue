<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import ObservationTimeEvidence from '@/components/ObservationTimeEvidence.vue';
import PortfolioAttempt from '@/components/PortfolioAttempt.vue';
import PortfolioShell from '@/components/PortfolioShell.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePortfolioForm } from '@/composables/usePortfolioForm';
import type { ObservationTiming } from '@/types/observation-time';

type Choice = { id: string; name: string; currency: string };
type Feed =
    | {
          kind: 'price';
          symbol: string;
          currency: string;
          mic_code: string;
          exchange_timezone: string;
      }
    | { kind: 'fx'; symbol: string; base: string; quote: string };
type Mapping = {
    id: string;
    reference: string;
    enabled: boolean;
    unadmitted_sample: boolean;
    feed: Feed;
    target:
        | { portfolio_account_id: string; asset_id: string }
        | { base: string; quote: string };
    run: null | {
        claimed_at: string;
        deadline: string;
        ordinal: number;
        status: string;
        code: string | null;
        retry_after: number;
    };
    admission: null | { outcome: 'accepted' | 'rejected'; code: string };
    observation:
        | null
        | (ObservationTiming & {
              value: string;
              source: string;
              stale: boolean;
          });
};
const props = defineProps<{
    mode: 'mock' | 'disabled';
    defaults: Record<string, string>;
    mappings: Mapping[];
    mapping_count: number;
    admission_capacity_reached: boolean;
    next: string | null;
    accounts: Choice[];
    assets: Choice[];
    account_next: string | null;
    asset_next: string | null;
    query: string;
    currencies: string[];
}>();
const { form, feedback, locked, recovered, submit } = usePortfolioForm(
    String(usePage().props.auth.user.id) + ':market-mapping',
    () => props.defaults,
);
watch(
    () => [
        form.kind,
        form.portfolio_account_id,
        form.asset_id,
        form.symbol,
        form.currency,
        form.mic_code,
        form.exchange_timezone,
        form.context,
    ],
    () => {
        if (!locked.value) form.raw_confirmed = '';
    },
);
const search = ref(props.query);
function choices(accountAfter?: string, assetAfter?: string) {
    router.get(
        '/market-data',
        {
            q: search.value,
            ...(form.portfolio_account_id
                ? { portfolio: form.portfolio_account_id }
                : {}),
            ...(form.supersedes_id ? { edit: form.supersedes_id } : {}),
            ...(accountAfter ? { account_after: accountAfter } : {}),
            ...(assetAfter ? { asset_after: assetAfter } : {}),
        },
        {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                if (!locked.value) {
                    form.currency = props.defaults.currency;
                    form.context = props.defaults.context;
                }
            },
        },
    );
}
function disable(mapping: Mapping) {
    if (locked.value || form.processing) return;
    form.kind = 'disable';
    form.supersedes_id = mapping.id;
    form.enabled = '0';
    submit('/market-data');
}
</script>

<template>
    <PortfolioShell
        title="Market data"
        eyebrow="Twelve Data · controlled observations"
        back="/portfolio"
    >
        <template #intro
            >Keep source mappings and refresh evidence alongside your manual
            prices and reporting rates.</template
        >
        <div
            role="status"
            class="mb-6 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950 dark:bg-amber-950 dark:text-amber-100"
        >
            <p class="font-semibold">
                {{
                    mode === 'mock'
                        ? 'Synthetic test transport'
                        : 'Provider connection disabled'
                }}
            </p>
            <p>
                No live prices or rates are fetched. Provider selection is
                approved for mock testing only; live licensing and access remain
                blocked.
            </p>
        </div>
        <div
            v-if="admission_capacity_reached"
            role="alert"
            class="mb-6 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950 dark:bg-amber-950 dark:text-amber-100"
        >
            <p class="font-semibold">Provider admission limit reached</p>
            <p>
                No new provider observations can be admitted for your account.
                Retained observations and receipts remain available. Use sourced
                manual prices or reporting rates when needed.
            </p>
        </div>
        <div class="mb-6 flex flex-wrap gap-3">
            <Button as-child variant="outline"
                ><Link href="/rates">Manual reporting rates</Link></Button
            >
            <Button as-child variant="outline"
                ><Link href="/portfolio"
                    >Portfolio &amp; manual prices</Link
                ></Button
            >
            <Button as-child variant="outline"
                ><Link href="/valuations">Saved valuations</Link></Button
            >
        </div>
        <section
            aria-labelledby="mapping-form-title"
            class="mb-8 rounded-2xl border p-5"
        >
            <h2 id="mapping-form-title" class="mb-3 text-xl font-semibold">
                {{
                    recovered
                        ? 'Recover pending mapping request'
                        : form.supersedes_id
                          ? 'Replace a retained mapping'
                          : 'Add a source mapping'
                }}
            </h2>
            <PortfolioAttempt
                :errors="form.errors"
                :uncertain="feedback.uncertain.value"
                :recovered="recovered"
                history="/market-data"
            />
            <form class="space-y-5" @submit.prevent="submit('/market-data')">
                <fieldset
                    :disabled="locked || form.processing"
                    class="space-y-5"
                >
                    <div>
                        <Label for="market-kind">Observation type</Label>
                        <select
                            id="market-kind"
                            v-model="form.kind"
                            class="mt-2 min-h-11 w-full rounded-md border bg-background p-2"
                        >
                            <option value="fx">Direct reporting FX rate</option>
                            <option value="price">
                                Raw daily equity price
                            </option>
                            <option
                                v-if="form.kind === 'disable'"
                                value="disable"
                            >
                                Disable retained mapping #{{
                                    form.supersedes_id
                                }}
                            </option>
                        </select>
                    </div>
                    <template v-if="form.kind === 'fx'">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <Label for="market-base">Base currency</Label
                                ><select
                                    id="market-base"
                                    v-model="form.base"
                                    class="mt-2 min-h-11 w-full rounded-md border bg-background p-2"
                                >
                                    <option
                                        v-for="currency in currencies"
                                        :key="currency"
                                    >
                                        {{ currency }}
                                    </option>
                                </select>
                            </div>
                            <div>
                                <Label for="market-quote">Quote currency</Label
                                ><select
                                    id="market-quote"
                                    v-model="form.quote"
                                    class="mt-2 min-h-11 w-full rounded-md border bg-background p-2"
                                >
                                    <option
                                        v-for="currency in currencies"
                                        :key="currency"
                                    >
                                        {{ currency }}
                                    </option>
                                </select>
                            </div>
                        </div>
                        <p class="text-sm text-muted-foreground">
                            Quote units per one base unit. Reporting
                            observations never convert cash or change
                            transaction rates.
                        </p>
                    </template>
                    <template v-if="form.kind === 'price'">
                        <div>
                            <Label for="market-search"
                                >Find owned account or asset</Label
                            >
                            <div class="mt-2 flex gap-2">
                                <Input
                                    id="market-search"
                                    v-model="search"
                                    maxlength="120"
                                /><Button
                                    type="button"
                                    variant="outline"
                                    @click="choices()"
                                    >Search</Button
                                >
                            </div>
                        </div>
                        <div>
                            <Label for="market-account"
                                >Investment account</Label
                            >
                            <select
                                id="market-account"
                                v-model="form.portfolio_account_id"
                                required
                                class="mt-2 min-h-11 w-full rounded-md border bg-background p-2"
                                @change="choices()"
                            >
                                <option value="">Choose an account</option>
                                <option
                                    v-if="
                                        form.portfolio_account_id &&
                                        !accounts.some(
                                            (a) =>
                                                a.id ===
                                                form.portfolio_account_id,
                                        )
                                    "
                                    :value="form.portfolio_account_id"
                                >
                                    Retained account #{{
                                        form.portfolio_account_id
                                    }}
                                </option>
                                <option
                                    v-for="account in accounts"
                                    :key="account.id"
                                    :value="account.id"
                                >
                                    {{ account.name }} · {{ account.currency }}
                                </option>
                            </select>
                            <Button
                                v-if="account_next"
                                type="button"
                                variant="link"
                                @click="choices(account_next)"
                                >More accounts</Button
                            >
                        </div>
                        <div>
                            <Label for="market-asset">Unitized asset</Label>
                            <select
                                id="market-asset"
                                v-model="form.asset_id"
                                required
                                class="mt-2 min-h-11 w-full rounded-md border bg-background p-2"
                            >
                                <option value="">Choose an asset</option>
                                <option
                                    v-if="
                                        form.asset_id &&
                                        !assets.some(
                                            (a) => a.id === form.asset_id,
                                        )
                                    "
                                    :value="form.asset_id"
                                >
                                    Retained asset #{{ form.asset_id }}
                                </option>
                                <option
                                    v-for="asset in assets"
                                    :key="asset.id"
                                    :value="asset.id"
                                >
                                    {{ asset.name }} · {{ asset.currency }}
                                </option>
                            </select>
                            <Button
                                v-if="asset_next"
                                type="button"
                                variant="link"
                                @click="choices(undefined, asset_next)"
                                >More assets</Button
                            >
                        </div>
                        <p v-if="!accounts.length" class="text-sm">
                            No matching active accounts.
                            <Link href="/portfolio" class="underline"
                                >Review your portfolio</Link
                            >
                            or clear the search.
                        </p>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <Label for="market-symbol"
                                    >Exact provider symbol</Label
                                ><Input
                                    id="market-symbol"
                                    v-model="form.symbol"
                                    maxlength="32"
                                    required
                                    class="mt-2"
                                />
                            </div>
                            <div>
                                <Label for="market-currency"
                                    >Quote currency</Label
                                ><Input
                                    id="market-currency"
                                    :model-value="form.currency"
                                    readonly
                                    class="mt-2"
                                />
                            </div>
                            <div>
                                <Label for="market-mic">Exchange MIC</Label
                                ><Input
                                    id="market-mic"
                                    v-model="form.mic_code"
                                    maxlength="4"
                                    required
                                    class="mt-2"
                                />
                            </div>
                            <div>
                                <Label for="market-zone"
                                    >Exchange IANA timezone</Label
                                ><Input
                                    id="market-zone"
                                    v-model="form.exchange_timezone"
                                    maxlength="64"
                                    required
                                    placeholder="e.g. America/New_York"
                                    class="mt-2"
                                />
                            </div>
                        </div>
                        <p class="text-sm text-muted-foreground">
                            Confirm the exact listing and raw, unadjusted units.
                            Daily bars retain their exchange date; receipt time
                            is separate. Account history changes require a fresh
                            confirmation.
                        </p>
                        <Label class="flex items-start gap-3"
                            ><input
                                v-model="form.raw_confirmed"
                                type="checkbox"
                                true-value="1"
                                false-value=""
                                required
                                class="mt-1 size-5 shrink-0"
                            />I reviewed the listing, currency and retained
                            splits and confirm raw price units.</Label
                        >
                        <Link
                            v-if="form.portfolio_account_id"
                            :href="
                                '/portfolio/' +
                                form.portfolio_account_id +
                                '/prices'
                            "
                            class="inline-block text-sm underline"
                            >Review retained prices and corporate actions</Link
                        >
                    </template>
                    <p v-if="form.supersedes_id" class="text-sm">
                        Prior mapping #{{ form.supersedes_id }} stays in
                        retained history.
                    </p>
                </fieldset>
                <Button
                    :disabled="form.processing"
                    type="submit"
                    class="min-h-11"
                    >{{
                        form.processing
                            ? 'Saving…'
                            : locked
                              ? 'Retry unchanged request'
                              : 'Save mapping'
                    }}</Button
                >
            </form>
        </section>
        <section aria-labelledby="mappings-title">
            <h2 id="mappings-title" class="mb-4 text-xl font-semibold">
                Current mappings · {{ mapping_count }}
            </h2>
            <p
                v-if="!mappings.length"
                class="rounded-xl border p-5 text-muted-foreground"
            >
                No source mappings yet. Manual observations and saved valuations
                remain available.
            </p>
            <article
                v-for="mapping in mappings"
                :key="mapping.id"
                class="mb-4 space-y-3 rounded-2xl border p-5 [overflow-wrap:anywhere]"
            >
                <h3 class="text-lg font-semibold">
                    {{ mapping.feed.symbol }} ·
                    {{
                        mapping.feed.kind === 'price'
                            ? 'Daily raw price'
                            : 'Reporting FX'
                    }}
                </h3>
                <p class="text-sm">
                    {{
                        mapping.enabled ? 'Mapping enabled' : 'Mapping disabled'
                    }}
                    · #{{ mapping.id }} · {{ mapping.reference }}
                </p>
                <p v-if="mapping.feed.kind === 'price'" class="text-sm">
                    {{ mapping.feed.currency }} · {{ mapping.feed.mic_code }} ·
                    {{ mapping.feed.exchange_timezone }}
                </p>
                <p v-if="mapping.run" class="text-sm">
                    Shared fetch: {{ mapping.run.status
                    }}{{ mapping.run.code ? ' · ' + mapping.run.code : '' }} ·
                    Attempt {{ mapping.run.ordinal }} ·
                    {{ mapping.run.claimed_at
                    }}<span v-if="mapping.run.retry_after">
                        · Provider delay
                        {{ mapping.run.retry_after }} seconds</span
                    >
                </p>
                <p v-else class="text-sm text-muted-foreground">
                    No provider fetch recorded.
                </p>
                <p class="text-sm">
                    Last retained admission:
                    {{
                        mapping.admission
                            ? mapping.admission.outcome +
                              ' · ' +
                              mapping.admission.code
                            : 'No observation admitted for this mapping version.'
                    }}
                </p>
                <p
                    v-if="mapping.unadmitted_sample"
                    role="status"
                    class="rounded-xl border border-amber-300 p-3 text-sm"
                >
                    The latest fetched sample has not been admitted. Admission
                    may be pending or blocked by retained-evidence capacity. The
                    last admitted value is unchanged; use sourced manual
                    evidence when needed.
                </p>
                <div
                    v-if="mapping.observation"
                    class="rounded-xl bg-muted p-3 text-sm"
                >
                    <p class="font-semibold">
                        Last admitted value {{ mapping.observation.value }} ·
                        {{
                            mapping.observation.stale
                                ? 'Stale source'
                                : 'Within seven-day source threshold'
                        }}
                    </p>
                    <p>{{ mapping.observation.source }}</p>
                    <ObservationTimeEvidence :timing="mapping.observation" />
                </div>
                <p v-else class="text-sm text-muted-foreground">
                    No admitted provider observation for this target. Use
                    sourced manual evidence when needed.
                </p>
                <div class="flex flex-wrap gap-3">
                    <Button as-child variant="outline" :disabled="locked"
                        ><Link :href="'/market-data?edit=' + mapping.id"
                            >Review &amp; replace</Link
                        ></Button
                    >
                    <Button
                        v-if="mapping.enabled"
                        variant="outline"
                        :disabled="locked || form.processing"
                        @click="disable(mapping)"
                        >Disable mapping</Button
                    >
                    <Button
                        v-if="'portfolio_account_id' in mapping.target"
                        as-child
                        variant="link"
                        ><Link
                            :href="
                                '/portfolio/' +
                                mapping.target.portfolio_account_id +
                                '/prices'
                            "
                            >Price history &amp; manual fallback</Link
                        ></Button
                    >
                </div>
            </article>
            <Button v-if="next" as-child variant="outline"
                ><Link :href="'/market-data?after=' + next"
                    >More mappings</Link
                ></Button
            >
        </section>
    </PortfolioShell>
</template>
