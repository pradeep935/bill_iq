<script setup>
    import { ref, onMounted , watch, watchEffect} from 'vue';
    import TextDesc from '@/Components/NewComponents/TextDesc.vue';

    const props = defineProps(['upcoming_event_list', 'authUser', 'privOne', 'privilege']);
    const emit = defineEmits(['editEventsDetails', 'addEvent']);

    function updateEventDetails(id){
    	if(id == 0){
    		emit('addEvent', 0)
    	}else{
    		emit('editEventsDetails', id)
    	}
    }

</script>
<template>
	 <TableCont>
        <thead>
            <tr>
                <th>Sn</th>
                <th>Title</th>
                <th>Start Date / Time</th>
                <th>End Date / Time</th>
                <th>Author</th>
                <th class="text-end">#</th>
            </tr>
        </thead>
        <tbody>
            <tr v-for="(upcoming_event_data, idx) in props.upcoming_event_list" :key="upcoming_event_data.id || idx">
                <td>{{ idx + 1 }}</td>
                <td>{{ upcoming_event_data.title }}</td>
                <td>{{ upcoming_event_data.display_start_date + ' / ' + upcoming_event_data.display_start_time }}</td>
                <td>{{ upcoming_event_data.display_end_date + ' / ' + upcoming_event_data.display_end_time }}</td>
                <td>{{ upcoming_event_data.name }}</td>
                <td class="text-end" v-if="upcoming_event_data.user_id == props.authUser || props.privOne" >
                    <Button2 cls="btn-outline-warning btn-sm" @click.prevent="updateEventDetails(upcoming_event_data.id)">
                        <i class="bi bi-pencil"></i>
                    </Button2>
                </td>
            </tr>
        </tbody>
    </TableCont>

    <br>

    <div class="text-end">
        <button class="btn btn-primary btn-sm" type="button" v-if="privilege !== 0" @click.prevent="addNewEvent(0)">Add Event</button>
    </div>

</template>