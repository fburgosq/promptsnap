<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import TextLink from '@/components/TextLink.vue';
import { login } from '@/routes';

defineOptions({
    layout: {
        title: 'Registration Disabled',
        description:
            'New account registration is currently disabled for testing.',
    },
});
</script>

<template>
    <Head title="Register" />

    <!--
    Registration form is commented out for testing.
    Please sign in using the default test account on the login page.
    -->
    <div class="text-center text-sm text-muted-foreground">
        Already have an account?
        <TextLink :href="login()" class="underline underline-offset-4">
            Log in
        </TextLink>
    </div>
</template>
