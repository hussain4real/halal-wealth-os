<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import PortfolioShell from '@/components/PortfolioShell.vue';
import PortfolioAttempt from '@/components/PortfolioAttempt.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePortfolioForm } from '@/composables/usePortfolioForm';
import type { Asset } from '@/types/portfolio';
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Asset catalog', href: '/assets' },
            { title: 'Reference details', href: '#' },
        ],
    },
});
const props = defineProps<{ prior: Asset | null; currencies: string[] }>();
const { form, feedback, recovered, locked, submit } = usePortfolioForm(
    String(usePage().props.auth.user.id) +
        ':asset:' +
        (props.prior?.family ?? 'new'),
    {
        prior_id: props.prior?.id ?? '',
        name: props.prior?.name ?? '',
        kind: props.prior?.kind ?? 'equity',
        currency: props.prior?.currency ?? 'QAR',
        identifier: props.prior?.identifier ?? '',
        market: props.prior?.market ?? '',
        geography: props.prior?.geography ?? '',
        geography_source: props.prior?.geography_source ?? '',
        source: props.prior?.source ?? '',
        unsupported_terms: props.prior?.unsupported_terms ?? '',
        reason: '',
    },
);
</script>
<template>
    <PortfolioShell
        :title="prior ? 'Revise the reference.' : 'Add an asset reference.'"
        back="/assets"
        ><template #intro
            >Reference metadata describes an instrument. It creates no position,
            cash movement or compliance conclusion. Type and currency remain
            fixed within a reference family.</template
        >
        <form
            class="max-w-3xl rounded-2xl border bg-card p-5 sm:p-7"
            @submit.prevent="submit('/assets')"
        >
            <PortfolioAttempt
                :errors="form.errors"
                :uncertain="feedback.uncertain.value"
                :recovered="recovered"
                history="/assets"
            />
            <fieldset :disabled="form.processing || locked" class="space-y-5">
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <Label for="geography"
                            >Geography classification · optional</Label
                        ><Input
                            id="geography"
                            v-model="form.geography"
                            maxlength="180"
                        />
                        <p class="mt-2 text-xs text-muted-foreground">
                            Use sourced classification. Currency, market and
                            platform do not establish geography.
                        </p>
                    </div>
                    <div>
                        <Label for="geography-source"
                            >Geography source · required when classified</Label
                        ><Input
                            id="geography-source"
                            v-model="form.geography_source"
                            maxlength="180"
                        />
                    </div>
                </div>
                <div>
                    <Label for="name">Asset name</Label
                    ><Input
                        id="name"
                        v-model="form.name"
                        maxlength="180"
                        required
                        :aria-invalid="!!form.errors.name"
                    />
                </div>
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <Label for="kind">Instrument type</Label
                        ><select
                            id="kind"
                            v-model="form.kind"
                            :disabled="!!prior"
                            class="mt-2 min-h-11 w-full rounded-lg border bg-background px-3"
                        >
                            <option value="equity">Equity</option>
                            <option value="unitized_fund">Unitized fund</option>
                            <option value="manual_investment">
                                Manually valued investment
                            </option>
                        </select>
                    </div>
                    <div>
                        <Label for="currency">Native currency</Label
                        ><select
                            id="currency"
                            v-model="form.currency"
                            :disabled="!!prior"
                            class="mt-2 min-h-11 w-full rounded-lg border bg-background px-3"
                        >
                            <option v-for="c in currencies" :key="c">
                                {{ c }}
                            </option>
                        </select>
                    </div>
                </div>
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <Label for="identifier">Identifier · optional</Label
                        ><Input
                            id="identifier"
                            v-model="form.identifier"
                            maxlength="180"
                        />
                    </div>
                    <div>
                        <Label for="market">Market label · optional</Label
                        ><Input
                            id="market"
                            v-model="form.market"
                            maxlength="180"
                        />
                    </div>
                </div>
                <div>
                    <Label for="source">Reference source</Label
                    ><Input
                        id="source"
                        v-model="form.source"
                        required
                        maxlength="180"
                        :aria-invalid="!!form.errors.source"
                    />
                    <p class="mt-2 text-xs text-muted-foreground">
                        Describe your manual statement or reference. Do not
                        enter credentials.
                    </p>
                </div>
                <div>
                    <Label for="unsupported">Unsupported terms · optional</Label
                    ><Input
                        id="unsupported"
                        v-model="form.unsupported_terms"
                        maxlength="180"
                    />
                    <p class="mt-2 text-xs text-muted-foreground">
                        Record any terms this simple opening model cannot
                        represent. Complex sukuk cash flows and derivatives are
                        outside this model.
                    </p>
                </div>
                <div>
                    <Label for="reason">{{
                        prior
                            ? 'Reason for correction'
                            : 'Reason for this reference'
                    }}</Label
                    ><Input
                        id="reason"
                        v-model="form.reason"
                        required
                        maxlength="180"
                        :aria-invalid="!!form.errors.reason"
                    />
                </div>
            </fieldset>
            <div class="mt-6 flex flex-wrap gap-3 border-t pt-5">
                <Button type="submit" :disabled="form.processing">{{
                    form.processing
                        ? 'Saving…'
                        : locked
                          ? 'Retry same request'
                          : prior
                            ? 'Save reference version'
                            : 'Save asset reference'
                }}</Button
                ><Button as-child variant="outline"
                    ><Link href="/assets">Cancel</Link></Button
                >
            </div>
        </form>
    </PortfolioShell>
</template>
