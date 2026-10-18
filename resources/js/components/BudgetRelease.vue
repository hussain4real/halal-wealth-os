<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
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
import type { BudgetAllocation } from '@/types/budget';
const props = defineProps<{ allocation: BudgetAllocation; month: string }>();
const page = usePage();
const open = ref(false);
const form = useForm(
    'budget-release:' + page.props.auth.user.id + ':' + props.allocation.id,
    {
        idempotency_key: crypto.randomUUID(),
        reason: '',
        month: props.month,
    },
);
const identity = planningRequestIdentity(
    'budget-release:' + page.props.auth.user.id + ':' + props.allocation.id,
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
        '/budgets/allocations/' + props.allocation.id + '/release',
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
            ><Button variant="ghost" size="sm" class="min-h-10 underline"
                >Release match</Button
            ></DialogTrigger
        >
        <DialogContent class="max-h-[90dvh] overflow-y-auto">
            <DialogHeader
                ><DialogTitle>Release this match</DialogTitle
                ><DialogDescription
                    >Keep the allocation in history and make its amount
                    available to match again. This changes no receipt, expense
                    or cash balance.</DialogDescription
                ></DialogHeader
            >
            <form class="space-y-5" @submit.prevent="submit">
                <LedgerFeedback
                    :errors="form.errors"
                    :uncertain="feedback.uncertain.value"
                    :history="'/budgets?month=' + month"
                />
                <fieldset :disabled="form.processing">
                    <Label :for="'release-reason-' + allocation.id"
                        >Reason</Label
                    >
                    <Input
                        :id="'release-reason-' + allocation.id"
                        v-model="form.reason"
                        required
                        maxlength="180"
                        class="mt-2 min-h-11"
                        :aria-invalid="!!form.errors.reason"
                        :aria-describedby="'release-error-' + allocation.id"
                    />
                    <InputError
                        :id="'release-error-' + allocation.id"
                        :message="form.errors.reason"
                    />
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
                        :disabled="form.processing"
                        :aria-busy="form.processing"
                        >{{
                            form.processing ? 'Releasing…' : 'Release match'
                        }}</Button
                    ></DialogFooter
                >
            </form>
        </DialogContent>
    </Dialog>
</template>
