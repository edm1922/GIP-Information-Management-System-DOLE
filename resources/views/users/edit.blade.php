{{-- Edit User Modal --}}
<div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserModalLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form class="modal-content border-0 shadow" :action="editUrl" method="POST">
            @csrf
            @method('PUT')
            
            <input type="hidden" name="id" x-model="editData.id">
            
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold" id="editUserModalLabel">
                    <i class="fas fa-user-edit text-brand me-2"></i>Edit User
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <div class="modal-body py-4">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="edit_first_name" class="form-label text-muted fw-semibold" style="font-size: 0.85rem;">First Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control @error('first_name') is-invalid @enderror" id="edit_first_name" name="first_name" x-model="editData.first_name" required>
                        @error('first_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label for="edit_middle_name" class="form-label text-muted fw-semibold" style="font-size: 0.85rem;">Middle Name</label>
                        <input type="text" class="form-control @error('middle_name') is-invalid @enderror" id="edit_middle_name" name="middle_name" x-model="editData.middle_name">
                        @error('middle_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-8 mb-3">
                        <label for="edit_last_name" class="form-label text-muted fw-semibold" style="font-size: 0.85rem;">Last Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control @error('last_name') is-invalid @enderror" id="edit_last_name" name="last_name" x-model="editData.last_name" required>
                        @error('last_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <label for="edit_suffix" class="form-label text-muted fw-semibold" style="font-size: 0.85rem;">Suffix</label>
                        <input type="text" class="form-control @error('suffix') is-invalid @enderror" id="edit_suffix" name="suffix" x-model="editData.suffix">
                        @error('suffix')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                
                <div class="mb-3">
                    <label for="edit_username" class="form-label text-muted fw-semibold" style="font-size: 0.85rem;">Username <span class="text-danger">*</span></label>
                    <input type="text" class="form-control @error('username') is-invalid @enderror" id="edit_username" name="username" x-model="editData.username" required>
                    @error('username')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="mb-3">
                    <label for="edit_role" class="form-label text-muted fw-semibold" style="font-size: 0.85rem;">Role <span class="text-danger">*</span></label>
                    <select class="form-select @error('role') is-invalid @enderror" id="edit_role" name="role" x-model="editData.role" required
                            @change="editData.role = $event.target.value">
                        <option value="admin">Admin</option>
                        <option value="peso_manager">PESO Manager</option>
                    </select>
                    @error('role')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="mb-3">
                    <div class="form-check form-switch p-0 d-flex align-items-center gap-3">
                        <label class="form-check-label fw-semibold text-muted" for="edit_is_active" style="font-size: 0.85rem; margin-bottom: 0;">Account Active</label>
                        <div style="margin-top: -2px;">
                            <input type="hidden" name="is_active" value="0">
                            <input class="form-check-input ms-0" type="checkbox" name="is_active" id="edit_is_active" 
                                   value="1" x-model="editData.is_active" style="width: 2.8em; height: 1.4em; cursor: pointer;">
                        </div>
                    </div>
                </div>

                {{-- Password Reset Notice --}}
                <div class="mt-4 p-3 bg-light rounded-2 border">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <p class="mb-1 fw-bold text-dark" style="font-size: 0.85rem;">Reset User Password</p>
                            <p class="mb-0 text-muted" style="font-size: 0.8rem;">Resets the user's password to a new random temporary password, shown once.</p>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-danger shadow-sm"
                                @click="openConfirmModal(
                                    '{{ url('settings/users') }}/' + editData.id + '/reset-password',
                                    'POST',
                                    'Reset Password',
                                    'Are you sure you want to reset the password for <strong>' + editData.first_name + ' ' + editData.last_name + '</strong> to a new random temporary password?',
                                    'Reset Password',
                                    'danger',
                                    'fa-key'
                                )">
                            <i class="fas fa-undo-alt me-1"></i> Reset
                        </button>
                    </div>
                </div>
                
            </div>
            
            <div class="modal-footer border-top-0 pt-0">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-brand px-4">Save Changes</button>
            </div>
        </form>
    </div>
</div>
