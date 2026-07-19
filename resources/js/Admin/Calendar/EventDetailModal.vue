<script setup>
    import { ref, onMounted , watch, watchEffect} from 'vue';
    import TextDesc from '@/Components/NewComponents/TextDesc.vue';

    const props = defineProps(['event_selected', 'authUser', 'privOne', "privilege"]);
    const emit = defineEmits(['editEventsDetails', 'addEvent', 'deleteEventEmit']);

    function updateEventDetails(id){
    	if(id == 0){
    		emit('addEvent', 0)
    	}else{
    		emit('editEventsDetails', id)
    	}
    }

    function deleteEvent(delete_id) {
        emit('deleteEventEmit', delete_id)
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
                <th>Note</th>
                <th class="text-end">#</th>
            </tr>
        </thead>
        <tbody>
            <tr v-for="(event_data, idx) in props.event_selected" :key="event_data.id || idx">
                <td>{{ idx + 1 }}</td>
                <td>{{ event_data.title }}</td>
                <td>{{ event_data.display_start_date + ' / ' + event_data.display_start_time }}</td>
                <td>{{ event_data.display_end_date + ' / ' + event_data.display_end_time }}</td>
                <td>{{ event_data.name }}</td>
                <td><TextDesc :text="event_data.note" :max="15" /></td>
                <td class="text-end" v-if="event_data.user_id == props.authUser || props.privOne" >
                    <Button2 cls="btn-ghost-primary btn-sm" @click="updateEventDetails(event_data.id)">
                        <i class="bi bi-pencil"></i>
                    </Button2>
                    <Button2 cls="btn-danger btn-sm ms-1" @click="deleteEvent(event_data.id)" ><i class="bi bi-trash"></i></Button2>
                </td>
            </tr>
        </tbody>
    </TableCont>

    <br>

    <div class="text-end">
        <button class="btn btn-primary btn-sm" v-if="privilege !== 0" type="button" @click.prevent="updateEventDetails(0)">Add Event</button>
    </div>

</template>