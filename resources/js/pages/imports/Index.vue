<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    importErrors,
    importObject,
    importPost,
    importUrl,
} from '@/composables/useStatementRequest';
import type { ImportChoice, ImportMapping } from '@/types/imports';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Statement imports', href: '/imports' }] },
});
const props = defineProps<{
    fields: string[];
    kinds: string[];
    accounts: ImportChoice[];
    assets: ImportChoice[];
    adapters: { id: string; name: string; config: ImportMapping }[];
    previews: { id: string; reference: string; namespace: string }[];
}>();
const mapping = ref<ImportMapping>({
    version: 1,
    delimiter: ',',
    date_format: 'Y-m-d',
    decimal_separator: '.',
    columns: Object.fromEntries(props.fields.map((field) => [field, ''])),
    type_map: [],
    currency_map: [],
    default_asset_id: '',
});
const account = ref('');
const namespace = ref('');
const adapterName = ref('My statement mapping');
const file = ref<File | null>(null);
const headers = ref<string[]>([]);
const rowCount = ref(0);
const busy = ref(false);
const errors = ref<string[]>([]);
const chosen = ref('');
const sourceType = ref('');
const targetType = ref('income');
const native = computed(
    () => props.accounts.find((a) => a.id === account.value)?.currency ?? '',
);
const label = (field: string) => field.replaceAll('_', ' ');
const selectFile = (event: Event) => {
    const target = event.target;
    file.value =
        target instanceof HTMLInputElement ? (target.files?.[0] ?? null) : null;
    headers.value = [];
};
const reuse = () => {
    const saved = props.adapters.find((a) => a.id === chosen.value);
    if (saved) {
        mapping.value = {
            ...saved.config,
            columns: { ...saved.config.columns },
            type_map: saved.config.type_map.map((entry) => ({ ...entry })),
            currency_map: saved.config.currency_map.map((entry) => ({
                ...entry,
            })),
        };
        adapterName.value = saved.name;
    }
};
const inspect = async () => {
    if (!file.value || busy.value) return;
    busy.value = true;
    errors.value = [];
    try {
        const data = new FormData();
        data.append('file', file.value);
        data.append(
            'delimiter',
            mapping.value.delimiter === '\t' ? 'tab' : mapping.value.delimiter,
        );
        const result = await importPost('/imports/inspect', data);
        if (
            !result.response.ok ||
            !importObject(result.body) ||
            !Array.isArray(result.body.headers) ||
            !result.body.headers.every(
                (h): h is string => typeof h === 'string',
            )
        )
            errors.value = importErrors(result.body);
        else {
            headers.value = result.body.headers;
            rowCount.value =
                typeof result.body.row_count === 'number'
                    ? result.body.row_count
                    : 0;
            for (const field of props.fields)
                if (
                    !mapping.value.columns[field] &&
                    headers.value.includes(field)
                )
                    mapping.value.columns[field] = field;
        }
    } catch {
        errors.value = [
            'Could not read the file. Keep it selected and try again.',
        ];
    } finally {
        busy.value = false;
        document.querySelector<HTMLElement>('[data-import-feedback]')?.focus();
    }
};
const preview = async () => {
    if (!file.value || busy.value) return;
    busy.value = true;
    errors.value = [];
    try {
        const data = new FormData();
        data.append('file', file.value);
        data.append('namespace', namespace.value);
        data.append('account_id', account.value);
        data.append('adapter_name', adapterName.value);
        data.append('mapping', JSON.stringify(mapping.value));
        const { response, body } = await importPost('/imports', data);
        const url = importUrl(body);
        if (response.ok && url) router.visit(url);
        else errors.value = importErrors(body);
    } catch {
        errors.value = [
            'Preview response interrupted. Creating the same preview again is safe; no financial approval was sent.',
        ];
    } finally {
        busy.value = false;
        document.querySelector<HTMLElement>('[data-import-feedback]')?.focus();
    }
};
const addType = () => {
    if (!sourceType.value.trim()) return;
    mapping.value.type_map = [
        ...mapping.value.type_map.filter((m) => m.value !== sourceType.value),
        { value: sourceType.value, target: targetType.value },
    ];
    sourceType.value = '';
};
</script>

