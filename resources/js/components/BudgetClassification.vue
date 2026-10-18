<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import LedgerFeedback from '@/components/LedgerFeedback.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { planningRequestIdentity } from '@/composables/planningRequestIdentity';
import { useLedgerFeedback } from '@/composables/useLedgerFeedback';
import type { BudgetActual, BudgetCategory } from '@/types/budget';
const props = defineProps<{
    actual: BudgetActual;
    categories: BudgetCategory[];
    month: string;
}>();
const open = ref(false);
const page = usePage();
const choices = computed(() =>
    props.categories.filter((c) => c.direction === props.actual.direction),
);
const form = useForm(
    'budget-category-map:' + page.props.auth.user.id + ':' + props.actual.id,
    {
        idempotency_key: crypto.randomUUID(),
        journal_id: props.actual.id,
        category_id: props.actual.category_id ?? '',
        reason: '',
        month: props.month,
    },
);
const identity = planningRequestIdentity(
    'budget-category-map:' + page.props.auth.user.id + ':' + props.actual.id,
    form.idempotency_key,
);
form.defaults('idempotency_key', identity.current);
form.idempotency_key = identity.current;
const feedback = useLedgerFeedback(
    () => form.errors,
    () => form.clearErrors(),
);
const submit = () =>
    form.post(
        '/budgets/classifications',
        feedback.options(() => {
            open.value = false;
            form.idempotency_key = identity.complete();
            form.defaults('idempotency_key', form.idempotency_key);
            form.reset('reason');
        }),
    );
</script>
<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child
            ><Button variant="outline" size="sm" class="min-h-10">{{
                actual.category_id ? 'Revise classification' : 'Classify actual'
            }}</Button></DialogTrigger
        >
        <DialogContent class="max-h-[90dvh] overflow-y-auto">
            <DialogHeader
                ><DialogTitle>Classify posted activity</DialogTitle
                ><DialogDescription
                    >Keep an explicit category version alongside this receipt.
                    Reclassification retains its prior versions and changes
                    fresh historical budget views. Ledger metadata and cash
                    remain unchanged.</DialogDescription
                ></DialogHeader
            >
            <form class="space-y-5" @submit.prevent="submit">
                <LedgerFeedback
                    :errors="form.errors"
                    :uncertain="feedback.uncertain.value"
                    :history="'/budgets?month=' + month"
                />
                <fieldset :disabled="form.processing" class="space-y-5">
                    <div>
                        <Label :for="'classify-category-' + actual.id"
                            >Category version · {{ actual.direction }}</Label
                        ><select
                            :id="'classify-category-' + actual.id"
                            v-model="form.category_id"
                            required
                            class="mt-2 min-h-11 w-full rounded-lg border border-input bg-background px-3 text-sm"
                            :aria-invalid="!!form.errors.category_id"
                            :aria-describedby="
                                'classify-category-error-' + actual.id
                            "
                        >
                            <option value="" disabled>Choose a category</option>
                            <option
                                v-for="category in choices"
                                :key="category.id"
                                :value="category.id"
                            >
                                {{ category.name }} · v{{ category.version }} ·
                                {{
                                    category.normal_budget
                                        ? 'normal budget'
                                        : 'outside normal budget'
                                }}
                            </option></select
                        ><InputError
                            :id="'classify-category-error-' + actual.id"
                            :message="form.errors.category_id"
                        />
                    </div>
                    <div>
                        <Label :for="'classify-reason-' + actual.id"
                            >Reason</Label
                        ><Input
                            :id="'classify-reason-' + actual.id"
                            v-model="form.reason"
                            required
                            maxlength="180"
                            class="mt-2 min-h-11"
                            :aria-invalid="!!form.errors.reason"
                            :aria-describedby="
                                'classify-reason-error-' + actual.id
                            "
                        /><InputError
                            :id="'classify-reason-error-' + actual.id"
                            :message="form.errors.reason"
                        />
                    </div>
                </fieldset>
                <DialogFooter
                    ><Button
                        type="button"
                        variant="outline"
                        :disabled="form.processing"
                        @click="open = false"
                        >Cancel</Button
                    ><Button
                        type="submit"
                        :disabled="form.processing || !choices.length"
                        >{{
                            form.processing ? 'Saving…' : 'Save classification'
                        }}</Button
                    ></DialogFooter
                >
            </form>
        </DialogContent>
    </Dialog>
</template>
