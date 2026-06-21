<script setup lang="ts">
const currentYear = new Date().getFullYear();

const legalLinks = [
    { label: 'Privacybeleid', bold: false },
    { label: 'Toegankelijkheid', bold: false },
    { label: 'Cookies', bold: false },
    { label: 'Contactgegevens', bold: true },
];
</script>

<template>
    <footer class="mt-stack-xxl w-full bg-primary">
        <div
            class="mx-auto flex max-w-7xl flex-col items-center justify-between gap-stack-lg px-margin-desktop py-stack-lg md:flex-row">
            <div class="flex flex-col items-center gap-2 md:items-start">
                <span class="font-headline-md text-headline-md font-bold text-on-primary">voormijndeur</span>
                <span class="font-caption text-caption text-on-primary-container opacity-80">© {{ currentYear }}
                    voormijndeur</span>
            </div>
            <div class="flex flex-wrap justify-center gap-stack-lg">
                <a v-for="link in legalLinks" :key="link.label" href="#"
                    class="font-caption text-caption text-on-primary-container opacity-80 transition-opacity hover:text-secondary-fixed"
                    :class="{ 'font-bold': link.bold }">{{ link.label }}</a>
            </div>
        </div>
    </footer>
</template>