<template>
    <Head title="Statement imports" />
    <main class="mx-auto w-full max-w-5xl space-y-6 p-4 md:p-8">
        <header>
            <h1 class="text-2xl font-semibold">Statement imports</h1>
            <p class="mt-2 text-sm text-muted-foreground">
                Upload, map and review before approving any financial entries.
                Your original source and every receipt remain private and
                retained.
            </p>
        </header>
        <div
            tabindex="-1"
            data-import-feedback
            aria-live="polite"
            class="outline-none"
        >
            <p
                v-for="error in errors"
                :key="error"
                class="mb-2 rounded border border-destructive p-3 text-destructive"
            >
                {{ error }}
            </p>
        </div>
        <section
            class="space-y-4 rounded-xl border p-5"
            aria-labelledby="source-heading"
        >
            <h2 id="source-heading" class="text-lg font-medium">
                1. Choose your source
            </h2>
            <p v-if="accounts.length === 0" class="text-sm">
                Create an
                <Link href="/accounts/new" class="underline">account</Link>
                before importing its statement.
            </p>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <Label for="import-account">Statement account</Label
                    ><select
                        id="import-account"
                        v-model="account"
                        class="mt-1 w-full rounded-md border bg-background p-2"
                    >
                        <option value="">Choose account</option>
                        <option v-for="a in accounts" :key="a.id" :value="a.id">
                            {{ a.name }} · {{ a.currency }}
                        </option>
                    </select>
                </div>
                <div>
                    <Label for="import-source">Statement source</Label
                    ><Input
                        id="import-source"
                        v-model="namespace"
                        maxlength="180"
                        placeholder="Bank or broker account stream"
                    />
                    <p class="mt-1 text-xs text-muted-foreground">
                        Reuse the same source name for overlapping statements
                        from this account. Renaming it changes its identity
                        namespace.
                    </p>
                </div>
                <div>
                    <Label for="import-file">CSV file</Label
                    ><Input
                        id="import-file"
                        type="file"
                        accept=".csv,text/csv"
                        @change="selectFile"
                    />
                    <p class="mt-1 text-xs text-muted-foreground">
                        UTF8, up to256KiB,1000 rows,32 columns. Dates and
                        amounts need explicit formats.
                    </p>
                </div>
                <div>
                    <Label for="import-adapter">Reuse a saved mapping</Label
                    ><select
                        id="import-adapter"
                        v-model="chosen"
                        class="mt-1 w-full rounded-md border bg-background p-2"
                        @change="reuse"
                    >
                        <option value="">New mapping</option>
                        <option v-for="a in adapters" :key="a.id" :value="a.id">
                            {{ a.name }} · {{ a.id }}
                        </option>
                    </select>
                </div>
                <div>
                    <Label for="import-delimiter">Column separator</Label
                    ><select
                        id="import-delimiter"
                        v-model="mapping.delimiter"
                        class="mt-1 w-full rounded-md border bg-background p-2"
                        @change="headers = []"
                    >
                        <option value=",">Comma</option>
                        <option value=";">Semicolon</option>
                        <option :value="'\t'">Tab</option>
                    </select>
                </div>
            </div>
            <Button :disabled="!file || busy" @click="inspect">{{
                busy ? 'Reading…' : 'Read CSV headers'
            }}</Button>
        </section>
        <form
            v-if="headers.length"
            class="space-y-5 rounded-xl border p-5"
            @submit.prevent="preview"
        >
            <h2 class="text-lg font-medium">2. Map {{ rowCount }} rows</h2>
            <p class="text-sm text-muted-foreground">
                Review each mapping. Amount is a positive magnitude; type
                records direction. Trades use gross consideration, separate
                fees/tax and exact units. Transfer references group explicit
                legs; missing relationships stay unresolved.
            </p>
            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <Label for="mapping-name">Mapping name</Label
                    ><Input
                        id="mapping-name"
                        v-model="adapterName"
                        maxlength="180"
                        required
                    />
                </div>
                <div>
                    <Label for="date-format">Date format</Label
                    ><select
                        id="date-format"
                        v-model="mapping.date_format"
                        class="mt-1 w-full rounded-md border bg-background p-2"
                    >
                        <option value="Y-m-d">Year-month-day</option>
                        <option value="d/m/Y">Day/month/year</option>
                        <option value="m/d/Y">Month/day/year</option>
                    </select>
                </div>
                <div>
                    <Label for="decimal-format"
                        >Decimal separator (no grouping)</Label
                    ><select
                        id="decimal-format"
                        v-model="mapping.decimal_separator"
                        class="mt-1 w-full rounded-md border bg-background p-2"
                    >
                        <option value=".">Point:12.34</option>
                        <option value=",">Comma:12,34</option>
                    </select>
                </div>
            </div>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="field in fields" :key="field">
                    <Label :for="'column-' + field" class="capitalize"
                        >{{ label(field)
                        }}{{
                            ['date', 'type', 'amount', 'description'].includes(
                                field,
                            )
                                ? ' *'
                                : ''
                        }}</Label
                    ><select
                        :id="'column-' + field"
                        v-model="mapping.columns[field]"
                        class="mt-1 w-full rounded-md border bg-background p-2"
                    >
                        <option value="">
                            {{
                                field === 'currency'
                                    ? 'Account currency: ' + native
                                    : 'Not supplied'
                            }}
                        </option>
                        <option
                            v-for="header in headers"
                            :key="header"
                            :value="header"
                        >
                            {{ header }}
                        </option>
                    </select>
                </div>
            </div>
            <details class="rounded-lg border p-3">
                <summary class="cursor-pointer font-medium">
                    Transaction labels and optional asset default
                </summary>
                <p class="my-3 text-sm">
                    Canonical types: {{ kinds.join(', ') }}. Map other statement
                    labels explicitly. Existing matches also require a receipt
                    number and in/out direction. ISO currencies are required;
                    they must match {{ native || 'the selected account' }}.
                </p>
                <div class="flex flex-wrap gap-2">
                    <Input
                        v-model="sourceType"
                        aria-label="Source transaction label"
                        placeholder="Statement label"
                        class="max-w-64"
                    /><select
                        v-model="targetType"
                        aria-label="Mapped transaction type"
                        class="rounded-md border bg-background p-2"
                    >
                        <option v-for="kind in kinds" :key="kind" :value="kind">
                            {{ kind }}
                        </option></select
                    ><Button type="button" variant="outline" @click="addType"
                        >Add label mapping</Button
                    >
                </div>
                <ul class="my-3 space-y-1 text-sm">
                    <li v-for="entry in mapping.type_map" :key="entry.value">
                        {{ entry.value }} → {{ entry.target }}
                        <button
                            type="button"
                            class="ml-2 underline"
                            @click="
                                mapping.type_map = mapping.type_map.filter(
                                    (m) => m.value !== entry.value,
                                )
                            "
                        >
                            Remove
                        </button>
                    </li>
                </ul>
                <Label for="default-asset"
                    >Asset default for an investment-only statement</Label
                ><select
                    id="default-asset"
                    v-model="mapping.default_asset_id"
                    class="mt-1 w-full rounded-md border bg-background p-2"
                >
                    <option value="">
                        No default; resolve each investment row
                    </option>
                    <option
                        v-for="a in assets.filter((a) => a.currency === native)"
                        :key="a.id"
                        :value="a.id"
                    >
                        {{ a.name }}
                    </option>
                </select>
            </details>
            <Button :disabled="busy || !account || !namespace || !file">{{
                busy ? 'Preparing preview…' : 'Create private dry-run preview'
            }}</Button>
            <p class="text-xs text-muted-foreground">
                This saves source evidence and mapping only. Approval comes
                after row review.
            </p>
        </form>
        <section v-if="previews.length" class="space-y-3">
            <h2 class="text-lg font-medium">Recent previews</h2>
            <ul class="divide-y rounded-xl border">
                <li v-for="p in previews" :key="p.id" class="p-4">
                    <Link
                        :href="'/imports/' + p.id"
                        class="block break-all underline"
                        >{{ p.namespace }} · {{ p.reference }}</Link
                    >
                </li>
            </ul>
        </section>
    </main>
</template>
