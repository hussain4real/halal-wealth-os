<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CurrencyDisplay from '@/components/CurrencyDisplay.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    importErrors,
    importPost,
    importUrl,
    useStatementRequest,
} from '@/composables/useStatementRequest';
import type {
    ImportChoice,
    ImportPreview,
    ImportReference,
} from '@/types/imports';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Statement imports', href: '/imports' },
            { title: 'Review', href: '#' },
        ],
    },
});
const props = defineProps<{
    preview: ImportPreview;
    accounts: ImportChoice[];
    assets: ImportChoice[];
    transfers: ImportReference[];
    events: ImportReference[];
    kinds: string[];
}>();
const selection = (raw: string): string[] => {
    const value: unknown = JSON.parse(raw);
    if (
        !Array.isArray(value) ||
        !value.every((n): n is string => typeof n === 'string')
    )
        throw new Error('The retained selection is invalid.');
    return value;
};
const request = useStatementRequest(
    String(usePage().props.auth.user.id) + ':approve:' + props.preview.id,
    '/imports/' + props.preview.id + '/approve',
    { selection: '[]' },
    (fields) => ({
        idempotency_key: fields.idempotency_key,
        selection: selection(fields.selection),
    }),
);
const { fields, locked, busy, errors, notice, submit, recover } = request;
const selected = computed<string[]>(() => {
    try {
        return selection(fields.value.selection);
    } catch {
        return [];
    }
});
const toggle = (number: string) => {
    if (!locked.value)
        fields.value.selection = JSON.stringify(
            selected.value.includes(number)
                ? selected.value.filter((n) => n !== number)
                : [...selected.value, number],
        );
};
const confirmed = ref(false);
const page = ref(0);
const visible = computed(() =>
    props.preview.rows.slice(page.value * 20, page.value * 20 + 20),
);
const pageCount = computed(() => Math.ceil(props.preview.rows.length / 20));
const readyCount = computed(
    () => props.preview.rows.filter((r) => r.ready).length,
);
const resolutions = ref<Record<string, Record<string, string>>>(
    Object.fromEntries(
        Object.entries(props.preview.resolutions).map(([number, values]) => [
            number,
            { ...values },
        ]),
    ),
);
const resolutionErrors = ref<string[]>([]);
const resolving = ref(false);
const setResolution = (number: string, field: string, event: Event) => {
    const target = event.target;
    if (
        target instanceof HTMLInputElement ||
        target instanceof HTMLSelectElement
    )
        resolutions.value[number] = {
            ...resolutions.value[number],
            [field]: target.value,
        };
};
const revise = async () => {
    if (resolving.value || locked.value) return;
    resolving.value = true;
    resolutionErrors.value = [];
    try {
        const { response, body } = await importPost(
            '/imports/' + props.preview.id + '/revise',
            { resolutions: resolutions.value },
        );
        const url = importUrl(body);
        if (response.ok && url) router.visit(url);
        else resolutionErrors.value = importErrors(body);
    } catch {
        resolutionErrors.value = [
            'Preview response interrupted. Repeating these resolutions is safe; no approval was sent.',
        ];
    } finally {
        resolving.value = false;
        document.querySelector<HTMLElement>('[data-import-feedback]')?.focus();
    }
};
</script>

