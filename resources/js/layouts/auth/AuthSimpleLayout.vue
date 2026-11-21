<script setup lang="ts">
import EntryLayout from '@/layouts/EntryLayout.vue';

defineProps<{ title?: string; description?: string }>();
</script>

<template>
    <EntryLayout>
        <div class="mx-auto flex w-full max-w-md flex-col gap-6 lg:mx-0">
            <div>
                <h1 class="display-title text-4xl sm:text-5xl">{{ title }}</h1>
                <p
                    v-if="description"
                    class="mt-3 leading-relaxed text-muted-foreground"
                >
                    {{ description }}
                </p>
            </div>
            <slot />
        </div>
    </EntryLayout>
</template>
