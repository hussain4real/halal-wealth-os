<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowDown, ArrowRight, LockKeyhole } from '@lucide/vue';
import { TabsContent, TabsList, TabsRoot, TabsTrigger } from 'reka-ui';
import AppLogo from '@/components/AppLogo.vue';
import CurrencyDisplay from '@/components/CurrencyDisplay.vue';
import { dashboard, login } from '@/routes';

const examples = [
    {
        id: 'overview',
        tab: 'Overview',
        measure: 'Net worth',
        amount: '20000',
        description: 'See what you own and owe in one place.',
        rows: [
            { label: 'Cash', amount: '12500' },
            { label: 'Investments', amount: '8000' },
            { label: 'Liabilities', amount: '-500' },
        ],
    },
    {
        id: 'plan',
        tab: 'Monthly plan',
        measure: 'Posted expenses',
        amount: '2350',
        description: 'Compare recorded spending with the plan for the month.',
        rows: [
            { label: 'Planned expenses', amount: '3000' },
            { label: 'Below plan by', amount: '650' },
        ],
    },
    {
        id: 'goals',
        tab: 'Goals',
        measure: 'Emergency fund · funded',
        amount: '4500',
        description: 'Follow the money set aside for a savings goal.',
        rows: [
            { label: 'Savings target', amount: '6000' },
            { label: 'Still to fund', amount: '1500' },
        ],
    },
];
</script>

<template>
    <Head title="Private access" />
    <header
        class="welcome-width flex min-h-20 items-center justify-between gap-3 py-3"
    >
        <div class="flex min-w-0 items-center gap-2.5 text-primary">
            <AppLogo />
        </div>
        <a
            href="#workspace-preview"
            class="welcome-explore inline-flex min-h-11 shrink-0 items-center gap-2 text-sm font-medium text-primary"
        >
            Explore <ArrowDown class="size-4" aria-hidden="true" />
        </a>
    </header>
    <main>
        <section class="welcome-hero" aria-labelledby="welcome-heading">
            <img
                class="welcome-photo"
                src="/images/dome-at-dusk.webp"
                width="1300"
                height="2311"
                alt=""
                fetchpriority="high"
            />
            <div class="welcome-width welcome-copy">
                <h1 id="welcome-heading" class="display-title">
                    Your wealth,<br /><em>in perspective.</em>
                </h1>
                <p class="welcome-intro">
                    Track your accounts, plan your monthly spending and see how
                    much you’ve set aside for each goal.
                </p>
                <div class="mt-7 flex flex-wrap items-center gap-x-5 gap-y-3">
                    <Link
                        :href="$page.props.auth.user ? dashboard() : login()"
                        class="welcome-action"
                    >
                        {{
                            $page.props.auth.user ? 'Open workspace' : 'Log in'
                        }}
                        <ArrowRight class="size-4" aria-hidden="true" />
                    </Link>
                    <p
                        v-if="!$page.props.auth.user"
                        class="inline-flex items-center gap-2 text-sm text-[#e8e2ce]"
                    >
                        <LockKeyhole
                            class="size-4"
                            aria-hidden="true"
                        />Existing accounts only
                    </p>
                </div>
            </div>
        </section>

        <section
            aria-labelledby="workspace-preview"
            class="welcome-width welcome-preview"
        >
            <div>
                <h2
                    id="workspace-preview"
                    tabindex="-1"
                    class="display-title w-fit scroll-mt-6 text-4xl sm:text-5xl"
                >
                    Inside the workspace
                </h2>
                <p
                    class="mt-3 max-w-xs text-base leading-relaxed text-muted-foreground"
                >
                    Explore the views with sample figures in Qatari riyals.
                </p>
            </div>
            <TabsRoot default-value="overview" class="min-w-0">
                <TabsList
                    aria-label="Workspace previews"
                    class="flex gap-4 border-b sm:gap-8"
                >
                    <TabsTrigger
                        v-for="example in examples"
                        :key="example.id"
                        :value="example.id"
                        class="welcome-tab"
                    >
                        {{ example.tab }}
                    </TabsTrigger>
                </TabsList>
                <TabsContent
                    v-for="example in examples"
                    :key="example.id"
                    :value="example.id"
                    class="welcome-panel"
                >
                    <div class="min-w-0">
                        <h3 class="text-sm font-medium text-muted-foreground">
                            {{ example.measure }}
                        </h3>
                        <p
                            class="display-title mt-1 text-4xl tracking-tight sm:text-5xl"
                        >
                            <CurrencyDisplay
                                :amount="example.amount"
                                currency="QAR"
                                group-thousands
                            />
                        </p>
                        <p
                            class="mt-3 max-w-xs text-sm leading-relaxed text-muted-foreground"
                        >
                            {{ example.description }}
                        </p>
                    </div>
                    <dl class="min-w-0 divide-y text-sm">
                        <div
                            v-for="row in example.rows"
                            :key="row.label"
                            class="flex items-baseline justify-between gap-3 py-3"
                        >
                            <dt class="text-muted-foreground">
                                {{ row.label }}
                            </dt>
                            <dd class="text-right font-medium">
                                <CurrencyDisplay
                                    :amount="row.amount"
                                    currency="QAR"
                                    group-thousands
                                />
                            </dd>
                        </div>
                    </dl>
                </TabsContent>
            </TabsRoot>
        </section>
    </main>
