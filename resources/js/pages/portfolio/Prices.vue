<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import PortfolioShell from '@/components/PortfolioShell.vue';
import PortfolioAttempt from '@/components/PortfolioAttempt.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePortfolioForm } from '@/composables/usePortfolioForm';
import type { Price } from '@/types/valuation';
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Portfolio', href: '/portfolio' },
            { title: 'Manual prices', href: '#' },
        ],
    },
});
const props = defineProps<{
    account: { id: string; name: string; currency: string; archived: boolean };
    prices: Price[];
    next: string | null;
    asset_next: string | null;
    assets: { id: string; name: string; currency: string; version: string }[];
    prior: Price | null;
    query: string;
    now: string;
    context: string;
}>();
const search = ref(props.query);
const searchAssets = (after?: string) =>
    router.get(
        '/portfolio/' + props.account.id + '/prices',
        {
            q: search.value,
            ...(after ? { asset_after: after } : {}),
            ...(props.prior ? { revise: props.prior.id } : {}),
        },
        { preserveState: true, preserveScroll: true },
    );
const { form, feedback, recovered, locked, submit } = usePortfolioForm(
    String(usePage().props.auth.user.id) +
        ':price:' +
        props.account.id +
        ':' +
        (props.prior?.id ?? 'new'),
    {
        context: props.context,
        asset_id: props.prior?.asset_id ?? '',
        unit_price: props.prior?.unit_price ?? '',
        source: props.prior?.source ?? '',
        observed_at: props.prior?.observed_at ?? props.now,
        effective_at: props.prior?.effective_at ?? props.now,
        supersedes_id: props.prior?.id ?? '',
        reason: '',
        unit_basis_confirmed: '',
    },
);
watch(
    () => [form.asset_id, form.unit_price, form.effective_at],
    () => {
        if (!locked.value) form.unit_basis_confirmed = '';
    },
);
</script>
<template>
    <PortfolioShell
        :title="
            prior
                ? 'Correct a retained manual price.'
                : 'Add a dated manual price.'
        "
        eyebrow="Price observations · not a live feed"
        :back="'/portfolio/' + account.id"
        ><template #intro
            >{{ account.name }} · {{ account.currency }}. Record a sourced price
            in the instrument's raw units after all retained splits through the
            price's effective UTC business date. A price never changes cash,
            quantity or book cost.</template
        >
        <div class="flex flex-wrap gap-3">
            <Button as-child variant="outline"
                ><Link :href="'/portfolio/' + account.id + '/events'"
                    >Review recorded corporate actions</Link
                ></Button
            >
            <Button as-child variant="outline"
                ><Link href="/valuations">Dated valuations</Link></Button
            ><Button as-child variant="outline"
                ><Link href="/assets">Asset catalog</Link></Button
            >
        </div>
        <section class="rounded-2xl border bg-card p-5 sm:p-7">
            <h2 class="text-xl font-semibold">
                {{ prior ? 'New immutable correction' : 'Manual observation' }}
            </h2>
            <p class="mt-2 text-sm text-muted-foreground">
                Eligible prices must be observed and effective by the cutoff and
                no more than seven days old. Prices before a later split become
                incompatible. Saved runs keep their original versions.
            </p>
            <form class="mt-5 flex gap-2" @submit.prevent="searchAssets()">
                <Label for="price-search" class="sr-only"
                    >Search asset references</Label
                ><Input
                    id="price-search"
                    name="q"
                    v-model="search"
                    maxlength="120"
                    placeholder="Search asset name"
                /><Button type="submit" variant="outline">Search</Button>
            </form>
            <button
                v-if="asset_next"
                type="button"
                class="mt-2 min-h-11 text-sm underline"
                @click="searchAssets(asset_next)"
            >
                More matching asset references →
            </button>
            <form
                class="mt-6 space-y-5"
                @submit.prevent="submit('/portfolio/' + account.id + '/prices')"
            >
                <PortfolioAttempt
                    :errors="form.errors"
                    :uncertain="feedback.uncertain.value"
                    :recovered="recovered"
                    :history="'/portfolio/' + account.id + '/prices'"
                />
                <fieldset
                    :disabled="form.processing || locked || account.archived"
                    class="space-y-5"
                >
                    <div>
                        <Label for="price-asset"
                            >Asset reference · version retained</Label
                        ><select
                            id="price-asset"
                            v-model="form.asset_id"
                            required
                            :disabled="!!prior"
                            class="mt-2 min-h-11 w-full rounded-lg border bg-background px-3"
                        >
                            <option value="" disabled>
                                Choose a unitized asset
                            </option>
                            <option
                                v-if="
                                    form.asset_id &&
                                    !assets.some((a) => a.id === form.asset_id)
                                "
                                :value="form.asset_id"
                            >
                                Retained selection · {{ form.asset_id }}
                            </option>
                            <option
                                v-for="a in assets"
                                :key="a.id"
                                :value="a.id"
                            >
                                {{ a.name }} · version {{ a.version }}
                            </option>
                        </select>
                        <p class="mt-2 text-xs text-muted-foreground">
                            Latest twenty matching references. Narrow your
                            search to find another asset.
                        </p>
                    </div>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <Label for="price-value"
                                >Unit price · {{ account.currency }}</Label
                            ><Input
                                id="price-value"
                                v-model="form.unit_price"
                                inputmode="decimal"
                                required
                                maxlength="32"
                                :aria-invalid="!!form.errors.unit_price"
                            />
                        </div>
                        <div>
                            <Label for="price-source">Source</Label
                            ><Input
                                id="price-source"
                                v-model="form.source"
                                :aria-invalid="!!form.errors.source"
                                required
                                maxlength="180"
                            />
                        </div>
                        <div>
                            <Label for="price-effective"
                                >Effective time · UTC</Label
                            ><Input
                                id="price-effective"
                                v-model="form.effective_at"
                                :aria-invalid="!!form.errors.effective_at"
                                required
                                placeholder="YYYY-MM-DDTHH:MM:SSZ"
                            />
                        </div>
                        <div>
                            <Label for="price-observed"
                                >Source observation time · UTC</Label
                            ><Input
                                id="price-observed"
                                v-model="form.observed_at"
                                :aria-invalid="!!form.errors.observed_at"
                                required
                                placeholder="YYYY-MM-DDTHH:MM:SSZ"
                            />
                        </div>
                    </div>
                    <div>
                        <Label for="price-reason">{{
                            prior ? 'Correction reason' : 'Recording reason'
                        }}</Label
                        ><Input
                            id="price-reason"
                            v-model="form.reason"
                            :aria-invalid="!!form.errors.reason"
                            required
                            maxlength="180"
                        />
                    </div>
                    <label
                        class="flex items-start gap-3 rounded-xl border bg-muted/20 p-4 text-sm"
                        ><input
                            v-model="form.unit_basis_confirmed"
                            type="checkbox"
                            :aria-invalid="!!form.errors.unit_basis_confirmed"
                            true-value="1"
                            false-value=""
                            required
                            class="mt-1 size-4 shrink-0"
                        /><span
                            >I checked that this raw price uses units after
                            every retained split through its effective UTC
                            business date, including same-day splits. No
                            automatic split adjustment is implied.</span
                        ></label
                    >
                </fieldset>
                <Button
                    type="submit"
                    :disabled="form.processing || account.archived"
                    >{{
                        form.processing
                            ? 'Recording…'
                            : locked
                              ? 'Retry same observation'
                              : prior
                                ? 'Record correction version'
                                : 'Record manual price'
                    }}</Button
                >
                <p v-if="account.archived" class="text-sm">
                    This account is archived; its retained evidence remains
                    readable.
                </p>
            </form>
        </section>
        <section class="space-y-4">
            <h2 class="text-xl font-semibold">Retained price observations</h2>
            <p
                v-if="!prices.length"
                class="rounded-xl border border-dashed p-6 text-muted-foreground"
            >
                No manual price observations yet. Missing prices stay
                unavailable.
            </p>
            <article
                v-for="p in prices"
                :key="p.id"
                class="min-w-0 space-y-2 rounded-2xl border bg-card p-5 text-sm [overflow-wrap:anywhere]"
            >
                <p class="text-lg font-semibold">
                    {{ account.currency }} {{ p.unit_price }}
                    <span class="text-sm font-normal text-muted-foreground"
                        >· version {{ p.version }}</span
                    >
                </p>
                <p>{{ p.source }} · asset reference {{ p.asset_id }}</p>
                <p class="text-muted-foreground">
                    Effective {{ p.effective_at }} · observed
                    {{ p.observed_at }}
                </p>
                <p>{{ p.reason }}</p>
                <details>
                    <summary class="min-h-11 cursor-pointer py-3">
                        Immutable observation identity
                    </summary>
                    <p>Observation {{ p.id }} · series {{ p.series_id }}</p>
                    <p class="mt-2 break-all">
                        Raw split context {{ p.split_basis }}
                    </p>
                </details>
                <Link
                    :href="
                        '/portfolio/' + account.id + '/prices?revise=' + p.id
                    "
                    class="inline-flex min-h-11 items-center underline"
                    >Create a retained correction →</Link
                >
            </article>
            <Link
                v-if="next"
                :href="'/portfolio/' + account.id + '/prices?after=' + next"
                class="inline-flex min-h-11 items-center underline"
                >Older price observations →</Link
            >
        </section></PortfolioShell
    >
</template>
