// mounts view specific behaviors

document.addEventListener('DOMContentLoaded', function() {
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
            msg += '\nThis will create or use /export/' + (sub ? sub : '<em>subdir</em>') + '.\nNewly created directories are automatically chown\'ed to nobody:nogroup so they can be exported via NFS.';
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
