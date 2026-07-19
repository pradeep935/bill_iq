<script setup>
	import { nextTick, ref, watch } from 'vue';
	import { useForm } from 'vee-validate';
	import DBService from '@/Service/Utils/DBService.js';
   import ChangePictureModal from '@/Components/Common/ChangePictureModal.vue';


	const { handleSubmit, resetForm } = useForm();
	const { player_id, params, canvas_trigger } = defineProps([
		'player_id',
		'params',
		'canvas_trigger'
	]);

	const emit = defineEmits(['callback']);

	watch(() => player_id,
	 	() => {
	 		resetForm();
	 		fetchPlayer()
	 	}
	 );

	watch(
		() => canvas_trigger,
		() => {
			nextTick(() => {
				const body = document.querySelector('#player_add_update .offcanvas-body');
				body?.scrollTo({ top: 0 });
			});
		}
	);

	const loading = ref(false);
	const loading_aiff = ref(false);
	const uploading_image = ref(false);

	const player = ref({
		biotype_id: '',
		nationality_id: '',
		nationality_other_id: '',
		gender: '',
		locally_developed: '',
		on_loan: '',
		foot_id: '',
		position_first_id: '',
		position_second_id: '',
		international_player: '',
	});

	const gender_list = ref([
		{ label: 'Male', value: 1 },
		{ label: 'Female', value: 2 },
	]);

	const select_yes_no = ref([
		{ label: 'Yes', value: 1 },
		{ label: 'No', value: 0 },
	]);

	function fetchPlayer() {
		uploading_image.value = false;
		if (player_id == 0) {
			resetForm();
			player.value = {};
			return;
		}
		loading.value = true;
		DBService.getData('/api/players/fetch-player/' + player_id).then((data) => {
			if (data.success) {
				player.value = data.player;
			} else {
				alert(data.message);
			}
			loading.value = false;
		});
	}

	const submitPlayerDetails = handleSubmit((values, { resetForm }) => {
		if(player.value.aiff_id && !isValidAiffId(player.value.aiff_id)) {
			bootbox.alert('Please enter a valid AIFF ID in PL********** format.');
			return;
		}
		loading.value = true;
		DBService.postData('/api/players/save-player', player.value).then((data) => {
			if (data.success) {
				bootbox.alert(data.message);
				resetForm();
				// $('#player_add_update').modal('hide');
				emit('callback');
			} else {
				bootbox.alert(data.message);
			}
			loading.value = false;
		});
	});

	function checkPlayerPosition(position_no) {
		if (player.value.position_second_id == player.value.position_first_id) {
			if (position_no == 2) player.value.position_second_id = '';
			else player.value.position_first_id = '';
		}
	}

	function isValidAiffId(aiffId) {
		return /^[Pp][Ll]\d{10}$/.test((aiffId || '').trim());
	}

	function searchAiffPlayer(){
		if (!isValidAiffId(player.value.aiff_id)) {
			bootbox.alert('Please enter a valid AIFF ID in PL********** format.');
			return;
		}
		if(player.value.first_name || player.value.last_name || player.value.dob || player.value.gender || player.value.father_name){
			bootbox.confirm("Existing data will be replaced. Are you sure you wants to continue?", function(result){
				if(result){
					searchAiffPlayerNew(); // proceed
				}
			});
		} else{
			searchAiffPlayerNew();
		}
	}

	function searchAiffPlayerNew(){
		loading_aiff.value = true;
		DBService.postData('/api/players/search-aiff-player', {aiff_id : player.value.aiff_id}).then((data) => {
			if (data.success) {

				player.value = data.player;
			} else {
				bootbox.alert(data.message);
			}
			loading_aiff.value = false;
		});
	}

	function openCloseImageModal(call_type) {
		uploading_image.value = !uploading_image.value;
		if (call_type == 1) {
			DBService.getData('/api/players/fetch-player-img/' + player_id).then((data) => {
				if (data.success) player.value.profile_pic = data.img_src;
				else alert(data.message);
			});
		}
	}

    function handlePlayerPhoto(data){
       // formData.value.profile_pic = data.path
    }

	function searchForDupes(){
		loading_aiff.value = true;
		DBService.postData('/api/players/search-name-dob-dupes', {first_name: player.value.first_name, last_name: player.value.last_name, dob: player.value.dob, id: player.value.id ? player.value.id : 0}).then((data) => {
			bootbox.alert(data.message);
			loading_aiff.value = false;
		});
	}