</template>

<style scoped>
.welcome-width {
    width: min(80rem, 88%);
    margin-inline: auto;
}
.welcome-explore:hover {
    text-decoration: underline;
    text-underline-offset: 0.25em;
}
.welcome-hero {
    position: relative;
    isolation: isolate;
    overflow: hidden;
    background: #112f29;
    color: #fffaf0;
}
.welcome-photo {
    position: absolute;
    z-index: -2;
    inset: 0 0 0 auto;
    width: 65%;
    height: 100%;
    object-fit: cover;
    object-position: center 52%;
}
.welcome-hero::before {
    content: '';
    position: absolute;
    z-index: -1;
    inset: 0;
    background: linear-gradient(
        90deg,
        #112f29 31%,
        #112f29fa 37%,
        #112f29b0 51%,
        #112f291f 72%,
        transparent
    );
}
.welcome-copy {
    padding-block: clamp(4rem, 6.5vw, 7rem);
}
.welcome-copy h1 {
    font-size: clamp(4rem, 7.5vw, 7.5rem);
    line-height: 0.96;
    letter-spacing: -0.035em;
}
.welcome-copy em {
    color: #e4cf9d;
}
.welcome-intro {
    max-width: min(25rem, 42%);
    margin-top: 1.6rem;
    line-height: 1.65;
    font-size: 1.05rem;
    color: #ebefe6;
}
.welcome-action {
    display: inline-flex;
    align-items: center;
    justify-content: space-between;
    gap: 2.25rem;
    min-height: 3rem;
    padding: 0.7rem 1.15rem;
    border-radius: 0.55rem;
    background: #f3e8cb;
    color: #16382e;
    font-weight: 600;
}
.welcome-action:hover {
    background: #fff5dd;
}
.welcome-action:focus-visible {
    outline-color: #ffedb7;
}
.welcome-preview {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1.95fr);
    gap: clamp(2rem, 6vw, 7rem);
    padding-block: clamp(2.5rem, 4.7vw, 5rem);
}
.welcome-tab {
    min-height: 2.75rem;
    margin-bottom: -1px;
    border-bottom: 2px solid transparent;
    color: var(--muted-foreground);
    font-size: 0.9rem;
    font-weight: 600;
}
.welcome-tab:hover {
    color: var(--primary);
}
.welcome-tab[data-state='active'] {
    border-color: var(--primary);
    color: var(--primary);
}
.welcome-panel {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: clamp(1rem, 3vw, 3rem);
    align-items: start;
    padding-block: 1.75rem;
}
.welcome-panel[hidden] {
    display: none;
}
@media (max-width: 55rem) {
    .welcome-preview {
        grid-template-columns: 1fr;
        gap: 1rem;
    }
}
@media (max-width: 40rem) {
    .welcome-photo {
        width: 100%;
        height: 68%;
        object-position: center 66%;
    }
    .welcome-hero::before {
        background: linear-gradient(
            0deg,
            #112f29 29%,
            #112f29fa 41%,
            #112f29eb 52%,
            #112f29ad 65%,
            #112f291f 77%,
            transparent
        );
    }
    .welcome-copy {
        padding-top: clamp(11.5rem, 52vw, 18rem);
        padding-bottom: 2.5rem;
    }
    .welcome-copy h1 {
        font-size: clamp(3.5rem, 13.7vw, 5.4rem);
    }
    .welcome-intro {
        max-width: 26rem;
        margin-top: 1.2rem;
        font-size: 1rem;
    }
    .welcome-panel {
        grid-template-columns: 1fr;
        gap: 0.65rem;
    }
}
</style>
