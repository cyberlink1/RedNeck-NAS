// mounts view specific behaviors

document.addEventListener('DOMContentLoaded', function() {
    // prepare create modal by resetting additional fields
    var createMountModal = document.getElementById('createMountModal');
    if (createMountModal) {
        createMountModal.addEventListener('show.bs.modal', function() {
            var form = document.getElementById('mountForm');
            if (form) {
                form.reset();
                form.mount_setuid.checked = false;
                form.mount_setgid.checked = false;                // clear perms checkboxes
                ['perm_own_r','perm_own_w','perm_own_x',
                 'perm_grp_r','perm_grp_w','perm_grp_x',
                 'perm_oth_r','perm_oth_w','perm_oth_x'].forEach(function(n){
                    if (form[n]) form[n].checked = false;
                });            }
        });
    }
    var mountTable = document.getElementById('mountTable');
    if (mountTable) {
        document.querySelectorAll('#mountTable tbody tr').forEach(function(row) {
            row.addEventListener('click', function(e) {
                if (e.target.closest('button') || e.target.closest('form')) {
                    return;
                }
                var dev = row.getAttribute('data-dev');
                var pt  = row.getAttribute('data-pt');
                var opts = row.getAttribute('data-opts') || '';
                var inFstab = row.getAttribute('data-infstab') === '1';
                var form = document.getElementById('editMountForm');
                if (!form) return;
                form.device.value = dev;
                var titleElt = document.getElementById('editMountModalTitle');
                if (titleElt) {
                    titleElt.textContent = 'Edit mount ' + dev;
                }
                form.mount_point.value = pt.replace(/^\/export\//, '');
                // owner/group
                form.mount_owner.value = row.dataset.owner || '';
                form.mount_group.value = row.dataset.group || '';
                // permissions bits
                var perms = row.dataset.perms || '';
                var p = parseInt(perms, 8) || 0;
                form.perm_own_r.checked = !!(p & 0o400);
                form.perm_own_w.checked = !!(p & 0o200);
                form.perm_own_x.checked = !!(p & 0o100);
                form.perm_grp_r.checked = !!(p & 0o040);
                form.perm_grp_w.checked = !!(p & 0o020);
                form.perm_grp_x.checked = !!(p & 0o010);
                form.perm_oth_r.checked = !!(p & 0o004);
                form.perm_oth_w.checked = !!(p & 0o002);
                form.perm_oth_x.checked = !!(p & 0o001);
                form.mount_setuid.checked = row.dataset.setuid === '1';
                form.mount_setgid.checked = row.dataset.setgid === '1';
                form.mount_boot.checked = inFstab;
                form.querySelectorAll('input[name="mount_opts[]"]').forEach(function(cb) {
                    cb.checked = opts.split(',').includes(cb.value);
                });
                new bootstrap.Modal(document.getElementById('editMountModal')).show();
            });
        });
    }

    var mountBtn = document.getElementById('btnMount');
    var mountForm = document.getElementById('mountForm');
    if (mountForm) {
        try {
            var origSubmit = mountForm.submit;
            mountForm.submit = function() {
                origSubmit.call(mountForm);
            };
        } catch(e) {}
    }
    if (mountBtn) {
        mountBtn.addEventListener('click', function(e) {
            e.preventDefault();
            var form = mountBtn.form;
            var dev = '';
            var sub = '';
            if (form) {
                if (form.device_select_mount) {
                    dev = form.device_select_mount.value || '';
                }
                if (form.mount_point) {
                    sub = form.mount_point.value || '';
                }
            }
            var msg = 'Mount the selected logical volume?';
            if (dev) {
                msg = 'Mount ' + dev + '?';
            }
            msg += '\nThis will create or use /export/' + (sub ? sub : '<em>subdir</em>') + '.\nYou may also specify owner/group and permissions for the directory; if omitted the default nobody:nogroup and standard modes are used.';
            showConfirmation(msg, function() {
                if (!mountBtn.form.querySelector('input[name="mount_lv"]')) {
                    var hid = document.createElement('input');
                    hid.type = 'hidden';
                    hid.name = 'mount_lv';
                    hid.value = mountBtn.value || '1';
                    mountBtn.form.appendChild(hid);
                }
                try {
                    mountBtn.form.submit();
                } catch (e) {
                    console.error('exception when calling submit', e);
                }
            });
        });
    }
    var umountBtn = document.getElementById('btnUmount');
    if (umountBtn) {
        umountBtn.addEventListener('click', function(e) {
            e.preventDefault();
            showConfirmation('Unmount the selected export?\nAny users accessing it will be disconnected.', function() {
                umountBtn.form.submit();
            });
        });
    }

    var umountRowButtons = document.querySelectorAll('.btn-umount-row');
    umountRowButtons.forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            showConfirmation('Unmount this export?\nAny users accessing it will be disconnected.', function() {
                if (!btn.form.querySelector('input[name="umount_lv"]')) {
                    var hid = document.createElement('input');
                    hid.type = 'hidden';
                    hid.name = 'umount_lv';
                    hid.value = btn.value || '1';
                    btn.form.appendChild(hid);
                }
                btn.form.submit();
            });
        });
    });
});
