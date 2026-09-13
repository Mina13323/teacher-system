<script setup>
import PublicNavbar from '@/components/landing/PublicNavbar.vue';
import Hero from '@/components/landing/Hero.vue';
import Philosophy from '@/components/landing/Philosophy.vue';
import GeographySection from '@/components/landing/GeographySection.vue';
import HistorySection from '@/components/landing/HistorySection.vue';
import LearningJourney from '@/components/landing/LearningJourney.vue';
import Features from '@/components/landing/Features.vue';
import CoursePreview from '@/components/landing/CoursePreview.vue';
import HowItWorks from '@/components/landing/HowItWorks.vue';
import FinalCTA from '@/components/landing/FinalCTA.vue';
import PublicFooter from '@/components/landing/PublicFooter.vue';
</script>

<template>
    <div class="min-h-screen bg-parchment-50 font-sans text-ink-900 selection:bg-terracotta-200 selection:text-terracotta-900">
        <!-- Public Navbar -->
        <PublicNavbar />

        <main>
            <!-- Hero with Video Hook -->
            <Hero />

            <!-- Educational Philosophy -->
            <Philosophy />

            <!-- Geography Section -->
            <GeographySection />

            <!-- History Section -->
            <HistorySection />

            <!-- Learning Journey (6-Step Pathway) -->
            <LearningJourney />

            <!-- Platform Capabilities & Features -->
            <Features />

            <!-- Public Course Discovery -->
            <CoursePreview />

            <!-- Simple Process Workflow -->
            <HowItWorks />

            <!-- Closing Conversion Call To Action -->
            <FinalCTA />
        </main>

        <!-- Editorial Public Footer -->
        <PublicFooter />
    </div>
</template>
