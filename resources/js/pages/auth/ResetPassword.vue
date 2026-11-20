<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import TextLink from '@/components/TextLink.vue';
import { login } from '@/routes';

defineOptions({
    layout: {
        title: 'Password Reset Disabled',
        description: 'Password reset is currently disabled for testing.',
    },
});
</script>

<template>
    <Head title="Reset password" />

    <!--
    Reset password form is commented out for testing.
    -->
    <div class="space-x-1 text-center text-sm text-muted-foreground">
        <span>Return to</span>
        <TextLink :href="login()">log in</TextLink>
    </div>
</template>
