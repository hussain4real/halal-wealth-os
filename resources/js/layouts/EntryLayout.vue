<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLogo from '@/components/AppLogo.vue';
import { home } from '@/routes';
</script>

<template>
    <main class="entry-layout">
        <div class="entry-content">
            <Link
                :href="home()"
                class="entry-brand inline-flex w-fit items-center gap-3 text-primary"
                ><AppLogo
            /></Link>
            <div class="entry-body"><slot /></div>
        </div>
        <div class="entry-art" aria-hidden="true">
            <div class="entry-arch">
                <img
                    src="/images/dome-at-dusk.webp"
                    alt=""
                    width="1300"
                    height="2311"
                    fetchpriority="high"
                />
            </div>
        </div>
    </main>
</template>
