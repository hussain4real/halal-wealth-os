<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import PortfolioShell from '@/components/PortfolioShell.vue';
import PortfolioAttempt from '@/components/PortfolioAttempt.vue';
import OpeningAssessmentGuidance from '@/components/OpeningAssessmentGuidance.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePortfolioForm } from '@/composables/usePortfolioForm';
import { portfolioReferenceCache } from '@/composables/portfolioReferenceCache';
import { openingAssessmentGuidance } from '@/composables/openingAssessmentGuidance';
import type { OpeningGuidanceState } from '@/composables/openingAssessmentGuidance';
import type {
    Asset,
    OpeningRow,
    Page,
    PortfolioAccount,
    Position,
} from '@/types/portfolio';
import { kindLabel } from '@/types/portfolio';
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Portfolio', href: '/portfolio' },
            { title: 'Opening statement', href: '#' },
        ],
    },
});
const props = defineProps<{
    account: PortfolioAccount;
    initial_rows: Position[];
    choices: Page<Asset>;
    query: string;
    today: string;
}>();
const referenceCache = portfolioReferenceCache(
    String(usePage().props.auth.user.id) + ':' + props.account.portfolio_id,
);
const metadata = ref(referenceCache.load());
for (const row of props.initial_rows)
    metadata.value.set(row.asset_id, {
        name: row.name,
        kind: row.kind,
        version: row.asset_version,
    });
watch(
    () => props.choices.items,
    (items) => {
        for (const row of items) metadata.value.set(row.id, row);
    },
    { immediate: true },
);
const initial = props.initial_rows.map((r) => ({
    asset_id: r.asset_id,
    quantity: r.quantity ?? '',
    unit_price: r.unit_price ?? '',
    manual_value: r.manual_value?.amount ?? '',
    basis_known: r.basis_known ? '1' : '0',
    cost_basis: r.cost_basis?.amount ?? '',
    acquired_on: r.acquired_on ?? '',
}));
const { form, feedback, recovered, locked, submit } = usePortfolioForm(
    String(usePage().props.auth.user.id) +
        ':opening:' +
        props.account.portfolio_id,
    {
        prior_id: props.account.opening?.id ?? '',
        as_of: props.account.opening?.as_of ?? props.today,
        source: props.account.opening?.source ?? '',
        reason: '',
        rows: JSON.stringify(initial),
    },
);
form.transform((data) => ({
    ...data,
    rows: JSON.parse(data.rows) as OpeningRow[],
}));
const rows = computed<OpeningRow[]>(
    () => JSON.parse(form.rows) as OpeningRow[],
);
const saveOpening = () => {
    const selectedReferences = new Map(
        rows.value.flatMap((row) => {
            const ref = metadata.value.get(row.asset_id);
            return ref ? [[row.asset_id, ref] as const] : [];
        }),
    );
    referenceCache.save(selectedReferences);
    submit('/portfolio/' + props.account.portfolio_id + '/opening');
};
const page = ref(0);
const visible = computed(() =>
    rows.value.slice(page.value * 20, page.value * 20 + 20),
);
const selected = ref('');
watch(
    () => props.choices.items,
    (items) => {
        if (
            selected.value &&
            !items.some((asset) => asset.id === selected.value)
        ) {
            selected.value = '';
        }
    },
);
const search = ref(props.query);
const message = ref('');
const guidance = ref<OpeningGuidanceState>({
    status: 'unavailable',
    cutoff: null,
    items: new Map(),
});
const guidanceIds = computed(() => [
    ...new Set([
        ...visible.value.map((row) => row.asset_id),
        ...(selected.value ? [selected.value] : []),
    ]),
]);
const guidanceReader = openingAssessmentGuidance(
    '/portfolio/' + props.account.portfolio_id + '/opening/assessment-guidance',
    (state) => {
        guidance.value = state;
    },
);
const refreshGuidance = () => {
    void guidanceReader.refresh(guidanceIds.value);
};
let stopGuidance: (() => void) | undefined;
let stopNavigation: (() => void) | undefined;
onMounted(() => {
    stopGuidance = watch(() => guidanceIds.value.join(','), refreshGuidance, {
        immediate: true,
    });
    stopNavigation = router.on('navigate', refreshGuidance);
    window.addEventListener('focus', refreshGuidance);
    window.addEventListener('pageshow', refreshGuidance);
});
onBeforeUnmount(() => {
    stopGuidance?.();
    stopNavigation?.();
    guidanceReader.dispose();
    window.removeEventListener('focus', refreshGuidance);
    window.removeEventListener('pageshow', refreshGuidance);
});
const blocked = computed(
    () => form.processing || locked.value || props.account.archived,
);
const add = () => {
    const asset = props.choices.items.find((a) => a.id === selected.value);
    if (!asset || blocked.value) return;
    if (rows.value.length >= 1000) {
        message.value = 'The complete statement is limited to 1,000 positions.';
        return;
    }
    if (
        rows.value.some(
            (r) =>
                r.asset_id === asset.id ||
                props.initial_rows.some(
                    (old) =>
                        old.asset_id === r.asset_id &&
                        old.asset_family === asset.family,
                ),
        )
    ) {
        message.value =
            'This asset family is already in the statement. Edit its retained row.';
        return;
    }
    form.rows = JSON.stringify([
        ...rows.value,
        {
            asset_id: asset.id,
            quantity: '',
            unit_price: '',
            manual_value: '',
            basis_known: '0',
            cost_basis: '',
            acquired_on: '',
        },
    ]);
    page.value = Math.floor((rows.value.length - 1) / 20);
    selected.value = '';
    message.value = '';
};
const update = (
    index: number,
    field: keyof OpeningRow,
    value: string | number,
) => {
    const next = rows.value.map((r) => ({ ...r }));
    next[index][field] = String(value);
    if (field === 'basis_known' && value === '0') {
        next[index].cost_basis = '';
        next[index].acquired_on = '';
    }
    form.rows = JSON.stringify(next);
};
const remove = (index: number) => {
    form.rows = JSON.stringify(rows.value.filter((_, i) => i !== index));
    page.value = Math.min(
        page.value,
        Math.max(0, Math.ceil(rows.value.length / 20) - 1),
    );
};
const find = (after?: string) =>
    router.get(
        '/portfolio/' + props.account.portfolio_id + '/opening',
        { q: search.value, ...(after ? { after } : {}) },
        {
            preserveState: true,
            preserveScroll: true,
            only: ['choices', 'query'],
        },
    );
