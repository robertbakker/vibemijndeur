<script setup lang="ts">
import MaterialIcon from '@/components/MaterialIcon.vue';

// Notification sign-up is not yet wired to a backend; visual only.
</script>

<template>
    <div class="bg-primary text-on-primary p-stack-lg rounded-2xl shadow-xl">
        <h3 class="font-headline-md text-headline-md mb-stack-sm text-white">
            Blijf op de hoogte
        </h3>
        <p class="font-body-md text-body-md mb-stack-lg text-white/80">
            Ontvang direct meldingen over wijzigingen in de planning of
            onvoorziene afsluitingen.
        </p>
        <div class="gap-base flex flex-col">
            <input
                type="email"
                placeholder="Uw e-mailadres"
                class="focus:ring-secondary-container w-full rounded-lg border border-white/20 bg-white/10 px-4 py-3 text-white transition-all placeholder:text-white/40 focus:ring-2 focus:outline-none"
            />
            <button
                class="bg-secondary-container text-on-secondary-container font-headline-md text-label-md group flex w-full items-center justify-center gap-2 rounded-xl py-4 font-bold shadow-lg transition-all hover:scale-[1.02] active:scale-[0.98]"
            >
                Houd mij op de hoogte
                <MaterialIcon
                    name="arrow_forward"
                    class="transition-transform group-hover:translate-x-1"
                />
            </button>
        </div>
    </div>
</template>
