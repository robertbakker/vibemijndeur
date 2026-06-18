<script setup lang="ts">
import MaterialIcon from '@/components/MaterialIcon.vue';

// Detailed neighbourhood impact is not part of the Melvin feed; placeholder copy.
</script>

<template>
    <section>
        <div class="gap-stack-sm mb-stack-lg flex items-center">
            <MaterialIcon name="groups" class="text-primary-container" />
            <h2 class="font-headline-lg text-headline-lg text-primary">
                Impact op de Buurt
            </h2>
        </div>

        <div class="gap-stack-md grid grid-cols-1 md:grid-cols-3">
            <div
                class="bg-surface-container-low p-stack-lg flex flex-col items-center rounded-xl text-center"
            >
                <MaterialIcon
                    name="directions_car"
                    fill
                    class="text-primary mb-base text-3xl"
                />
                <h3 class="font-label-md text-label-md mb-stack-xs font-bold">
                    Verkeer
                </h3>
                <p class="font-caption text-caption text-on-surface-variant">
                    Afgesloten voor doorgaand verkeer. Omleidingsroutes staan
                    aangegeven.
                </p>
            </div>
            <div
                class="bg-surface-container-low p-stack-lg flex flex-col items-center rounded-xl text-center"
            >
                <MaterialIcon
                    name="volume_up"
                    fill
                    class="text-primary mb-base text-3xl"
                />
                <h3 class="font-label-md text-label-md mb-stack-xs font-bold">
                    Geluid
                </h3>
                <p class="font-caption text-caption text-on-surface-variant">
                    Lichte geluidsoverlast tussen 07:00 en 16:00 uur op
                    werkdagen.
                </p>
            </div>
            <div
                class="bg-surface-container-low p-stack-lg flex flex-col items-center rounded-xl text-center"
            >
                <MaterialIcon
                    name="local_parking"
                    fill
                    class="text-primary mb-base text-3xl"
                />
                <h3 class="font-label-md text-label-md mb-stack-xs font-bold">
                    Parkeren
                </h3>
                <p class="font-caption text-caption text-on-surface-variant">
                    Tijdelijke parkeerverboden in de werkvakken. Alternatieve
                    locaties beschikbaar.
                </p>
            </div>
        </div>
    </section>
</template>
