<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import PortfolioShell from '@/components/PortfolioShell.vue';
import PortfolioValue from '@/components/PortfolioValue.vue';
import PortfolioAttempt from '@/components/PortfolioAttempt.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { usePortfolioForm } from '@/composables/usePortfolioForm';
import type { Page, PortfolioAccount } from '@/types/portfolio';
defineOptions({
    layout: { breadcrumbs: [{ title: 'Portfolio', href: '/portfolio' }] },
});
defineProps<{
    accounts: Page<PortfolioAccount>;
    eligible: Page<{ id: string; name: string; currency: string }>;
    query: string;
}>();
const { form, feedback, recovered, locked, submit } = usePortfolioForm(
    String(usePage().props.auth.user.id) + ':link',
    { account_id: '', platform_label: '', source: '' },
);
</script>
<template>
    <PortfolioShell
        title="A clear view of your investments."
        eyebrow="Portfolio · account by account"
        ><template #intro
            >Choose one valuation method for each investment account. Detailed
            mode adds posted cash and opening positions; whole-account mode uses
            one value that already includes cash.</template
        >
        <div class="flex flex-wrap gap-3">
            <Button as-child variant="outline"
                ><Link href="/assets">Asset catalog</Link></Button
            ><Button as-child variant="outline"
                ><Link href="/valuations">Valuation & performance</Link></Button
            >
        </div>
        <section
            v-if="!accounts.items.length"
            class="rounded-2xl border border-dashed p-7"
        >
            <h2 class="text-xl font-semibold">
                Bring your statement into view.
            </h2>
            <p class="mt-3 max-w-2xl text-muted-foreground">
                Link an existing investment ledger account below, record your
                opening statement, then review and confirm its valuation mode.
                Linking alone moves no cash.
            </p>
        </section>
        <div class="grid gap-5 lg:grid-cols-2">
            <article
                v-for="a in accounts.items"
                :key="a.portfolio_id"
                class="min-w-0 space-y-3"
            >
                <div class="px-1">
                    <Link
                        :href="'/portfolio/' + a.portfolio_id"
                        class="inline-flex min-h-11 items-center text-xl font-semibold [overflow-wrap:anywhere] underline"
                        >{{ a.name }}</Link
                    >
                    <p
                        class="text-sm [overflow-wrap:anywhere] text-muted-foreground"
                    >
                        {{ a.platform_label }} · {{ a.currency
                        }}{{ a.archived ? ' · archived' : '' }}
                    </p>
                </div>
                <PortfolioValue :value="a.contribution" />
            </article>
        </div>
        <Link
            v-if="accounts.next"
            :href="'/portfolio?after=' + accounts.next"
            class="inline-flex min-h-11 items-center underline"
            >More portfolio accounts →</Link
        >
        <section class="rounded-2xl border bg-card p-5 sm:p-7">
            <h2 class="text-xl font-semibold">Link an investment account</h2>
            <p class="mt-2 text-sm text-muted-foreground">
                Existing unlinked investment accounts retain their ledger-only
                contribution. A linked account needs a confirmed mode before its
                value is complete.
            </p>
            <form action="/portfolio" class="mt-5 flex gap-2">
                <Label for="q" class="sr-only"
                    >Search eligible account name</Label
                ><Input
                    id="q"
                    name="q"
                    :default-value="query"
                    placeholder="Search eligible accounts"
                    class="max-w-md min-w-0"
                /><Button type="submit" variant="outline">Search</Button>
            </form>
            <form
                class="mt-5 max-w-2xl space-y-5"
                @submit.prevent="submit('/portfolio')"
            >
                <PortfolioAttempt
                    :errors="form.errors"
                    :uncertain="feedback.uncertain.value"
                    :recovered="recovered"
                    history="/portfolio"
                />
                <fieldset
                    :disabled="form.processing || locked"
                    class="space-y-5"
                >
                    <div>
                        <Label for="account">Investment ledger account</Label
                        ><select
                            id="account"
                            v-model="form.account_id"
                            required
                            class="mt-2 min-h-11 w-full rounded-lg border bg-background px-3"
                            :aria-invalid="!!form.errors.account_id"
                        >
                            <option value="" disabled>Choose an account</option>
                            <option
                                v-if="
                                    form.account_id &&
                                    !eligible.items.some(
                                        (a) => a.id === form.account_id,
                                    )
                                "
                                :value="form.account_id"
                            >
                                Retained selection · {{ form.account_id }}
                            </option>
                            <option
                                v-for="a in eligible.items"
                                :key="a.id"
                                :value="a.id"
                            >
                                {{ a.name }} · {{ a.currency }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <Label for="platform">Platform reference label</Label
                        ><Input
                            id="platform"
                            v-model="form.platform_label"
                            required
                            maxlength="180"
                            :aria-invalid="!!form.errors.platform_label"
                        />
                    </div>
                    <div>
                        <Label for="source">Manual source</Label
                        ><Input
                            id="source"
                            v-model="form.source"
                            required
                            maxlength="180"
                            :aria-invalid="!!form.errors.source"
                        />
                    </div>
                </fieldset>
                <Button
                    type="submit"
                    :disabled="form.processing || (!form.account_id && !locked)"
                    >{{
                        form.processing
                            ? 'Linking…'
                            : locked
                              ? 'Retry same request'
                              : 'Link account workspace'
                    }}</Button
                >
            </form>
            <p
                v-if="!eligible.items.length"
                class="mt-4 text-sm text-muted-foreground"
            >
                No matching eligible accounts.
                <Link href="/accounts" class="underline"
                    >Create an investment ledger account</Link
                >
                first, or open an existing portfolio above.
            </p>
            <Link
                v-if="eligible.next"
                :href="
                    '/portfolio?q=' +
                    encodeURIComponent(query) +
                    '&eligible_after=' +
                    eligible.next
                "
                class="mt-3 inline-flex min-h-11 items-center underline"
                >More eligible accounts →</Link
            >
        </section>
    </PortfolioShell>
</template>
