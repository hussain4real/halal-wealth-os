<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    displayAnswerFact,
    parseGroundedAnswer,
} from '@/composables/copilotAnswer';
import type { GroundedAnswer } from '@/composables/copilotAnswer';
import { copilotPreviewOutcome } from '@/composables/copilotPreviewOutcome';
import { importPost } from '@/composables/useStatementRequest';
const props = defineProps<{
    boundary: {
        enabled: boolean;
        consent: boolean;
        generation: string;
        usage: { requests_today: number; reserved_tokens_today: number };
        limits: { requests_per_day: number; reserved_tokens_per_day: number };
    };
    context_choices: {
        goals: { id: string; label: string }[];
        assets: { id: string; label: string }[];
        valuations: { id: string; label: string }[];
    };
    answer_available: boolean;
    model: string;
    context_defaults: { month: string; cutoff: string };
}>();
type Turn =
    | { kind: 'question'; text: string }
    | { kind: 'answer'; answer: GroundedAnswer }
    | { kind: 'notice'; text: string };
type Request = {
    reference: string;
    question: string;
    document: string;
    context: {
        goal_id: string | null;
        valuation_id: string | null;
        asset_id: string | null;
        month: string;
        cutoff: string;
    };
    disclosure_confirmed: true;
};
const turns = ref<Turn[]>([]),
    question = ref(''),
    document = ref(''),
    goal = ref(''),
    valuation = ref(''),
    asset = ref('');
