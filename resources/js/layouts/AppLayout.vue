<script setup lang="ts">
import AppFooter from '@/components/AppFooter.vue';
import AppHeader from '@/components/AppHeader.vue';
</script>

<template>
    <div
        class="flex min-h-screen flex-col bg-background font-body-md text-body-md text-on-surface antialiased"
    >
        <AppHeader />
        <main class="flex-1">
            <slot />
        </main>
        <AppFooter />
    </div>
</template>