<template>
    <Head title="Review statement" />
    <main class="mx-auto w-full max-w-5xl space-y-5 p-4 md:p-8">
        <header class="space-y-2">
            <Link href="/imports" class="text-sm underline"
                >Statement imports</Link
            >
            <h1 class="text-2xl font-semibold">
                Review {{ preview.namespace }}
            </h1>
            <p class="text-sm text-muted-foreground">
                {{ preview.rows.length }} rows · {{ readyCount }} ready ·
                {{ preview.currency }}. Preview records evidence only. Approval
                checks current accounts and investment replay again.
            </p>
            <p class="text-xs break-all text-muted-foreground">
                Source {{ preview.source_reference }} · Preview
                {{ preview.reference }}
            </p>
        </header>
        <div
            data-import-feedback
            tabindex="-1"
            aria-live="polite"
            class="space-y-2 outline-none"
        >
            <p v-if="notice" class="rounded-lg border bg-muted p-3 text-sm">
                {{ notice }}
            </p>
            <p
                v-for="error in [...errors, ...resolutionErrors]"
                :key="error"
                class="rounded-lg border border-destructive p-3 text-destructive"
            >
                {{ error }}
            </p>
        </div>
        <section
            v-if="locked"
            class="flex flex-wrap gap-3 rounded-xl border p-4"
            aria-label="Interrupted approval recovery"
        >
            <Button :disabled="busy" @click="recover"
                >Check committed receipt</Button
            ><Button variant="outline" :disabled="busy" @click="submit"
                >Retry unchanged approval</Button
            >
        </section>
        <div class="flex flex-wrap items-center gap-3">
            <a
                :href="'/imports/' + preview.id + '/export'"
                class="rounded-md border px-3 py-2 text-sm underline"
                >Download spreadsheet-safe CSV evidence</a
            ><span class="text-xs text-muted-foreground"
                >Exact source bytes remain in your complete owner JSON
                export.</span
            >
        </div>
        <div class="rounded-lg border bg-muted/40 p-4 text-sm">
            Rows execute by effective date, then source-row order. Put funding
            before dependent trades on the same date. Transfer-in/out requires a
            counterparty and a shared stable transfer reference. Existing
            matches add evidence only. Repeated no-ID rows need persistent
            occurrence labels; the app will not invent them.
        </div>
        <section class="space-y-4" aria-label="Statement rows">
            <article
                v-for="row in visible"
                :key="row.number"
                class="min-w-0 space-y-3 rounded-xl border p-4"
                :class="row.errors.length ? 'border-amber-500/60' : ''"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <label class="flex min-w-0 items-start gap-3"
                        ><input
                            type="checkbox"
                            :checked="selected.includes(row.number)"
                            :disabled="!row.ready || locked || busy"
                            :aria-label="'Approve row ' + row.number"
                            class="mt-1 size-4"
                            @change="toggle(row.number)"
                        /><span class="min-w-0"
                            ><strong>Row {{ row.number }}</strong
                            ><span class="ml-2 text-sm"
                                >{{ row.data?.kind ?? 'Unresolved' }} ·
                                {{
                                    row.data?.effective_date ?? 'Invalid date'
                                }}</span
                            ><span class="mt-1 block break-words">{{
                                row.data?.description ??
                                'Review source cells and validation reasons'
                            }}</span></span
                        ></label
                    ><span v-if="row.data" class="shrink-0"
                        ><CurrencyDisplay
                            :amount="row.data.amount"
                            :currency="row.data.currency"
                        /><span
                            v-if="
                                ['buy', 'sell', 'dividend'].includes(
                                    row.data.kind,
                                )
                            "
                            class="block text-xs text-muted-foreground"
                            >Gross consideration{{
                                row.data.quantity
                                    ? ' · ' + row.data.quantity + ' units'
                                    : ''
                            }}</span
                        ></span
                    >
                </div>
                <ul
                    v-if="row.errors.length"
                    class="space-y-1 text-sm text-amber-800 dark:text-amber-200"
                >
                    <li v-for="reason in row.errors" :key="reason">
                        {{ reason }}
                    </li>
                </ul>
                <p v-else-if="row.existing" class="text-sm">
                    Already retained ({{ row.existing.type }}).
                    <Link
                        :href="'/imports/receipts/' + row.existing.receipt_id"
                        class="underline"
                        >Open its receipt and corrected history</Link
                    >. No new financial entry will be created.
                </p>
                <p v-else class="text-sm text-muted-foreground">
                    Ready for explicit selection. Authoritative commit-time
                    checks may still reject the complete batch.
                </p>
                <details>
                    <summary class="cursor-pointer text-sm underline">
                        Resolve explicit relationships or inspect source
                    </summary>
                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <div>
                            <Label :for="'type-' + row.number"
                                >Transaction type</Label
                            ><select
                                :id="'type-' + row.number"
                                :value="
                                    resolutions[row.number]?.type ??
                                    row.data?.kind ??
                                    ''
                                "
                                :disabled="locked"
                                class="mt-1 w-full rounded-md border bg-background p-2"
                                @change="
                                    setResolution(row.number, 'type', $event)
                                "
                            >
                                <option value="">Choose explicitly</option>
                                <option
                                    v-for="kind in kinds"
                                    :key="kind"
                                    :value="kind"
                                >
                                    {{ kind }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <Label :for="'counterparty-' + row.number"
                                >Transfer counterparty</Label
                            ><select
                                :id="'counterparty-' + row.number"
                                :value="
                                    resolutions[row.number]
                                        ?.counterparty_account_id ?? ''
                                "
                                :disabled="locked"
                                class="mt-1 w-full rounded-md border bg-background p-2"
                                @change="
                                    setResolution(
                                        row.number,
                                        'counterparty_account_id',
                                        $event,
                                    )
                                "
                            >
                                <option value="">
                                    Use supplied CSV reference
                                </option>
                                <option
                                    v-for="a in accounts.filter(
                                        (a) =>
                                            a.id !== preview.account_id &&
                                            a.currency === preview.currency,
                                    )"
                                    :key="a.id"
                                    :value="a.id"
                                >
                                    {{ a.name }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <Label :for="'operation-' + row.number"
                                >Stable transfer / trade reference</Label
                            ><Input
                                :id="'operation-' + row.number"
                                :model-value="
                                    resolutions[row.number]
                                        ?.operation_reference ??
                                    row.data?.operation_reference ??
                                    ''
                                "
                                :disabled="locked"
                                maxlength="180"
                                @input="
                                    setResolution(
                                        row.number,
                                        'operation_reference',
                                        $event,
                                    )
                                "
                            />
                        </div>
                        <div>
                            <Label :for="'asset-' + row.number"
                                >Investment asset</Label
                            ><select
                                :id="'asset-' + row.number"
                                :value="resolutions[row.number]?.asset_id ?? ''"
                                :disabled="locked"
                                class="mt-1 w-full rounded-md border bg-background p-2"
                                @change="
                                    setResolution(
                                        row.number,
                                        'asset_id',
                                        $event,
                                    )
                                "
                            >
                                <option value="">
                                    Use supplied CSV/default asset
                                </option>
                                <option
                                    v-for="a in assets.filter(
                                        (a) => a.currency === preview.currency,
                                    )"
                                    :key="a.id"
                                    :value="a.id"
                                >
                                    {{ a.name }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <Label :for="'match-' + row.number"
                                >Existing transfer or investment receipt</Label
                            ><select
                                :id="'match-' + row.number"
                                :value="resolutions[row.number]?.match_id ?? ''"
                                :disabled="locked"
                                class="mt-1 w-full rounded-md border bg-background p-2"
                                @change="
                                    setResolution(
                                        row.number,
                                        'match_id',
                                        $event,
                                    )
                                "
                            >
                                <option value="">
                                    Use supplied CSV reference
                                </option>
                                <optgroup label="Recent transfers">
                                    <option
                                        v-for="r in transfers"
                                        :key="'j' + r.id"
                                        :value="r.id"
                                    >
                                        Transfer · {{ r.label }}
                                    </option>
                                </optgroup>
                                <optgroup label="Recent investment events">
                                    <option
                                        v-for="r in events"
                                        :key="'e' + r.id"
                                        :value="r.id"
                                    >
                                        Event · {{ r.label }}
                                    </option>
                                </optgroup>
                            </select>
                            <p class="mt-1 text-xs text-muted-foreground">
                                Choose match_transfer or match_investment above.
                                Account, date, currency and exact net cash must
                                agree; no candidate is automatically selected.
                            </p>
                        </div>
                        <div>
                            <Label :for="'direction-' + row.number"
                                >Matched cash direction</Label
                            ><select
                                :id="'direction-' + row.number"
                                :value="
                                    resolutions[row.number]?.direction ?? ''
                                "
                                :disabled="locked"
                                class="mt-1 w-full rounded-md border bg-background p-2"
                                @change="
                                    setResolution(
                                        row.number,
                                        'direction',
                                        $event,
                                    )
                                "
                            >
                                <option value="">
                                    Use supplied CSV direction
                                </option>
                                <option value="in">Inflow</option>
                                <option value="out">Outflow</option>
                            </select>
                        </div>
                        <div class="sm:col-span-2">
                            <Label :for="'provider-' + row.number"
                                >Retained provider transaction ID</Label
                            >
                            <Input
                                :id="'provider-' + row.number"
                                :model-value="
                                    resolutions[row.number]?.provider_id ?? ''
                                "
                                :disabled="locked"
                                maxlength="180"
                                @input="
                                    setResolution(
                                        row.number,
                                        'provider_id',
                                        $event,
                                    )
                                "
                            />
                            <p class="mt-1 text-xs text-muted-foreground">
                                Use the original provider ID only when this row
                                represents that same retained transaction. A
                                changed ID cannot authorize a second posting.
                            </p>
                        </div>
                        <div class="sm:col-span-2">
                            <Label :for="'occurrence-' + row.number"
                                >Persistent label for a genuinely separate
                                repeated occurrence</Label
                            ><Input
                                :id="'occurrence-' + row.number"
                                :model-value="
                                    resolutions[row.number]?.occurrence ?? ''
                                "
                                :disabled="locked"
                                maxlength="180"
                                @input="
                                    setResolution(
                                        row.number,
                                        'occurrence',
                                        $event,
                                    )
                                "
                            />
                            <p class="mt-1 text-xs text-muted-foreground">
                                Supply a stable evidence label only after
                                confirming this is a separate real transaction.
                                Keep that label on later overlapping statements.
                                Relabeling an approved source row cannot post it
                                again.
                            </p>
                        </div>
                    </div>
                    <p class="mt-3 rounded bg-muted p-3 text-xs break-all">
                        Original CSV cells: {{ row.cells.join(' | ') }}
                    </p>
                </details>
            </article>
        </section>
        <nav
            v-if="pageCount > 1"
            aria-label="Preview pages"
            class="flex items-center justify-between gap-3"
        >
            <Button variant="outline" :disabled="page === 0" @click="page--"
                >Previous</Button
            ><span class="text-sm">Page {{ page + 1 }} of {{ pageCount }}</span
            ><Button
                variant="outline"
                :disabled="page + 1 >= pageCount"
                @click="page++"
                >Next</Button
            >
        </nav>
        <Button
            variant="outline"
            :disabled="locked || resolving"
            @click="revise"
            >{{
                resolving ? 'Checking…' : 'Save resolutions as a new preview'
            }}</Button
        >
        <section
            class="space-y-3 rounded-xl border p-5"
            aria-labelledby="approval-heading"
        >
            <h2 id="approval-heading" class="text-lg font-medium">
                Approve selected rows
            </h2>
            <p class="text-sm">
                {{ selected.length }} explicitly selected. Their cash and
                holding effects commit together, or the whole chosen batch rolls
                back. Unselected rows remain available.
            </p>
            <label class="flex items-start gap-3 text-sm"
                ><input
                    v-model="confirmed"
                    type="checkbox"
                    :disabled="locked"
                    class="mt-1 size-4"
                /><span
                    >I checked the selected rows, their transfer/trade
                    relationships and execution order, and approve their
                    financial effects.</span
                ></label
            ><Button
                :disabled="
                    busy || locked || !confirmed || selected.length === 0
                "
                @click="submit"
                >{{
                    busy
                        ? 'Committing…'
                        : 'Approve ' + selected.length + ' selected rows'
                }}</Button
            >
        </section>
        <section v-if="preview.receipts.length">
            <h2 class="mb-2 font-medium">Retained receipts</h2>
            <ul class="space-y-2">
                <li v-for="r in preview.receipts" :key="r.id">
                    <Link
                        :href="'/imports/receipts/' + r.id"
                        class="break-all underline"
                        >{{ r.reference }}</Link
                    >
                </li>
            </ul>
        </section>
    </main>
</template>
