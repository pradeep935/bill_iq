<script setup>
    import { ref, onMounted, watch } from 'vue';
    import DBService from '@/Service/Utils/DBService.js'
    import CardDisplay from '@/Components/NewComponents/CardDisplay.vue';

    const {staff} = defineProps(['staff']);

    const personal = [
        {label: 'Full Name', value: 'name', type: 'text'},
        {label: 'Father`s Name', value: 'father_name', type: 'text'},
        {label: 'Email', value: 'email', type: 'text'},
        {label: 'Contact Number', value: 'mobile', type: 'text'},
        {label: 'Date Of Birth', value: 'display_dob', type: 'date', format_type: 1},
        {label: 'Nationality', value: 'country_name', type: 'text'},
        // {label: 'State', value: 'state', type: 'text'},
        // {label: 'District', value: 'district', type: 'text'},
        // {label: 'city', value: 'city', type: 'text'},
    ];

    const professional = [
        {label: 'Current Job', value: 'current_job', type: 'text'},
        {label: 'Annual Salary', value: 'annual_net_salary', type: 'text'},
        {label: 'Languages', value: 'languages', type: 'text'},
        {label: 'Preferred Tactical Formation', value: 'preferred_tactical_formations', type: 'text'},
    ]

    const other = [
        {label: 'State', value: 'state', type: 'text'},
        {label: 'District', value: 'district', type: 'text'},
        {label: 'City', value: 'city', type: 'text'},
        {label: 'Address', value: 'address', type: 'text'},
        {label: 'Nick Name', value: 'nick_name', type: 'text'},
    ]

    const work = [
        {label: 'Team Reports Filled', value: 'total_player_reports', type: 'text'},
        {label: 'Player Reports Filled', value: 'total_team_reports', type: 'text'},
        {label: 'Total Assignments Filled', value: 'total_completed_assignments', type: 'text'},
        {label: 'Total Assignments Assigned', value: 'total_assigned_assignments', type: 'text'},
    ]

    const representative = [
        {label: 'Name', value: 'rep_name', type: 'text'},
        {label: 'Mobile', value: 'rep_mobile', type: 'text'},
        {label: 'Contract Expiry Date', value: 'rep_contract_expiry_date', type: 'date', format_type: 1},
        {label: 'Link', value: 'rep_link', type: 'link', link_text: 'Click Here'},
    ]

    const social = [
        {label: 'Facebook', value: 'facebook', link_text: 'Facebook', show_icon: true, type: 'link', icon: 'me-1 bi bi-facebook', icon_color: '#1877F2'},
        {label: 'Instagram', value: 'instagram', link_text: 'Instagram', show_icon: true, type: 'link', icon: 'me-1 bi bi-instagram insta-icon', icon_color: '#833AB4'},
        {label: 'X (Twitter)', value: 'twitter', link_text: 'X', show_icon: true, type: 'link', icon: 'me-1 bi bi-twitter-x', icon_color: '#000000'},
        {label: 'LinkedIn', value: 'linkedin', link_text: 'LinkedIn', show_icon: true, type: 'link', icon: 'me-1 bi bi-linkedin', icon_color: '#0077B5'},
    ]
</script>

<template>
    <div class="row mb-4">
        <CardDisplay title="Personal Information" body_class="mb-3" cls="col-md-4" :arr="personal" :obj="staff"></CardDisplay>
        <CardDisplay title="Professional Information" body_class="mb-3" cls="col-md-4" :arr="professional" :obj="staff"></CardDisplay>
        <CardDisplay title="Work Information" body_class="mb-3" cls="col-md-4" :arr="work" :obj="staff"></CardDisplay>
        <CardDisplay title="Other Details" body_class="mb-3" cls="col-md-4 mt-3" :arr="other" :obj="staff"></CardDisplay>
        <CardDisplay title="Representative Information" body_class="mb-3" cls="col-md-4 mt-3" :arr="representative" :obj="staff"></CardDisplay>
        <CardDisplay title="Social Media" body_class="mb-3" cls="col-md-4 mt-3" :arr="social" :obj="staff"></CardDisplay>
    </div>
</template>