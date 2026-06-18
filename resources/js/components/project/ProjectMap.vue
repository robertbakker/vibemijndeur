<script setup lang="ts">
import { computed } from 'vue';
import MaterialIcon from '@/components/MaterialIcon.vue';

const props = defineProps<{
    locationLabel: string;
    latitude: number | null;
    longitude: number | null;
}>();

const coordinates = computed(() =>
    props.latitude !== null && props.longitude !== null
        ? `${props.latitude.toFixed(5)}, ${props.longitude.toFixed(5)}`
        : null,
);
</script>

<template>
    <div
        class="border-outline-variant overflow-hidden rounded-2xl border bg-white shadow-sm"
    >
        <div
            class="p-stack-md border-outline-variant bg-surface-container-low flex items-center justify-between border-b"
        >
            <h3 class="font-label-md text-label-md text-primary font-bold">
                Projectlocatie
            </h3>
            <span
                class="border-outline-variant text-primary rounded border bg-white px-2 py-1 text-[10px] font-bold"
                >LIVE KAART</span
            >
        </div>

        <!-- Static basemap thumbnail; the marker is positioned on the real centroid. -->
        <div class="relative h-64 brightness-75 contrast-125 grayscale">
            <div
                class="absolute inset-0 h-full w-full bg-cover bg-center"
                style="
                    background-image: url('https://lh3.googleusercontent.com/aida-public/AB6AXuBvQ-e0MJJ_on_xffeFJ810OTvDNKSPbHMo9ZXdx1i4-NoDZA6mkUpUE79LKDI9MtWnYWt8GqMHPqcUo8dwu63459_kSS581dh-4yV39G6DObzr-w3kpoo63ZC0lmQLElOK9HEYWmrpl5dn2l6g7x2_kyxOFrhHpDIcfQJKxHZYaUAC14z4nTOTOZ_ormsRP3FBvXkNRlmAuAUhm12eBIKVgjYSX5_1VCoc0yjyLJYa0F6cseLinvmaCgr15n1TvKeybP4C3PXPcJda');
                "
            ></div>
            <div
                class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2"
            >
                <div class="relative">
                    <div
                        class="bg-primary absolute inset-0 h-8 w-8 animate-ping rounded-full opacity-20"
                    ></div>
                    <div
                        class="bg-primary relative flex h-8 w-8 items-center justify-center rounded-full border-4 border-white shadow-xl"
                    >
                        <MaterialIcon name="construction" class="text-sm text-white" />
                    </div>
                </div>
            </div>
        </div>

        <div class="p-stack-md flex items-center justify-between">
            <span
                class="font-caption text-caption text-on-surface-variant flex items-center gap-1"
            >
                <MaterialIcon name="location_on" class="text-xs" />
                {{ coordinates ?? locationLabel }}
            </span>
            <span class="text-primary font-label-md text-caption font-bold"
                >{{ locationLabel }}</span
            >
        </div>
    </div>
</template>
