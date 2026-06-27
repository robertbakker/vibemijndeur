<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { show } from '@/routes/projecten';

defineOptions({ layout: AppLayout });

defineProps<{
    projects: App.Data.RoadworkCard[];
    roadworksTotal: number;
}>();

const search = ref('Lindengracht, Amsterdam');

function goToMap(): void {
    router.visit('/kaart');
}

const infoCards = [
    {
        icon: 'fa-location-dot',
        title: 'Altijd op uw adres',
        text: 'Geen lange lijsten — alleen het werk dat uw straat raakt.',
    },
    {
        icon: 'fa-bell',
        title: 'Op tijd gewaarschuwd',
        text: 'Ontvang bericht zodra er werk bij u in de buurt gepland wordt.',
    },
    {
        icon: 'fa-handshake',
        title: 'Direct de juiste contactpersoon',
        text: 'Vragen? U ziet meteen wie u kunt bellen of mailen.',
    },
];
</script>

<template>
    <Head
        title="Welke werkzaamheden zijn er bij u voor de deur? | voormijndeur"
    />

    <!-- Hero -->
    <section
        class="relative overflow-hidden border-b-[3px] border-secondary-container"
        style="background: radial-gradient(rgba(11, 44, 94, 0.05) 1.2px, transparent 1.2px) 0 0 / 22px 22px, linear-gradient(135deg, #0b2c5e 0%, #143c73 46%, #1c4c8c 100%)"
    >
        <div
            class="pointer-events-none absolute -top-24 -right-24 h-90 w-90 rounded-full bg-secondary-container/15"
        ></div>
        <div
            class="pointer-events-none absolute -bottom-30 -left-16 h-60 w-60 rounded-full bg-white/[0.04]"
        ></div>

        <div
            class="relative mx-auto grid max-w-7xl items-center gap-12 px-margin-desktop py-16 lg:grid-cols-[1.05fr_0.95fr]"
        >
            <!-- Left -->
            <div>
                <div
                    class="mb-5 inline-flex items-center gap-2 rounded-full bg-secondary-container px-3.5 py-1.5 text-[12px] font-bold tracking-wide text-on-secondary-container"
                >
                    WERK IN DE BUURT, DUIDELIJK UITGELEGD
                </div>
                <h1
                    class="mb-4 max-w-[560px] font-display text-[46px] leading-[1.08] font-extrabold tracking-tight text-balance text-on-primary"
                >
                    Welke werkzaamheden zijn er bij u
                    <span class="text-secondary-container">voor de deur</span>?
                </h1>
                <p
                    class="mb-7 max-w-[520px] text-[18px] leading-relaxed text-on-primary-container"
                >
                    Vul uw adres in en zie precies wat er gebeurt, wanneer het
                    klaar is en hoe uw straat bereikbaar blijft.
                </p>

                <form
                    class="flex max-w-[520px] gap-2.5 rounded-xl bg-white p-2 shadow-[0_14px_36px_rgba(0,0,0,0.22)]"
                    @submit.prevent="goToMap"
                >
                    <div class="flex items-center pl-2.5 text-outline">
                        <i class="fa-solid fa-magnifying-glass"></i>
                    </div>
                    <input
                        v-model="search"
                        type="text"
                        aria-label="Zoek op straat of plaats"
                        class="flex-1 bg-transparent px-2 py-2.5 text-[16px] text-on-surface outline-none"
                    >
                    <button
                        type="submit"
                        class="rounded-lg bg-secondary-container px-6 py-3 text-[15px] font-bold text-on-secondary-container"
                    >
                        Zoeken
                    </button>
                </form>

                <div
                    class="mt-4.5 flex items-center gap-4 text-[13px] font-medium text-on-primary-container/80"
                >
                    <span>Populair:</span>
                    <Link
                        v-for="place in ['Centrum', 'Jordaan', 'De Pijp']"
                        :key="place"
                        href="/kaart"
                        class="text-on-primary underline underline-offset-2"
                        >{{ place }}</Link
                    >
                </div>
            </div>

            <!-- Right: decorative map teaser -->
            <Link href="/kaart" class="relative block cursor-pointer">
                <div
                    class="rotate-[1.4deg] rounded-[18px] bg-white p-2 shadow-[0_24px_60px_rgba(0,0,0,0.28)]"
                >
                    <div
                        class="relative h-[330px] overflow-hidden rounded-xl bg-[#E9ECEE]"
                    >
                        <div
                            class="absolute top-[20%] left-0 h-10 w-full -rotate-[7deg] bg-[#AEC9DF]"
                        ></div>
                        <div
                            class="absolute top-0 left-[38%] h-full w-9 bg-[#AEC9DF]"
                        ></div>
                        <div
                            class="absolute top-[44%] left-0 h-3 w-full bg-white"
                        ></div>
                        <div
                            class="absolute top-[64%] left-0 h-2 w-full bg-white"
                        ></div>
                        <div
                            class="absolute top-0 left-[20%] h-full w-2.5 bg-white"
                        ></div>
                        <div
                            class="absolute top-0 left-[70%] h-full w-2 bg-white"
                        ></div>
                        <div
                            class="absolute top-[50%] left-[46%] h-16 w-24 rounded-md bg-[#CFE3C4]"
                        ></div>

                        <!-- pulsing active marker -->
                        <div
                            class="absolute top-[48%] left-[30%] -translate-x-1/2 -translate-y-1/2"
                        >
                            <span
                                class="vmd-pulse absolute top-1/2 left-1/2 h-12 w-12 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-[#FFC400]"
                            ></span>
                            <span
                                class="relative flex h-9 w-9 items-center justify-center rounded-full border-[3px] border-white bg-[#FFC400] shadow-[0_3px_8px_rgba(10,30,60,0.4)]"
                            >
                                <i
                                    class="fa-solid fa-fire-flame-simple text-[15px] text-white"
                                ></i>
                            </span>
                        </div>
                        <div
                            class="absolute top-[66%] left-[60%] flex h-[34px] w-[34px] -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border-[3px] border-white bg-[#2F6BD8] shadow-[0_3px_8px_rgba(10,30,60,0.4)]"
                        >
                            <i
                                class="fa-solid fa-droplet text-[14px] text-white"
                            ></i>
                        </div>
                        <div
                            class="absolute top-[30%] left-[78%] flex h-[34px] w-[34px] -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border-[3px] border-white bg-[#1F8A5B] shadow-[0_3px_8px_rgba(10,30,60,0.4)]"
                        >
                            <i
                                class="fa-solid fa-bolt text-[14px] text-white"
                            ></i>
                        </div>

                        <!-- floating mini card -->
                        <div
                            class="absolute right-3.5 bottom-3.5 left-3.5 flex items-center gap-3 rounded-xl bg-white p-3 shadow-[0_6px_18px_rgba(10,30,60,0.18)]"
                        >
                            <div
                                class="flex h-[34px] w-[34px] items-center justify-center rounded-[9px] bg-surface-container-high"
                            >
                                <i
                                    class="fa-solid fa-fire-flame-simple text-[15px] text-primary"
                                ></i>
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="text-[13px] font-bold text-primary">
                                    Vervanging gasleiding
                                </div>
                                <div
                                    class="text-[11.5px] text-on-surface-variant"
                                >
                                    Lindengracht · nog ± 5 weken
                                </div>
                            </div>
                            <span
                                class="inline-flex items-center gap-1.5 rounded-full bg-[#FFF3C2] px-2 py-1 text-[10px] font-bold text-[#7A5B00]"
                            >
                                <span
                                    class="h-[5px] w-[5px] rounded-full bg-[#FFC400]"
                                ></span>Actief
                            </span>
                        </div>
                    </div>
                </div>
                <div
                    v-if="projects.length"
                    class="absolute -top-3.5 -right-1.5 rotate-3 rounded-[11px] bg-secondary-container px-3.5 py-2 text-[13px] font-extrabold text-on-secondary-container shadow-[0_8px_20px_rgba(0,0,0,0.2)]"
                >
                    {{ projects.length }}
                    in uw buurt
                </div>
            </Link>
        </div>
    </section>

    <!-- Nearby list -->
    <section class="mx-auto w-full max-w-7xl px-margin-desktop pt-12 pb-2">
        <div class="mb-5 flex items-end justify-between">
            <div>
                <div
                    class="mb-1 text-[13px] font-semibold tracking-wide text-on-surface-variant uppercase"
                >
                    In uw buurt
                </div>
                <h2
                    class="font-display text-[26px] font-bold tracking-tight text-primary"
                >
                    {{ projects.length }}
                    werkzaamheden gevonden
                </h2>
            </div>
            <Link
                href="/kaart"
                class="inline-flex items-center gap-2 rounded-[9px] border border-outline-variant bg-white px-4 py-2.5 text-[14px] font-bold text-primary"
            >
                Bekijk op de kaart →
            </Link>
        </div>

        <div
            v-if="projects.length === 0"
            class="rounded-xl border border-dashed border-outline-variant p-stack-xl text-center text-on-surface-variant"
        >
            Momenteel geen lopende werkzaamheden gevonden.
        </div>

        <div v-else class="grid grid-cols-1 gap-3.5 md:grid-cols-2">
            <Link
                v-for="work in projects"
                :key="work.id"
                :href="work.slug ? show.url(work.slug) : '/kaart'"
                class="flex items-start gap-4 rounded-[14px] bg-white p-4.5 shadow-[0_1px_3px_rgba(10,30,60,0.08)] transition-shadow hover:shadow-[0_6px_20px_rgba(10,30,60,0.14)]"
            >
                <div
                    class="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-[11px] bg-surface-container-high"
                >
                    <i
                        class="fa-solid text-[20px] text-primary"
                        :class="work.icon"
                    ></i>
                </div>
                <div class="min-w-0 flex-1">
                    <div class="mb-1.5 flex items-center gap-2">
                        <span
                            class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-bold tracking-wide"
                            :style="{ background: work.chipBg, color: work.chipText }"
                        >
                            <span
                                class="h-1.5 w-1.5 rounded-full"
                                :style="{ background: work.markerColor }"
                            ></span>
                            {{ work.statusLabel }}
                        </span>
                        <span class="text-[12px] font-semibold text-outline"
                            >{{ work.typeLabel }}</span
                        >
                    </div>
                    <div
                        class="mb-0.5 font-display text-[17px] font-bold tracking-tight text-primary"
                    >
                        {{ work.title }}
                    </div>
                    <div class="text-[14px] text-on-surface-variant">
                        {{ work.locationLabel }}
                        · {{ work.period }}
                    </div>
                </div>
            </Link>
        </div>

        <p class="mt-stack-md text-[12px] text-outline">
            {{ roadworksTotal.toLocaleString('nl-NL') }}
            werkzaamheden landelijk geregistreerd.
        </p>
    </section>

    <!-- Info strip -->
    <section
        class="mx-auto w-full max-w-7xl px-margin-desktop pt-stack-xl pb-stack-xxl"
    >
        <div class="grid grid-cols-1 gap-3.5 md:grid-cols-3">
            <div
                v-for="card in infoCards"
                :key="card.title"
                class="rounded-[14px] bg-white p-5.5 shadow-[0_1px_3px_rgba(10,30,60,0.08)]"
            >
                <div class="mb-3 text-[24px] text-primary">
                    <i class="fa-solid" :class="card.icon"></i>
                </div>
                <div
                    class="mb-1.5 font-display text-[17px] font-bold text-primary"
                >
                    {{ card.title }}
                </div>
                <p class="text-[14px] leading-relaxed text-on-surface-variant">
                    {{ card.text }}
                </p>
            </div>
        </div>
    </section>
</template>
