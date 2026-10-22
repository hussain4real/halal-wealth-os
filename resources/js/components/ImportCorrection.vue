<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useStatementRequest } from '@/composables/useStatementRequest';
import type { ImportPreviewChoice } from '@/types/imports';
const props = defineProps<{
    receiptId: string;
    token: string;
    previews: ImportPreviewChoice[];
}>();
const { fields, locked, busy, errors, notice, submit, recover } =
    useStatementRequest(
        String(usePage().props.auth.user.id) +
            ':correct:' +
            props.receiptId +
            ':' +
            props.token,
        '/imports/receipts/' + props.receiptId + '/' + props.token + '/correct',
        { reason: '', replacement_id: '', replacement_row: '' },
        (fields) => ({
            ...fields,
            replacement_id: fields.replacement_id || null,
            replacement_row: fields.replacement_row || null,
        }),
    );
const confirmed = ref(false);
</script>
<template>
    <details class="rounded-lg border p-4">
        <summary class="cursor-pointer font-medium">
            Reverse or replace this imported operation
        </summary>
        <form class="mt-4 space-y-4" @submit.prevent="submit">
            <p class="text-sm text-muted-foreground">
                The original source and financial history remain retained. A
                reversal cancels the complete root; a replacement uses a
                separately reviewed source row and creates a linked reversal
                plus replacement. Existing domain checks still apply.
            </p>
            <div
                data-import-feedback
                tabindex="-1"
                aria-live="polite"
                class="outline-none"
            >
                <p v-if="notice" class="mb-2 rounded bg-muted p-3 text-sm">
                    {{ notice }}
                </p>
                <p
                    v-for="error in errors"
                    :key="error"
                    class="mb-2 text-destructive"
                >
                    {{ error }}
                </p>
            </div>
            <Label :for="'reason-' + token">Correction reason</Label
            ><Input
                :id="'reason-' + token"
                v-model="fields.reason"
                maxlength="180"
                required
                :disabled="locked"
            />
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <Label :for="'replacement-' + token"
                        >Optional replacement preview</Label
                    ><select
                        :id="'replacement-' + token"
                        v-model="fields.replacement_id"
                        :disabled="locked"
                        class="mt-1 w-full rounded-md border bg-background p-2"
                    >
                        <option value="">Cancel with reversal only</option>
                        <option v-for="p in previews" :key="p.id" :value="p.id">
                            {{ p.label }}
                        </option>
                    </select>
                    <a
                        v-if="fields.replacement_id"
                        :href="'/imports/' + fields.replacement_id"
                        target="_blank"
                        rel="noopener"
                        class="mt-2 inline-block text-sm underline"
                        >Open selected preview and verify source row</a
                    >
                </div>
                <div>
                    <Label :for="'replacement-row-' + token"
                        >Replacement source row number</Label
                    ><Input
                        :id="'replacement-row-' + token"
                        v-model="fields.replacement_row"
                        inputmode="numeric"
                        :disabled="locked || !fields.replacement_id"
                        :required="!!fields.replacement_id"
                    />
                </div>
            </div>
            <label class="flex items-start gap-3 text-sm"
                ><input
                    v-model="confirmed"
                    type="checkbox"
                    :disabled="locked"
                    class="mt-1 size-4"
                /><span
                    >I approve this linked reversal or replacement, preserving
                    the original history.</span
                ></label
            >
            <div class="flex flex-wrap gap-3">
                <Button
                    :disabled="
                        busy || (!locked && (!confirmed || !fields.reason))
                    "
                    >{{
                        locked
                            ? 'Retry unchanged correction'
                            : 'Commit linked correction'
                    }}</Button
                ><Button
                    v-if="locked"
                    type="button"
                    variant="outline"
                    :disabled="busy"
                    @click="recover"
                    >Check receipt</Button
                >
            </div>
        </form>
    </details>
</template>
