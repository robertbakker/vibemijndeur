<script setup lang="ts">
defineProps<{
    title: string;
    statusLabel: string;
    reference: string;
    description: string;
}>();

// No per-roadwork imagery is available; a representative stock photo is used.
const heroImage =
    'https://lh3.googleusercontent.com/aida-public/AB6AXuDUZ4GIKnpBgtkjLYTyOd2kAJYoC8wPrx2CUYGI0ww3ULpiqk6UToHEsd6NEyhUk79k7o8RHHWzBZPXcuy40xZtD7Jli_xEVhZHqi5Wndk2OxZl1bOjCOrAopa_tSTo9WbRU_ZP4xC2VXkM204Vo7PQ8BAsq0LjZz2JJHKcKaq-idEF-w0s5vayAAnQ1mXYQJWcYsu4H1bd_gL34LOl5cnIIkso-qBkJcyva6CIRsMh9WMBLc7f95t-j8sJYeo9EoOwl_np8wlcQRNb';
</script>

<template>
    <section class="relative h-64 w-full overflow-hidden">
        <div class="absolute inset-0 h-full w-full bg-cover bg-center"
            data-alt="A professional architectural photograph of a high-quality road construction site at dawn. The scene features clean yellow construction equipment and organized traffic cones on a freshly paved dark asphalt road. The lighting is soft and golden, reflecting the authoritative and precise nature of urban development. The overall aesthetic is clean, corporate modern, with a deep blue and gold color palette."
            :style="{ backgroundImage: `url('${heroImage}')` }"></div>
        <div class="hero-overlay px-margin-desktop pb-stack-lg absolute inset-0 flex flex-col justify-end">
            <div class="mx-auto w-full max-w-7xl">
                <div class="gap-stack-sm mb-stack-md flex items-center">
                    <span
                        class="bg-secondary-container text-on-secondary-container font-label-md text-caption rounded-full px-3 py-1 tracking-wider uppercase">{{
                        statusLabel }}</span>
                    <span class="font-label-md text-caption text-white/80">Project ID: {{ reference }}</span>
                </div>
                <h1 class="font-display text-display max-w-2xl leading-tight text-white">
                    {{ title }}
                </h1>
                <p class="font-body-lg text-body-lg mt-stack-md text-white/90">
                    {{ description }}
                </p>
            </div>
        </div>
    </section>
</template>
