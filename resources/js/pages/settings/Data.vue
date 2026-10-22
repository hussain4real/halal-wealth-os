<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Download } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { download, index } from '@/routes/data';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Data export', href: index() }],
    },
});
</script>

<template>
    <Head title="Data export" />
    <h1 class="sr-only">Data export</h1>
    <div class="space-y-6">
        <Heading
            variant="small"
            title="Your data"
            description="Download your profile and complete financial history."
        />
        <div class="space-y-4 rounded-lg border p-5">
            <h2 class="font-medium">Download all my data</h2>
            <p class="text-sm leading-6 text-muted-foreground">
                The versioned JSON file includes archived accounts, corrections,
                released reservations and retained evidence. It contains only
                your records and excludes passwords, security keys and tokens.
            </p>
            <p class="text-sm leading-6 text-muted-foreground">
                Keep this financial file private. It is for inspection and
                portability. Restoring the application requires its separate
                encrypted database backup.
            </p>
            <Button as-child>
                <a :href="download.url()">
                    <Download aria-hidden="true" class="h-4 w-4" />
                    Download all my data
                </a>
            </Button>
            <p class="text-sm text-muted-foreground">
                Confirm your password before the download begins.
            </p>
        </div>
    </div>
</template>
