<script setup lang="ts">
import MaterialIcon from '@/components/MaterialIcon.vue';

defineProps<{
    period: string;
    authority: string | null;
    description: string;
}>();
</script>

<template>
    <section>
        <div class="gap-stack-sm mb-stack-lg flex items-center">
            <MaterialIcon name="info" class="text-primary-container" />
            <h2 class="font-headline-lg text-headline-lg text-primary">
                Project Informatie
            </h2>
        </div>

        <div class="gap-stack-lg grid grid-cols-1 md:grid-cols-2">
            <div
                class="p-stack-lg border-outline-variant rounded-xl border bg-white"
            >
                <p
                    class="font-label-md text-label-md text-primary mb-base uppercase opacity-60"
                >
                    Looptijd
                </p>
                <p class="font-body-lg text-body-lg font-semibold">
                    {{ period }}
                </p>
            </div>
            <div
                class="p-stack-lg border-outline-variant rounded-xl border bg-white"
            >
                <p
                    class="font-label-md text-label-md text-primary mb-base uppercase opacity-60"
                >
                    {{ authority ? 'Wegbeheerder' : 'Aannemer' }}
                </p>
                <!-- Authority is real; contractor is unknown, so a placeholder is shown. -->
                <p class="font-body-lg text-body-lg font-semibold">
                    {{ authority ?? 'CivielBouw Nederland B.V.' }}
                </p>
            </div>
        </div>

        <div class="mt-stack-lg">
            <p
                class="font-body-md text-body-md text-on-surface-variant leading-relaxed"
            >
                {{ description }}
            </p>
        </div>
    </section>
</template>
