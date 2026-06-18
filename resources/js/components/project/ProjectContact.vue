<script setup lang="ts">
// Placeholder omgevingsmanager; not part of the Melvin feed.
const portrait =
    'https://lh3.googleusercontent.com/aida-public/AB6AXuBFiuFFHV3npv6AiJgQDHbWqh0KFqIFNvnUCPExnNSkz-OMEaO39bplO2qVZjPbwhkfBQt5GaKiJ4Ocznr5PoPWgw0k0QCP77rVQ-21Uhpgw6gwwZD642duYBVbCO1JNwCnQ7Razkc_OR01j5ZLGklOOO4UjDHx1tPwBiIWvRJBMDOdlKIK-NfPbaA0DsRpHNq32OQo_gLFJqiCjvM39Qyk-4RUUq-inMS7VsJ0vyeW1ljpPzL8vo1M-mXmQUthbM8i1tbRUw5d9Luq';
</script>

<template>
    <div class="p-stack-lg border-outline-variant rounded-2xl border bg-white">
        <h4 class="font-label-md text-label-md text-primary mb-stack-sm font-bold">
            Vragen of feedback?
        </h4>
        <p class="font-body-md text-body-md text-on-surface-variant mb-stack-md">
            Onze omgevingsmanager staat u graag te woord over dit project.
        </p>
        <div class="gap-stack-md flex items-center">
            <div
                class="h-12 w-12 overflow-hidden rounded-full bg-cover bg-center"
                :style="{ backgroundImage: `url('${portrait}')` }"
            ></div>
            <div>
                <p class="font-label-md text-label-md font-bold">
                    Sarah de Vries
                </p>
                <p class="font-caption text-caption text-on-surface-variant">
                    Omgevingsmanager
                </p>
            </div>
        </div>
        <button
            class="mt-stack-md border-primary text-primary font-label-md text-label-md hover:bg-primary w-full rounded-lg border py-2 transition-all hover:text-white"
        >
            Stuur bericht
        </button>
    </div>
</template>