const month = ref(props.context_defaults.month),
    cutoff = ref(props.context_defaults.cutoff),
    disclosure = ref(false),
    pending = ref<Request | null>(null),
    busy = ref(false),
    consentBusy = ref(false),
    feedback = ref(''),
    recovery = ref<'new' | 'unconfirmed'>('new');
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
            : 'Consent was not confirmed. Refresh its state before enabling.';
        router.reload({ only: ['boundary', 'answer_available'] });
    } catch {
        feedback.value =
            'Consent outcome is unconfirmed. Check its current state.';
    } finally {
        consentBusy.value = false;
    }
}
async function send() {
    if (
        busy.value ||
        (!pending.value && (!disclosure.value || !question.value.trim()))
    )
        return;
    if (pending.value === null) {
        pending.value = {
            reference: crypto.randomUUID(),
            question: question.value,
            document: document.value,
            context: {
                goal_id: goal.value || null,
                valuation_id: valuation.value || null,
                asset_id: asset.value || null,
                month: month.value,
                cutoff: cutoff.value,
            },
            disclosure_confirmed: true,
        };
        turns.value.push({ kind: 'question', text: question.value });
    }
    busy.value = true;
    feedback.value = '';
    try {
        const { response, body } = await importPost(
            '/copilot/answer',
            pending.value,
        );
        const result = copilotPreviewOutcome({
            status: response.status,
            body,
            reference: pending.value.reference,
            recovery: recovery.value,
        });
        if (result.kind === 'uncertain') {
            recovery.value = 'unconfirmed';
            feedback.value =
                'Outcome unconfirmed. Keep this exact question and context; check it again when access or usage permits. A reservation may remain.';
            return;
        }
        if (result.kind === 'complete') {
            const answer = parseGroundedAnswer(result.evidence);
            if (answer === null) {
                recovery.value = 'unconfirmed';
                feedback.value =
                    'Answer receipt could not be verified. Check this unchanged request.';
                return;
            }
            turns.value.push({ kind: 'answer', answer });
            feedback.value =
                'Evidence answer ready. Review the recorded figures, dates and sources above.';
        } else {
            const text =
                result.kind === 'already_charged'
                    ? 'This request was already admitted and will not execute again. Its answer was not retained; the reservation remains.'
                    : result.kind === 'limited'
                      ? 'Usage limit reached. Core financial pages remain available.'
                      : result.kind === 'rejected'
                        ? 'Request rejected. Check consent, selected owned record IDs and required fields.'
                        : 'Answer provider or evidence unavailable. No financial action occurred.';
            turns.value.push({ kind: 'notice', text });
            feedback.value = text;
        }
        pending.value = null;
        recovery.value = 'new';
        question.value = '';
        document.value = '';
        router.reload({ only: ['boundary', 'answer_available'] });
    } catch {
        recovery.value = 'unconfirmed';
        feedback.value =
            'Connection interrupted. Check the unchanged request; do not create a replacement while its outcome is unknown.';
    } finally {
        busy.value = false;
    }
}
</script>
<template>
    <Head title="Evidence-grounded chat" />
    <div class="mx-auto w-full max-w-5xl min-w-0 space-y-6 px-4 py-8 sm:px-8">
        <a href="/copilot" class="inline-flex min-h-11 items-center underline"
            >← Read-only evidence tools</a
        >
        <header class="space-y-2">
            <p class="text-sm font-semibold text-teal-800 dark:text-teal-200">
                Private financial evidence
            </p>
            <h1 class="text-3xl font-semibold">
                Ask about your recorded finances
            </h1>
            <p class="text-muted-foreground">
                Exact figures and dated sources, with no trades, payments or
                religious certification.
            </p>
        </header>
        <section
            aria-labelledby="chat-availability"
            class="space-y-3 rounded-xl border bg-card p-5"
        >
            <h2 id="chat-availability" class="text-lg font-semibold">
                {{
                    answer_available
                        ? 'Answer planner available'
                        : 'Answer planner unavailable'
                }}
            </h2>
            <p class="text-sm break-words">
                Configured model: {{ model }}. The provider is disabled by
                default; your core financial pages work independently.
            </p>
            <p class="text-sm">
                The planner receives only the question and optional quoted
                document you enter, which may themselves contain personal
                information. Trusted account records and selected IDs stay
                inside the app. Figures and source links are produced by
                application services.
            </p>
            <p class="text-sm">
                Consent is {{ boundary.consent ? 'on' : 'off' }}.
                <Button
                    v-if="boundary.consent"
                    :disabled="consentBusy"
                    @click="consent(false)"
                    >Revoke consent</Button
                ><Button
                    v-else
                    :disabled="!boundary.enabled || consentBusy"
                    @click="consent(true)"
                    >Allow read-only questions</Button
                >
            </p>
            <p class="text-sm">
                Today (UTC): {{ boundary.usage.requests_today }} /
                {{ boundary.limits.requests_per_day }} requests;
                {{ boundary.usage.reserved_tokens_today }} /
                {{ boundary.limits.reserved_tokens_per_day }} reserved token
                ceiling.
            </p>
        </section>
        <section
            aria-labelledby="context-title"
            class="space-y-3 rounded-xl border bg-card p-5"
        >
            <h2 id="context-title" class="text-lg font-semibold">
                Explicit source context
            </h2>
            <p class="text-sm text-muted-foreground">
                Choose a named source from the suggestions, or enter its
                reference ID from the core page. Suggestions show at most100
                recent records. Selecting a goal here explicitly designates it
                as this question’s emergency context. Cash and goal funding are
                current; the month only selects essential-expense context. Saved
                valuations retain their own dates; the cutoff applies to
                assessments.
            </p>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <Label for="chat-goal">Selected emergency goal</Label
                    ><Input
                        id="chat-goal"
                        list="chat-goals-options"
                        v-model="goal"
                        :disabled="pending !== null"
                    />
                    <datalist id="chat-goals-options">
                        <option
                            v-for="source in context_choices.goals"
                            :key="source.id"
                            :value="source.id"
                        >
                            {{ source.label }}
                        </option>
                    </datalist>
                </div>
                <div>
                    <Label for="chat-valuation">Saved valuation</Label
                    ><Input
                        id="chat-valuation"
                        list="chat-valuations-options"
                        v-model="valuation"
                        :disabled="pending !== null"
                    />
                    <datalist id="chat-valuations-options">
                        <option
                            v-for="source in context_choices.valuations"
                            :key="source.id"
                            :value="source.id"
                        >
                            {{ source.label }}
                        </option>
                    </datalist>
                </div>
                <div>
                    <Label for="chat-asset">Assessment asset</Label
                    ><Input
                        id="chat-asset"
                        list="chat-assets-options"
                        v-model="asset"
                        :disabled="pending !== null"
                    />
                    <datalist id="chat-assets-options">
                        <option
                            v-for="source in context_choices.assets"
                            :key="source.id"
                            :value="source.id"
                        >
                            {{ source.label }}
                        </option>
                    </datalist>
                </div>
                <div>
                    <Label for="chat-month">Essential-expense month</Label
                    ><Input
                        id="chat-month"
                        v-model="month"
                        type="month"
                        :disabled="pending !== null"
                    />
                </div>
                <div class="sm:col-span-2">
                    <Label for="chat-cutoff">Assessment cutoff (UTC)</Label
                    ><Input
                        id="chat-cutoff"
                        v-model="cutoff"
                        :disabled="pending !== null"
                    />
                </div>
            </div>
        </section>
        <section
            aria-labelledby="conversation-title"
            class="space-y-4 rounded-xl border bg-card p-5"
        >
            <h2 id="conversation-title" class="text-lg font-semibold">
                Conversation
            </h2>
            <p v-if="turns.length === 0" class="text-muted-foreground">
                Ask one question about available cash, your selected emergency
                goal, Nigeria geography, a saved investment comparison or
                recorded assessment reviews.
            </p>
            <ol class="space-y-5">
                <li
                    v-for="(turn, index) in turns"
                    :key="index"
                    class="min-w-0 space-y-3 rounded-lg bg-muted p-4"
                >
                    <template v-if="turn.kind === 'question'"
                        ><h3 class="font-semibold">Your question</h3>
                        <p class="break-words whitespace-pre-wrap">
                            {{ turn.text }}
                        </p></template
                    ><template v-else-if="turn.kind === 'notice'"
                        ><p>{{ turn.text }}</p></template
                    ><template v-else
                        ><h3 class="font-semibold">
                            {{
                                turn.answer.mode === 'hypothetical'
                                    ? 'Hypothetical request · recorded baseline only'
                                    : 'Recorded evidence'
                            }}
                        </h3>
                        <p>{{ turn.answer.text }}</p>
                        <p class="text-sm break-words text-foreground">
                            {{ turn.answer.claim }} · {{ turn.answer.state }} ·
                            as of
                            {{ turn.answer.as_of ?? 'no financial capture' }} ·
                            captured
                            {{ turn.answer.captured_at ?? 'not applicable' }} ·
                            {{ turn.answer.completeness ?? 'not applicable' }}
                        </p>
                        <dl class="grid gap-3 sm:grid-cols-2">
                            <div
                                v-for="fact in turn.answer.facts"
                                :key="fact.key"
                                class="min-w-0 rounded border bg-background p-3"
                            >
                                <dt class="text-sm text-muted-foreground">
                                    {{ fact.label }}
                                </dt>
                                <dd
                                    class="mt-1 overflow-auto break-words whitespace-pre-wrap"
                                >
                                    {{ displayAnswerFact(fact) }}
                                </dd>
                            </div>
                        </dl>
                        <ul class="space-y-1 text-sm">
                            <li
                                v-for="(limitation, i) in turn.answer
                                    .limitations"
                                :key="i"
                            >
                                {{ limitation }}
                            </li>
                        </ul>
                        <ul class="flex flex-wrap gap-3 text-sm">
                            <li
                                v-for="citation in turn.answer.citations"
                                :key="citation.href"
                            >
                                <a
                                    :href="citation.href"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex min-h-11 items-center underline"
                                    >{{ citation.label }} ·
                                    {{ citation.record_id }}</a
                                >
                            </li>
                        </ul>
                        <p class="text-xs text-foreground">
                            {{ turn.answer.scope }}
                        </p></template
                    >
                </li>
            </ol>
        </section>
        <form
            class="space-y-4 rounded-xl border bg-card p-5"
            @submit.prevent="send"
        >
            <div>
                <Label for="chat-question">Question</Label
                ><textarea
                    id="chat-question"
                    v-model="question"
                    required
                    maxlength="2048"
                    :disabled="pending !== null"
                    rows="3"
                    class="mt-2 w-full rounded-md border bg-background p-3"
                />
            </div>
            <details>
                <summary class="cursor-pointer text-sm underline">
                    Optional untrusted quoted document
                </summary>
                <Label for="chat-document" class="mt-3 block"
                    >Quoted document (combined question/document limit: 2048
                    bytes)</Label
                ><textarea
                    id="chat-document"
                    v-model="document"
                    :disabled="pending !== null"
                    rows="3"
                    class="mt-2 w-full rounded-md border bg-background p-3"
                />
            </details>
            <label class="flex items-start gap-3 text-sm"
                ><input
                    v-model="disclosure"
                    type="checkbox"
                    :disabled="pending !== null"
                    class="mt-1"
                /><span
                    >I allow questions and documents I send from this page to go
                    to the configured planner. It cannot authorize financial
                    actions; avoid personal information. Source records stay
                    inside this app.</span
                ></label
            ><Button
                :disabled="
                    busy ||
                    !boundary.enabled ||
                    !boundary.consent ||
                    (!pending && !answer_available) ||
                    (!pending && !disclosure)
                "
                >{{
                    busy
                        ? 'Checking…'
                        : pending
                          ? 'Check unchanged request'
                          : 'Ask with evidence'
                }}</Button
            >
            <p role="status" aria-live="polite" class="text-sm break-words">
                {{ feedback }}
            </p>
            <p class="text-xs text-foreground">
                Messages stay only in this page’s memory. Reload clears the
                conversation; redacted request status and reservations remain.
                No automatic resend occurs.
            </p>
        </form>
    </div>
</template>
