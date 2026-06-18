<script setup lang="ts">
import MaterialIcon from '@/components/MaterialIcon.vue';

// Melvin does not expose project phases, so the timeline is illustrative.
</script>

<template>
    <section>
        <div class="gap-stack-sm mb-stack-lg flex items-center">
            <MaterialIcon name="event_note" class="text-primary-container" />
            <h2 class="font-headline-lg text-headline-lg text-primary">
                Mijlpalen
            </h2>
        </div>

        <div class="space-y-stack-md">
            <!-- Fase 1 (afgerond) -->
            <div class="gap-stack-lg flex">
                <div class="flex flex-col items-center">
                    <div class="bg-primary mt-2 h-4 w-4 rounded-full"></div>
                    <div class="bg-outline-variant h-full w-0.5"></div>
                </div>
                <div class="pb-stack-lg">
                    <p class="font-label-md text-label-md text-primary font-bold">
                        Fase 1: Sloop en grondwerk
                    </p>
                    <p class="font-caption text-caption text-on-surface-variant mb-base">
                        Afgerond - April 2024
                    </p>
                    <p class="font-body-md text-body-md text-on-surface-variant">
                        Verwijderen van oude bestrating en voorbereiden van de
                        ondergrond voor nieuwe riolering.
                    </p>
                </div>
            </div>

            <!-- Fase 2 (huidig) -->
            <div class="gap-stack-lg flex">
                <div class="flex flex-col items-center">
                    <div
                        class="border-primary mt-2 h-4 w-4 rounded-full border-4 bg-white"
                    ></div>
                    <div class="bg-outline-variant h-full w-0.5"></div>
                </div>
                <div class="pb-stack-lg">
                    <p class="font-label-md text-label-md text-primary font-bold">
                        Fase 2: Rioleringswerkzaamheden
                    </p>
                    <p class="font-caption text-caption text-secondary mb-base font-bold">
                        Huidige fase - Juni 2024
                    </p>
                    <p class="font-body-md text-body-md text-on-surface-variant">
                        Aanleg van het nieuwe gescheiden rioolstelsel in het
                        noordelijke deel van de straat.
                    </p>
                </div>
            </div>

            <!-- Fase 3 (gepland) -->
            <div class="gap-stack-lg flex">
                <div class="flex flex-col items-center">
                    <div class="bg-outline-variant mt-2 h-4 w-4 rounded-full"></div>
                </div>
                <div>
                    <p class="font-label-md text-label-md text-outline">
                        Fase 3: Bestrating en Inrichting
                    </p>
                    <p class="font-caption text-caption text-on-surface-variant mb-base">
                        Verwacht - Augustus 2024
                    </p>
                </div>
            </div>
        </div>
    </section>
</template>
