<script setup lang="ts">
import MaterialIcon from '@/components/MaterialIcon.vue';
</script>

<template>
    <div class="border-primary p-lg gap-lg flex flex-col items-center justify-between rounded-xl border-2 bg-white shadow-lg md:flex-row">
        <div class="flex items-center gap-6">
            <div class="relative">
                <div class="border-primary-container h-24 w-24 overflow-hidden rounded-full border-4 shadow-inner">
                    <img
                        alt="Marcus Thorne - Projectmanager"
                        class="h-full w-full object-cover"
                        src="https://lh3.googleusercontent.com/aida-public/AB6AXuDqze37s7k7srfSdjbxubb2ac0R1fnWEBSvxQ7Lj0n6ssyoXKi06JcMXOwjULtLLfHKobBeBUyhzwC4SHSMxFQt5I9sIZbpw0xEOy6dy0O0i9pISOR0RFmLcDS6HOLayLpbB7HcMvWQLZ_sOWzZrP5d44jBMvurcfi81IU-B3r5FlaMoveN639mN-O_92WsV-mZY4J2MlaxlAWSLFhgiqYvAxcTQ7bDPStYcO2XBADhFTVcK7VzFE--Bd-bfNGfi_UAew3ruLzd1Taa"
                    />
                </div>
                <div class="absolute -right-1 -bottom-1 h-5 w-5 rounded-full border-2 border-white bg-green-500"></div>
            </div>
            <div>
                <h3 class="font-headline-md text-primary mb-1 text-2xl font-bold">Heeft u vragen?</h3>
                <p class="text-on-surface font-semibold">Marcus Thorne</p>
                <p class="text-on-surface-variant text-sm">Senior Projectmanager • Team Stadsontwikkeling</p>
                <p class="text-primary mt-1 text-xs font-medium">vinden@voormijndeur.nl</p>
            </div>
        </div>
        <div class="flex w-full flex-col gap-4 sm:flex-row md:w-auto">
            <button class="bg-primary hover:bg-primary/90 flex flex-1 items-center justify-center gap-2 rounded px-10 py-3 font-bold text-white transition-all md:flex-none">
                <MaterialIcon name="mail" class="text-sm" />
                Stuur bericht
            </button>
            <button class="border-primary text-primary hover:bg-primary flex flex-1 items-center justify-center gap-2 rounded border-2 px-10 py-3 font-bold transition-all hover:text-white md:flex-none">
                <MaterialIcon name="phone" class="text-sm" />
                Bel kantoor
            </button>
        </div>
    </div>
</template>
