<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ProjectContact from '@/components/project/ProjectContact.vue';
import ProjectDocuments from '@/components/project/ProjectDocuments.vue';
import ProjectHero from '@/components/project/ProjectHero.vue';
import ProjectImpact from '@/components/project/ProjectImpact.vue';
import ProjectInfo from '@/components/project/ProjectInfo.vue';
import ProjectMap from '@/components/project/ProjectMap.vue';
import ProjectMilestones from '@/components/project/ProjectMilestones.vue';
import ProjectNotify from '@/components/project/ProjectNotify.vue';
import AppLayout from '@/layouts/AppLayout.vue';

export interface ProjectDetail {
    id: number;
    reference: string;
    title: string;
    description: string;
    statusLabel: string;
    period: string;
    endLabel: string | null;
    authority: string | null;
    locationLabel: string;
    latitude: number | null;
    longitude: number | null;
}

const props = defineProps<{ project: ProjectDetail }>();

defineOptions({ layout: AppLayout });
</script>

<template>
    <Head :title="`${project.title} | VoorMijnDeur`" />

    <div>
        <ProjectHero
            :title="project.title"
            :status-label="project.statusLabel"
            :reference="project.reference"
            :description="project.description"
        />

        <div
            class="px-margin-desktop py-stack-xxl gap-gutter mx-auto grid w-full max-w-7xl grid-cols-1 lg:grid-cols-12"
        >
            <!-- Left column -->
            <div class="gap-stack-xxl flex flex-col lg:col-span-8">
                <ProjectInfo
                    :period="project.period"
                    :authority="project.authority"
                    :description="project.description"
                />
                <ProjectMilestones />
                <ProjectImpact />
            </div>

            <!-- Right column -->
            <aside class="gap-stack-lg flex flex-col lg:col-span-4">
                <ProjectNotify />
                <ProjectMap
                    :roadwork-id="project.id"
                    :location-label="project.locationLabel"
                    :latitude="project.latitude"
                    :longitude="project.longitude"
                />
                <ProjectContact />
            </aside>
        </div>

        <ProjectDocuments />
    </div>
</template>
