<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { copilotPreviewOutcome } from '@/composables/copilotPreviewOutcome';
import { importPost } from '@/composables/useStatementRequest';

type RegistryEntry = {
    name: string;
    parameters: {
        properties: Record<string, { type: string }>;
        required: string[];
    };
};
const props = defineProps<{
    boundary: {
        enabled: boolean;
        provider_available: boolean;
        consent: boolean;
        generation: string;
        registry: RegistryEntry[];
        usage: {
            requests_today: number;
            reserved_tokens_today: number;
            retained: number;
        };
        limits: {
            requests_per_day: number;
            reserved_tokens_per_day: number;
            retained_requests: number;
        };
        history: {
            reference: string;
            lane: string;
            tool: string | null;
            status: string;
            recorded_at: string;
        }[];
    };
}>();
const labels: Record<string, string> = {
    cash_availability: 'Cash availability',
    goals: 'Goals',
    holdings: 'Holdings',
    valuation_evidence: 'Saved valuation',
    shariah_history: 'Shariah assessment history',
    month: 'Planning month',
    before: 'Next page record ID (optional)',
    portfolio_id: 'Portfolio record ID',
    asset_id: 'Asset record ID',
    run_id: 'Saved valuation record ID',
    cutoff: 'Evidence cutoff (UTC)',
};
const selected = ref('cash_availability');
const fields = ref<Record<string, string>>({});
const entry = computed(() =>
    props.boundary.registry.find((r) => r.name === selected.value),
);
const busy = ref(false);
const feedback = ref('');
const result = ref('');
const pending = ref<null | {
    reference: string;
    tool: string;
    arguments: Record<string, string>;
}>(null);
const recovery = ref<'new' | 'unconfirmed'>('new');
const consentBusy = ref(false);
async function consent(enabled: boolean) {
    if (consentBusy.value) return;
    consentBusy.value = true;
    try {
        const { response } = await importPost('/copilot/consent', {
            enabled,
            generation: props.boundary.generation,
        });
        feedback.value = response.ok
            ? enabled
                ? 'Consent enabled.'
                : 'Consent revoked.'
            : 'Consent could not be saved. Refresh and review its current state.';
        router.reload({ only: ['boundary'] });
    } catch {
        feedback.value =
            'Connection interrupted. Refresh to confirm the current consent state.';
    } finally {
        consentBusy.value = false;
    }
}
async function preview() {
    if (busy.value || !entry.value) return;
    const argumentsForTool: Record<string, string> = {};
    for (const name of Object.keys(entry.value.parameters.properties)) {
        const value = fields.value[name]?.trim();
        if (value) argumentsForTool[name] = value;
    }
    pending.value ??= {
        reference: crypto.randomUUID(),
        tool: selected.value,
        arguments: argumentsForTool,
    };
    busy.value = true;
    result.value = '';
    feedback.value = '';
    try {
        const { response, body } = await importPost(
            '/copilot/preview',
            pending.value,
        );
        const outcome = copilotPreviewOutcome({
            status: response.status,
            body,
            reference: pending.value.reference,
            recovery: recovery.value,
        });
        switch (outcome.kind) {
            case 'complete':
                result.value = JSON.stringify(outcome.evidence, null, 2);
                feedback.value =
                    'Read-only evidence retrieved. Check its date, completeness and source records.';
                break;
            case 'already_charged':
                feedback.value =
                    'This request was already admitted. It will not run again. Check usage, then start a new preview if needed.';
                break;
            case 'unavailable':
                feedback.value =
                    'Evidence is unavailable for this selection or exceeds the disclosure limit. Use the core financial page for full details.';
                break;
            case 'limited':
                feedback.value =
                    'Usage limit reached. Core financial pages remain available.';
                break;
            case 'rejected':
                feedback.value =
                    'The preview was rejected. Check consent, owned record IDs, dates and required fields.';
                break;
            case 'uncertain':
                feedback.value =
                    'The outcome could not be confirmed. Keep this unchanged request and check it again when access or usage limits permit. A reserved charge may remain.';
                break;
            default: {
                const exhaustive: never = outcome;
                throw new Error(String(exhaustive));
            }
        }
        if (outcome.kind === 'uncertain') recovery.value = 'unconfirmed';
        else {
            pending.value = null;
            recovery.value = 'new';
            router.reload({ only: ['boundary'] });
        }
    } catch {
        recovery.value = 'unconfirmed';
        feedback.value =
            'Connection interrupted. Retry this unchanged request to check its outcome. A reserved charge may remain.';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Head title="Read-only copilot" />
    <div class="mx-auto w-full max-w-5xl px-4 py-8 sm:px-8 sm:py-12">
        <a
            href="/dashboard"
            class="inline-flex min-h-11 items-center text-sm underline"
            >← Dashboard</a
        >
        <header class="mt-4 space-y-3">
            <p
                class="text-xs font-semibold tracking-wider text-teal-800 uppercase dark:text-teal-200"
            >
                Private financial evidence
            </p>
            <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">
                Read-only copilot
            </h1>
            <p class="max-w-2xl text-muted-foreground">
                Financial evidence stays grounded in your records. No trades,
                payments or financial edits.
            </p>
        </header>
        <div class="mx-auto w-full max-w-4xl space-y-6 p-4 sm:p-6">
            <section
                aria-labelledby="availability"
                class="rounded-xl border bg-card p-5"
            >
                <h2 id="availability" class="text-lg font-semibold">
                    Provider
                    {{
                        boundary.provider_available
                            ? 'available'
                            : 'unavailable'
                    }}
                </h2>
                <p class="mt-2 text-sm text-muted-foreground">
                    {{
                        boundary.enabled
                            ? 'The read-only evidence boundary is enabled.'
                            : 'The copilot is disabled by default.'
                    }}
                    No live model is connected. Answer generation is a later
                    phase.
                </p>
                <p class="mt-2 text-sm">
                    Your dashboard, accounts and reports work independently of
                    this optional feature.
                </p>
                <a href="/dashboard" class="mt-3 inline-block underline"
                    >Open dashboard</a
                >
            </section>
            <section
                aria-labelledby="consent-title"
                class="space-y-3 rounded-xl border bg-card p-5"
            >
                <h2 id="consent-title" class="text-lg font-semibold">
                    Your consent
                </h2>
                <p class="text-sm">
                    {{
                        boundary.consent ? 'Consent is on.' : 'Consent is off.'
                    }}
                    When enabled, this boundary may read your selected financial
                    evidence. A future approved provider may receive your
                    question. Consent never permits financial actions.
                </p>
                <p class="text-sm text-muted-foreground">
                    Request history stores status and reserved usage only.
                    Questions, documents, financial results and raw provider
                    errors are not retained in traces.
                </p>
                <div class="flex flex-wrap gap-3">
                    <Button
                        v-if="!boundary.consent"
                        :disabled="!boundary.enabled || consentBusy"
                        @click="consent(true)"
                        >Allow read-only evidence</Button
                    >
                    <Button
                        v-else
                        variant="outline"
                        :disabled="consentBusy"
                        @click="consent(false)"
                        >Revoke consent</Button
                    >
                </div>
            </section>
            <section
                aria-labelledby="preview-title"
                class="space-y-4 rounded-xl border bg-card p-5"
            >
                <h2 id="preview-title" class="text-lg font-semibold">
                    Preview your evidence
                </h2>
                <p class="text-sm text-muted-foreground">
                    These previews read your records directly and do not contact
                    a model. IDs are shown on the relevant core financial pages.
                    Saved valuations retain their original cutoff.
                </p>
                <form class="space-y-4" @submit.prevent="preview">
                    <div>
                        <Label for="evidence-tool">Evidence to read</Label>
                        <select
                            id="evidence-tool"
                            v-model="selected"
                            :disabled="busy || pending !== null"
                            class="mt-2 block w-full rounded-md border bg-background p-2"
                        >
                            <option
                                v-for="tool in boundary.registry"
                                :key="tool.name"
                                :value="tool.name"
                            >
                                {{ labels[tool.name] ?? tool.name }}
                            </option>
                        </select>
                    </div>
                    <div
                        v-for="(_, name) in entry?.parameters.properties ?? {}"
                        :key="name"
                    >
                        <Label :for="String(name)">{{
                            labels[name] ?? name
                        }}</Label>
                        <Input
                            :id="String(name)"
                            v-model="fields[name]"
                            :disabled="busy || pending !== null"
                            :required="
                                entry?.parameters.required.includes(
                                    String(name),
                                )
                            "
                            :placeholder="
                                name === 'cutoff'
                                    ? '2026-10-24T12:00:00Z'
                                    : name === 'month'
                                      ? '2026-10'
                                      : ''
                            "
                            class="mt-2"
                        />
                    </div>
                    <Button
                        :disabled="
                            !boundary.enabled || !boundary.consent || busy
                        "
                        >{{
                            busy
                                ? 'Reading…'
                                : pending
                                  ? 'Check unchanged request'
                                  : 'Read evidence'
                        }}</Button
                    >
                </form>
                <p role="status" aria-live="polite" class="text-sm break-words">
                    {{ feedback }}
                </p>
                <pre
                    v-if="result"
                    aria-label="Structured financial evidence"
                    tabindex="0"
                    class="max-h-[32rem] overflow-auto rounded border bg-muted p-3 text-xs break-words whitespace-pre-wrap"
                    >{{ result }}</pre>
            </section>
            <section
                aria-labelledby="usage-title"
                class="space-y-2 rounded-xl border bg-card p-5"
            >
                <h2 id="usage-title" class="text-lg font-semibold">
                    Usage and recovery
                </h2>
                <p class="text-sm">
                    Today (UTC): {{ boundary.usage.requests_today }} /
                    {{ boundary.limits.requests_per_day }} requests. Reserved
                    token ceiling: {{ boundary.usage.reserved_tokens_today }} /
                    {{ boundary.limits.reserved_tokens_per_day }}.
                </p>
                <p class="text-sm text-muted-foreground">
                    Reservations are conservative limits, not measured model
                    usage. Failed or interrupted requests stay charged and are
                    not automatically resent. Retained history:
                    {{ boundary.usage.retained }} /
                    {{ boundary.limits.retained_requests }}. Consent can always
                    be revoked.
                </p>
                <p v-if="!boundary.history.length" class="text-sm">
                    No requests recorded.
                </p>
                <ul v-else class="space-y-2 text-sm">
                    <li
                        v-for="request in boundary.history"
                        :key="request.reference"
                        class="border-t pt-2 break-words"
                    >
                        {{ request.status }} ·
                        {{
                            request.tool
                                ? labels[request.tool]
                                : 'Provider selection'
                        }}
                        · {{ request.recorded_at }}
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>