</script>

<template>
	<div class="offcanvas-body modal-right__body">
		<SectionToggle icon_cls="bi bi-search" title="AIFF Player Search" :defaultOpen="true" >
			<div class="player-header-card">
			   <div class="profile-upload-section mb-4">
			       <ChangePictureModal modalId="simple" v-model="player.profile_pic" @uploaded="handlePlayerPhoto"/>
			   </div>
	    		<div class="row align-items-end">
	        		<div class="col-md-9">
			            <label class="form-label">
			                AIFF Player ID
			            </label>
	            		<input class="form-control" v-model="player.aiff_id" placeholder="Enter AIFF Player ID">
	        		</div>
	        		<div class="col-md-3">
			            <button class="btn btn-success btn-success-modified w-100" @click.prevent="searchAiffPlayer()">
			                <i class="bi bi-search me-1"></i>
			                Search
			            </button>
			        </div>
	    		</div>
			    <div class="small text-muted mt-2">
			        Search AIFF database to auto-fill player details
			    </div>
			</div>
		</SectionToggle>

		<form @submit.prevent="submitPlayerDetails()" id="playerAddUpdate">
			<!-- ── Personal Details ── -->
			<SectionToggle icon_cls="bi bi-person" title="Personal Details" :defaultOpen="true" cls="mt-3">
				<div class="row g-3">
					<FormInput :disabled="loading_aiff || loading" label="First Name" name="first_name" :req="true" v-model="player.first_name" cls="col-md-6"/>

					<FormInput :disabled="loading_aiff || loading" label="Last Name" name="last_name" v-model="player.last_name" cls="col-md-6"/>

					<FormInput :disabled="loading_aiff || loading" label="Father's Name" name="father_name" v-model="player.father_name" :req="true" cls="col-md-6"/>

					<FormInput :disabled="loading_aiff || loading" label="Date of Birth" type="date" name="dob" v-model="player.dob" :req="true" cls="col-md-6"/>

					<div class="col-md-12 d-flex justify-content-center" v-if="player.first_name && player.dob">
						<span class="pe-2" style="font-weight: bold;">Search for duplicates (Enter full name first)</span>
						<button class="btn btn-sm btn-secondary" @click.prevent="searchForDupes()">Search</button>
					</div>

					<FormSelect :disabled="loading_aiff || loading" label="Gender" name="gender" v-model="player.gender" :req="true" :options="gender_list" cls="col-md-6"/>

					<FormSelect :disabled="loading" label="Biotype" name="biotype_id" v-model="player.biotype_id" :options="params.biotype_list" opt_id="id" opt_name="biotype_name" cls="col-md-6"/>

					<FormInput :disabled="loading" right_box_text="cm" label="Height" type="number" name="height" v-model="player.height" cls="col-md-6"/>

					<FormInput :disabled="loading" right_box_text="kg" label="Weight" type="number" name="weight" v-model="player.weight" cls="col-md-6"/>

					<FormInput :disabled="loading" label="Mobile" name="mobile" v-model="player.mobile" cls="col-md-6"/>

					<FormInput :disabled="loading" label="Email" name="email" v-model="player.email" cls="col-md-6"/>

					<FormSelect :disabled="loading"	label="Nationality" :req="true"	:options="params.country_list"	opt_name="country_name"	opt_id="id"	name="nationality_id"	v-model="player.nationality_id"	cls="col-md-6"/>

					<FormSelect :disabled="loading" label="Second Nationality" :options="params.country_list" opt_name="country_name" opt_id="id" name="nationality_other_id" v-model="player.nationality_other_id" cls="col-md-6"/>

					<FormInput :disabled="loading" label="Birth State" name="state" v-model="player.state" cls="col-md-6"/>

					<FormInput :disabled="loading" label="District" name="district" v-model="player.district" cls="col-md-6"/>

					<FormInput :disabled="loading" label="City" name="city" v-model="player.city" cls="col-md-4"/>

					<FormInput :disabled="loading" label="Studies" name="studies" v-model="player.studies" cls="col-md-4"/>

					<FormSelect :disabled="loading"	label="Locally Developed" name="locally_developed" v-model="player.locally_developed" :options="select_yes_no"	cls="col-md-4"/>

					<div class="col-md-12 form-group">
						<label>Address</label>
						<textarea :disabled="loading" class="form-control" name="address" v-model="player.address" rows="3"></textarea>
					</div>
				</div>
			</SectionToggle>

			<!-- ── AIFF Details ── -->
			<SectionToggle icon_clr="#9333ea" icon_cls="bi bi-patch-check" title="All India Football Federation" :defaultOpen="true" cls="mt-2">
				<div class="row g-3">
					<FormInput :disabled="loading" label="AIFF Player ID" name="aiff_id" v-model="player.aiff_id" cls="col-md-6" type="text"/>

					<FormInput :disabled="loading" label="Registered State" name="registered_state" v-model="player.registered_state" cls="col-md-6"/>

					<FormInput :disabled="loading" type="number" label="Maturity Rate" name="maturity_rate" v-model="player.maturity_rate" cls="col-md-6"/>

					<FormInput :disabled="loading" type="number" label="Comparative Height" name="height_comparative" v-model="player.height_comparative" cls="col-md-6"/>
				</div>
			</SectionToggle>

			<!-- ── Professional Details ── -->
			<SectionToggle icon_cls="bi bi-briefcase text-success" title="Professional Details" :defaultOpen="true" cls="mt-2">
				<div class="row g-3">
					<FormInput :disabled="loading" label="Club Name" name="club_name"  v-model="player.club_name" cls="col-md-6"/>
					<FormInput :disabled="loading" label="Tournament" name="tournament" v-model="player.tournament" cls="col-md-6"/>
					<FormInput :disabled="loading" label="Shirt Name" name="shirt_name" v-model="player.shirt_name" cls="col-md-6"/>
					<FormSelect :disabled="loading" label="On Loan"  name="on_loan" v-model="player.on_loan" :options="select_yes_no" cls="col-md-6"/>
					<!-- <FormInput :disabled="loading" label="Contract Expiry" type="date" :req="true" name="contract_expiry_date" v-model="player.contract_expiry_date" cls="col-md-6"/> -->

					<FormSelect :disabled="loading" label="Foot" name="foot_id" v-model="player.foot_id" :options="params.foot_list" opt_name="foot" opt_id="id" cls="col-md-6" />

					<FormSelect :disabled="loading"	label="First Position" 	name="position_first_id"	v-model="player.position_first_id"	:options="params.position_list"	opt_name="position_short"	opt_id="id"	@change="checkPlayerPosition(1)"	cls="col-md-6"	/>
					<FormSelect :disabled="loading" label="Second Position" name="position_second_id" v-model="player.position_second_id" :options="params.position_list" opt_name="position_short" opt_id="id" @change="checkPlayerPosition(2)" cls="col-md-6"/>
					<FormInput :disabled="loading"  label="Position Profile" name="position_profile" v-model="player.position_profile" cls="col-md-6"/>
					<FormSelect :disabled="loading" label="International Player" name="international_player" v-model="player.international_player" :options="select_yes_no" cls="col-md-6"/>

					<!-- <FormInput :disabled="loading" :req="true" left_box_text="₹" display_position="left" label="Market Value" name="market_value" v-model="player.market_value" cls="col-md-6" type="number"/> -->

					<!-- <FormInput :disabled="loading" :req="true" left_box_text="₹" display_position="left" label="Annual Net Salary" name="annual_salary" v-model="player.annual_salary" cls="col-md-6" type="number"/> -->
				</div>
			</SectionToggle>

			<!-- ── Social Links ── -->
			<SectionToggle icon_clr="#0891b2" icon_cls="bi bi-share" title="Social Media" :defaultOpen="true" cls="mt-2">
				<div class="row g-3">
					<FormInput left_box_text="bi bi-facebook" box_type="icon" :disabled="loading" label="Facebook" name="facebook" v-model="player.facebook" cls="col-md-6"/>
					<FormInput left_box_text="bi bi-instagram" box_type="icon" :disabled="loading" label="Instagram" name="instagram" v-model="player.instagram" cls="col-md-6"/>
					<FormInput left_box_text="bi bi-twitter-x" box_type="icon" :disabled="loading" label="X (Twitter)" name="twitter" v-model="player.twitter" cls="col-md-6"/>
					<FormInput left_box_text="bi bi-linkedin" box_type="icon" :disabled="loading" label="LinkedIn" name="linkedin" v-model="player.linkedin" cls="col-md-6"/>
				</div>
			</SectionToggle>

			<!-- ── Representative ── -->
			<SectionToggle icon_clr="#d97706" icon_cls="bi bi-person-lines-fill" title="Representative" :defaultOpen="false" cls="mt-2">
				<div class="row g-3">
					<FormInput :disabled="loading" label="Name" name="rep_name" v-model="player.rep_name" cls="col-md-6"/>

					<FormInput :disabled="loading" label="Mobile" name="rep_mobile" v-model="player.rep_mobile" cls="col-md-6"/>

					<FormInput :disabled="loading" label="Relation" type="text" name="relation" v-model="player.relation" cls="col-md-6"/>

					<!-- <FormInput :disabled="loading"	label="Contract Expiry"	type="date"	name="rep_contract_expiry_date" 	v-model="player.rep_contract_expiry_date"	cls="col-md-6"	/> -->

					<FormInput :disabled="loading" label="Profile Link" name="rep_link" v-model="player.rep_link" cls="col-md-6"/>
				</div>
			</SectionToggle>

			<!-- ── Notes ── -->
			<SectionToggle icon_cls="bi bi-journal-text text-muted" title="Notes" :defaultOpen="false" cls="mt-2">
				<div class="form-group">
					<textarea :disabled="loading" class="form-control" name="notes" v-model="player.notes" rows="4" placeholder="Internal notes about this player…"></textarea>
				</div>
			</SectionToggle>
		</form>
	</div>
	<div class="oc-footer">
		<FormButton cls="btn-primary" form="playerAddUpdate" :disabled="loading" >Save</FormButton>
	</div>
</template>

<style type="text/css">
	/* avatar upload */
    .avatar-upload {
		width: 68px;
		height: 68px;
		border-radius: var(--r-md);
		border: 2px dashed var(--border);
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: center;
		font-size: 10px;
		color: var(--text-muted);
		cursor: pointer;
		transition: border-color .15s, background .15s;
		gap: 4px;
	}
    .avatar-upload:hover { border-color: var(--primary); background: #eff3ff; color: var(--primary); }
    .avatar-upload i { font-size: 20px; }

	.upload-label{
	    margin-top:8px;
	    font-size:13px;
	    font-weight:500;
	}

	.profile-upload-section{
        display:flex;
        flex-direction:column;
        align-items:center;
        margin-bottom:20px;
    }

	.aiff-search-btn{
	    min-width:140px;
	    font-weight:600;
	}

	.btn-success-modified{
	    min-height: 38px;
	}

	@media(max-width:768px){

	    .player-header-card .col-md-2{
	        margin-bottom:15px;
	    }

	}
</style>