watch(
    () => form.errors,
    (errors) => {
        const key = Object.keys(errors).find((k) => /^rows\.\d+\./.test(k));
        if (key) page.value = Math.floor(Number(key.split('.')[1]) / 20);
    },
);
const rowError = (index: number, field: string) =>
    form.errors['rows.' + index + '.' + field];
</script>
<template>
    <PortfolioShell
        :title="
            account.opening
                ? 'Correct the opening statement.'
                : 'Record your opening positions.'
        "
        :back="'/portfolio/' + account.portfolio_id"
        ><template #intro
            >{{ account.name }} · {{ account.currency }}. This is the complete
            opening book. Corrections replace the selected book at its original
            UTC date and retain every previous version. Record trades in
            Activity and update manual prices in Prices.</template
        >
        <section
            class="rounded-2xl border bg-muted/25 p-5 text-sm leading-relaxed"
        >
            <p>
                Keep units and prices exact: up to 18 integer and 12 fractional
                digits. Values settle to native cash precision only after
                multiplication. All positions must use {{ account.currency }};
                mixed-currency holdings need separate accounts.
            </p>
            <p class="mt-2">
                An unknown price or basis stays unknown. Never use cost basis as
                a current value. Reference prices and manual values are usable
                only for the opening UTC date.
            </p>
        </section>
        <section class="rounded-2xl border bg-card p-5 text-sm sm:p-7">
            <h2 class="font-semibold">
                Assessment evidence for the selected positions
            </h2>
            <p class="mt-2">
                Review current sources before an investment decision. This
                opening statement records facts; it does not certify assets or
                restrict factual holdings by assessment status.
            </p>
            <Button
                type="button"
                variant="outline"
                class="mt-3"
                :disabled="!guidanceIds.length"
                @click="refreshGuidance"
                >Refresh current assessment evidence</Button
            >
            <p v-if="guidance.status === 'loading'" role="status" class="mt-3">
                Checking current evidence for the selected and visible
                references.
            </p>
            <p
                v-else-if="
                    guidance.status === 'unavailable' && guidanceIds.length
                "
                role="status"
                class="mt-3"
            >
                Current evidence could not be loaded here. Use each reference’s
                evidence link; factual recording remains available.
            </p>
        </section>
        <section class="rounded-2xl border bg-card p-5 sm:p-7">
            <h2 class="text-lg font-semibold">
                Add a reference to the complete book
            </h2>
            <form class="mt-4 flex gap-2" @submit.prevent="find()">
                <Label for="q" class="sr-only">Search asset references</Label
                ><Input
                    id="q"
                    v-model="search"
                    placeholder="Search asset name or identifier"
                    class="max-w-md min-w-0"
                /><Button type="submit" variant="outline">Search</Button>
            </form>
            <div class="mt-4 flex flex-wrap items-end gap-3">
                <div class="min-w-0 flex-1">
                    <Label for="asset"
                        >{{ account.currency }} asset reference</Label
                    ><select
                        id="asset"
                        v-model="selected"
                        :disabled="blocked"
                        class="mt-2 min-h-11 w-full rounded-lg border bg-background px-3"
                    >
                        <option value="">Choose a reference</option>
                        <option
                            v-for="asset in choices.items"
                            :key="asset.id"
                            :value="asset.id"
                        >
                            {{ asset.name }} · {{ kindLabel(asset.kind) }} · v{{
                                asset.version
                            }}
                        </option>
                    </select>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="blocked || !selected"
                    @click="add"
                    >Add position</Button
                >
            </div>
            <OpeningAssessmentGuidance
                v-if="selected"
                :asset-id="selected"
                :state="guidance"
            />
            <p v-if="message" role="status" class="mt-3 text-sm">
                {{ message }}
            </p>
            <p
                v-if="!choices.items.length"
                class="mt-4 text-sm text-muted-foreground"
            >
                No matching native-currency references.
                <Link href="/assets/new" class="underline"
                    >Add an asset reference</Link
                >
                first. Unsaved edits stay on this page only; finish or cancel
                before leaving.
            </p>
            <Button
                v-if="choices.next"
                class="mt-3"
                type="button"
                variant="ghost"
                @click="find(choices.next ?? undefined)"
                >More references →</Button
            >
        </section>
        <form class="space-y-5" @submit.prevent="saveOpening">
            <PortfolioAttempt
                :errors="form.errors"
                :uncertain="feedback.uncertain.value"
                :recovered="recovered"
                :history="'/portfolio/' + account.portfolio_id"
            />
            <fieldset :disabled="blocked" class="space-y-5">
                <div class="rounded-2xl border bg-card p-5 sm:p-7">
                    <h2 class="text-lg font-semibold">Statement evidence</h2>
                    <div class="mt-5 grid gap-5 sm:grid-cols-2">
                        <div>
                            <Label for="asof">Opening as-of date · UTC</Label
                            ><Input
                                id="asof"
                                v-model="form.as_of"
                                type="date"
                                required
                                :readonly="!!account.opening"
                                :max="today"
                                :aria-invalid="!!form.errors.as_of"
                            />
                        </div>
                        <div>
                            <Label for="source">Manual statement source</Label
                            ><Input
                                id="source"
                                v-model="form.source"
                                required
                                maxlength="180"
                                :aria-invalid="!!form.errors.source"
                            />
                        </div>
                    </div>
                    <div class="mt-5">
                        <Label for="reason"
                            >Reason for this opening version</Label
                        ><Input
                            id="reason"
                            v-model="form.reason"
                            required
                            maxlength="180"
                            :aria-invalid="!!form.errors.reason"
                        />
                    </div>
                    <p class="mt-4 text-sm text-muted-foreground">
                        {{ rows.length }} positions in the complete statement.
                        All rows are saved together, including those on other
                        pages. Saving an empty book explicitly replaces it with
                        zero positions.
                    </p>
                </div>
                <article
                    v-for="(row, offset) in visible"
                    :key="page * 20 + offset"
                    class="min-w-0 rounded-2xl border bg-card p-5 sm:p-7"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-3"
                    >
                        <div class="min-w-0">
                            <h2 class="font-semibold [overflow-wrap:anywhere]">
                                {{
                                    metadata.get(row.asset_id)?.name ??
                                    'Retained asset reference ' + row.asset_id
                                }}
                            </h2>
                            <p class="mt-1 text-xs text-muted-foreground">
                                Exact reference v{{
                                    metadata.get(row.asset_id)?.version ??
                                    'retained'
                                }}
                                · {{ account.currency }}
                            </p>
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            :aria-label="
                                'Remove position ' + (page * 20 + offset + 1)
                            "
                            @click="remove(page * 20 + offset)"
                            >Remove position</Button
                        >
                    </div>
                    <OpeningAssessmentGuidance
                        :asset-id="row.asset_id"
                        :state="guidance"
                    />
                    <div class="mt-5 grid gap-5 sm:grid-cols-2">
                        <template
                            v-if="
                                metadata.get(row.asset_id)?.kind !==
                                'manual_investment'
                            "
                            ><div>
                                <Label :for="'quantity-' + offset"
                                    >Exact units</Label
                                ><Input
                                    :id="'quantity-' + offset"
                                    :model-value="row.quantity"
                                    inputmode="decimal"
                                    required
                                    maxlength="32"
                                    :aria-invalid="
                                        !!rowError(
                                            page * 20 + offset,
                                            'quantity',
                                        )
                                    "
                                    @update:model-value="
                                        update(
                                            page * 20 + offset,
                                            'quantity',
                                            $event,
                                        )
                                    "
                                />
                            </div>
                            <div>
                                <Label :for="'price-' + offset"
                                    >Reference unit price · optional</Label
                                ><Input
                                    :id="'price-' + offset"
                                    :model-value="row.unit_price"
                                    inputmode="decimal"
                                    maxlength="32"
                                    :aria-invalid="
                                        !!rowError(
                                            page * 20 + offset,
                                            'unit_price',
                                        )
                                    "
                                    @update:model-value="
                                        update(
                                            page * 20 + offset,
                                            'unit_price',
                                            $event,
                                        )
                                    "
                                />
                                <p class="mt-2 text-xs text-muted-foreground">
                                    Blank means unknown. Explicit zero remains
                                    zero.
                                </p>
                            </div></template
                        >
                        <div
                            v-if="
                                metadata.get(row.asset_id)?.kind ===
                                    'manual_investment' ||
                                !metadata.has(row.asset_id)
                            "
                        >
                            <Label :for="'manual-' + offset"
                                >Manual reference value · optional</Label
                            ><Input
                                :id="'manual-' + offset"
                                :model-value="row.manual_value"
                                :aria-invalid="
                                    !!rowError(
                                        page * 20 + offset,
                                        'manual_value',
                                    )
                                "
                                inputmode="decimal"
                                maxlength="32"
                                @update:model-value="
                                    update(
                                        page * 20 + offset,
                                        'manual_value',
                                        $event,
                                    )
                                "
                            />
                            <p class="mt-2 text-xs text-muted-foreground">
                                No units or unit price are invented for this
                                investment.
                            </p>
                        </div>
                        <div>
                            <Label :for="'known-' + offset"
                                >Opening cost basis</Label
                            ><select
                                :id="'known-' + offset"
                                :value="row.basis_known"
                                :aria-invalid="
                                    !!rowError(
                                        page * 20 + offset,
                                        'basis_known',
                                    )
                                "
                                class="mt-2 min-h-11 w-full rounded-lg border bg-background px-3"
                                @change="
                                    update(
                                        page * 20 + offset,
                                        'basis_known',
                                        ($event.target as HTMLSelectElement)
                                            .value,
                                    )
                                "
                            >
                                <option value="0">
                                    Unknown · no profit basis
                                </option>
                                <option value="1">
                                    Known · enter total native cost
                                </option>
                            </select>
                        </div>
                        <div v-if="row.basis_known === '1'">
                            <Label :for="'basis-' + offset"
                                >Total cost basis ·
                                {{ account.currency }}</Label
                            ><Input
                                :id="'basis-' + offset"
                                :model-value="row.cost_basis"
                                inputmode="decimal"
                                maxlength="32"
                                required
                                :aria-invalid="
                                    !!rowError(page * 20 + offset, 'cost_basis')
                                "
                                @update:model-value="
                                    update(
                                        page * 20 + offset,
                                        'cost_basis',
                                        $event,
                                    )
                                "
                            />
                            <Label
                                :for="'acquired-' + offset"
                                class="mt-4 block"
                                >Sourced acquisition date · optional</Label
                            ><Input
                                :id="'acquired-' + offset"
                                type="date"
                                :model-value="row.acquired_on ?? ''"
                                :max="form.as_of"
                                @update:model-value="
                                    update(
                                        page * 20 + offset,
                                        'acquired_on',
                                        $event,
                                    )
                                "
                            />
                            <p class="mt-2 text-xs text-muted-foreground">
                                Leave blank for mixed acquisition dates or
                                missing evidence. Foreign-currency opening cost
                                stays unavailable without a sourced historical
                                date and transaction FX.
                            </p>
                            <p class="mt-2 text-xs text-muted-foreground">
                                Explicit known zero is allowed. This amount is
                                never a price.
                            </p>
                        </div>
                    </div>
                </article>
            </fieldset>
            <nav
                v-if="rows.length > 20"
                aria-label="Opening position pages"
                class="flex flex-wrap items-center gap-3"
            >
                <Button
                    type="button"
                    variant="outline"
                    :disabled="page === 0"
                    @click="page--"
                    >Previous positions</Button
                ><span class="text-sm"
                    >Page {{ page + 1 }} of
                    {{ Math.ceil(rows.length / 20) }}</span
                ><Button
                    type="button"
                    variant="outline"
                    :disabled="(page + 1) * 20 >= rows.length"
                    @click="page++"
                    >Next positions</Button
                >
            </nav>
            <div class="flex flex-wrap gap-3 border-t pt-5">
                <Button
                    type="submit"
                    :disabled="form.processing || (account.archived && !locked)"
                    >{{
                        form.processing
                            ? 'Saving…'
                            : locked
                              ? 'Retry same statement'
                              : 'Save complete opening statement'
                    }}</Button
                ><Button as-child variant="outline"
                    ><Link :href="'/portfolio/' + account.portfolio_id"
                        >Cancel</Link
                    ></Button
                >
            </div>
        </form>
    </PortfolioShell>
</template>
