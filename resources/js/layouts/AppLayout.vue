<script setup lang="ts">
import AppFooter from '@/components/AppFooter.vue';
import AppHeader from '@/components/AppHeader.vue';
</script>

<template>
    <div
        class="flex min-h-screen flex-col bg-background font-body-md text-body-md text-on-surface antialiased"
    >
        <AppHeader />
        <main class="mx-auto w-full max-w-7xl flex-1 px-margin-desktop">
            <slot />
        </main>
        <AppFooter />
    </div>
</template>
