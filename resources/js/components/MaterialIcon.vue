<script setup lang="ts">
const props = defineProps<{
    name: string;
    fill?: boolean;
}>();
</script>

<template>
    <span
        class="material-symbols-outlined"
        :style="props.fill ? { fontVariationSettings: `'FILL' 1` } : undefined"
        >{{ props.name }}</span
    >
</template>
