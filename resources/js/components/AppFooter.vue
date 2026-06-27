<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

const currentYear = new Date().getFullYear();

const columns = [
    {
        title: 'Ontdek',
        links: [
            'Werkzaamheden op de kaart',
            'Zoek op adres',
            'Gepland werk',
            'Afgerond werk',
            'Veelgestelde vragen',
        ],
    },
    {
        title: 'Voor bewoners',
        links: [
            'Hoe blijft mijn straat bereikbaar?',
            'Parkeren tijdens werk',
            'Afvalinzameling',
            'Overlast melden',
            'Schade melden',
        ],
    },
    {
        title: 'Organisaties',
        links: [
            'Voor gemeenten',
            'Voor netbeheerders',
            'Voor aannemers',
            'Data & API',
            'Werk aanmelden',
        ],
    },
    {
        title: 'Over ons',
        links: [
            'Over voormijndeur',
            'Hoe het werkt',
            'Nieuws',
            'Pers',
            'Vacatures',
        ],
    },
];

const cities = [
    'Amsterdam',
    'Rotterdam',
    'Den Haag',
    'Utrecht',
    'Eindhoven',
    'Groningen',
    'Tilburg',
    'Almere',
    'Breda',
    'Nijmegen',
    'Haarlem',
    'Arnhem',
    'Zaanstad',
    'Amersfoort',
    'Apeldoorn',
    'Den Bosch',
    'Maastricht',
    'Leiden',
    'Dordrecht',
    'Zwolle',
    'Delft',
    'Alkmaar',
    'Deventer',
    'Leeuwarden',
];

const workTypes = [
    'Gaswerkzaamheden',
    'Rioolvervanging',
    'Wegonderhoud',
    'Glasvezel aanleg',
    'Kabels & leidingen',
    'Herbestrating',
    'Boomonderhoud',
    'Bruggen & kades',
];

const socials = [
    { icon: 'fa-linkedin-in', label: 'LinkedIn' },
    { icon: 'fa-x-twitter', label: 'X' },
    { icon: 'fa-facebook-f', label: 'Facebook' },
];

const legalLinks = [
    'Privacy',
    'Cookies',
    'Toegankelijkheid',
    'Sitemap',
    'Contact',
];
</script>

<template>
    <footer class="mt-auto bg-primary-dark text-on-primary-container">
        <div
            class="mx-auto max-w-7xl px-margin-desktop pt-stack-xxl pb-stack-lg"
        >
            <div
                class="grid grid-cols-1 gap-stack-xl md:grid-cols-[1.4fr_1fr_1fr_1fr_1fr]"
            >
                <!-- Brand -->
                <div>
                    <div class="mb-3.5 flex items-center gap-2.5">
                        <span
                            class="h-[22px] w-[22px] rounded-[5px] bg-secondary-container"
                        ></span>
                        <span
                            class="font-display text-[18px] font-extrabold text-on-primary"
                            >voormijndeur</span
                        >
                    </div>
                    <p
                        class="mb-stack-md max-w-60 text-[13.5px] leading-relaxed text-on-primary-container/70"
                    >
                        Alle werkzaamheden bij u in de buurt, duidelijk
                        uitgelegd. Een initiatief in samenwerking met gemeenten
                        en netbeheerders.
                    </p>
                    <div class="flex gap-2.5">
                        <a
                            v-for="social in socials"
                            :key="social.icon"
                            href="#"
                            :aria-label="social.label"
                            class="flex h-[34px] w-[34px] items-center justify-center rounded-lg bg-white/[0.08] text-on-primary-container/70 transition-colors hover:text-secondary-container"
                            ><i
                                class="fa-brands"
                                :class="social.icon"
                                aria-hidden="true"
                            ></i><span class="sr-only"
                                >{{ social.label }}</span
                            ></a
                        >
                    </div>
                </div>

                <!-- Link columns -->
                <div v-for="col in columns" :key="col.title">
                    <div
                        class="mb-3.5 text-[13px] font-bold tracking-wide text-on-primary uppercase"
                    >
                        {{ col.title }}
                    </div>
                    <div class="flex flex-col gap-2.5">
                        <a
                            v-for="link in col.links"
                            :key="link"
                            href="#"
                            class="text-[13.5px] text-on-primary-container/70 transition-colors hover:text-secondary-container"
                            >{{ link }}</a
                        >
                    </div>
                </div>
            </div>

            <!-- City link cloud (crawlability) -->
            <div class="mt-9 border-t border-white/10 pt-stack-lg">
                <div
                    class="mb-3.5 text-[13px] font-bold tracking-wide text-on-primary uppercase"
                >
                    Werkzaamheden per gemeente
                </div>
                <div class="flex flex-wrap gap-2">
                    <Link
                        v-for="city in cities"
                        :key="city"
                        href="/kaart"
                        class="rounded-md bg-white/5 px-2.5 py-1.5 text-[12.5px] text-on-primary-container/70 transition-colors hover:bg-white/10 hover:text-secondary-container"
                        >Werkzaamheden {{ city }}</Link
                    >
                </div>
            </div>

            <!-- Type link cloud -->
            <div class="mt-stack-lg">
                <div
                    class="mb-3.5 text-[13px] font-bold tracking-wide text-on-primary uppercase"
                >
                    Per soort werk
                </div>
                <div class="flex flex-wrap gap-2">
                    <Link
                        v-for="type in workTypes"
                        :key="type"
                        href="/kaart"
                        class="rounded-md bg-white/5 px-2.5 py-1.5 text-[12.5px] text-on-primary-container/70 transition-colors hover:bg-white/10 hover:text-secondary-container"
                        >{{ type }}</Link
                    >
                </div>
            </div>

            <!-- Bottom bar -->
            <div
                class="mt-9 flex flex-wrap items-center justify-between gap-3 border-t border-white/10 pt-stack-md"
            >
                <div class="text-[12.5px] text-on-primary-container/60">
                    © {{ currentYear }} voormijndeur · Een publieke
                    informatiedienst
                </div>
                <div class="flex flex-wrap gap-4.5">
                    <a
                        v-for="link in legalLinks"
                        :key="link"
                        href="#"
                        class="text-[12.5px] text-on-primary-container/70 transition-colors hover:text-secondary-container"
                        >{{ link }}</a
                    >
                </div>
            </div>
        </div>
    </footer>
</template>
